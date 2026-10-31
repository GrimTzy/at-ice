<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Support\Audit;
use App\Support\PayPeriod;
use App\Support\Settings;
use App\Support\ThesisDemo\AttendanceBuilder;
use App\Support\ThesisDemo\Cleanup;
use App\Support\ThesisDemo\CommerceBuilder;
use App\Support\ThesisDemo\Context;
use App\Support\ThesisDemo\Docs;
use App\Support\ThesisDemo\LeaveBuilder;
use App\Support\ThesisDemo\Manifest;
use App\Support\ThesisDemo\MemoBuilder;
use App\Support\ThesisDemo\OperationsBuilder;
use App\Support\ThesisDemo\PayrollBuilder;
use App\Support\ThesisDemo\PeopleBuilder;
use App\Support\ThesisDemo\Reports;
use App\Support\ThesisDemo\Roster;
use App\Support\ThesisDemo\SampleFiles;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

// Thesis demonstration dataset: fictional, connected records added to an
// existing database without touching real ones. See the handoff files
// (THESIS-DEMO-INSTALL-REPORT.md) for the full procedure.
//
//   preflight  read-only: target, settings, periods, candidates, and a full
//              install inside a transaction that is rolled back (exact counts)
//   install    adds the dataset; reruns skip what is already there
//   verify     consistency checks + payroll reconciliation CSV
//   cleanup    removes only manifest rows (refuses if real records depend on them)
//   refresh    cleanup + install for a new as-of date
//   export     rewrites the accounts and payroll CSVs and the walkthrough and
//              coverage docs with the record numbers of this database
class ThesisDemo extends Command
{
    protected $signature = 'thesis-demo
        {action : preflight|install|verify|cleanup|refresh|export}
        {--as-of= : Demo date (Y-m-d, Asia/Manila); periods are counted from it}
        {--hr= : Email of the EXISTING HR account that seeded HR actions are attributed to}
        {--admin= : Email of the EXISTING Admin account that seeded Admin actions are attributed to}
        {--existing= : Existing employees who get seeded past attendance: EMP codes separated by commas, or "all"}
        {--existing-periods=6 : Ended pay periods of past attendance for existing employees (plus the current period up to yesterday)}
        {--confirm= : The database name, to confirm the target (install, cleanup, refresh)}
        {--i-have-a-backup : Required in production for install, cleanup and refresh}
        {--allow-past-as-of : Allow an as-of date before today}
        {--out= : Folder for the handoff CSVs (default storage/app/private/thesis-demo)}
        {--domain= : Email domain of the fictional logins (default atice.invalid, never delivered)}
        {--no-attendance-label : Do not write the "seeded history" note on attendance rows}
        {--include-derived : cleanup: also remove rows made during the demo on dataset records}
        {--keep-existing-attendance : cleanup: keep the seeded attendance of existing employees}
        {--dry-run : cleanup: show what would be removed}';

    protected $description = 'Install, verify or remove the fictional thesis demonstration dataset';

    public function handle(): int
    {
        $action = $this->argument('action');
        if (! in_array($action, ['preflight', 'install', 'verify', 'cleanup', 'refresh', 'export'], true)) {
            $this->error('Unknown action.');

            return self::FAILURE;
        }
        if (! Schema::hasTable(Manifest::TABLE)) {
            $this->error('Table '.Manifest::TABLE.' is missing: run the migrations first (php artisan migrate).');

            return self::FAILURE;
        }

        try {
            return match ($action) {
                'preflight' => $this->preflight(),
                'install' => $this->install(),
                'verify' => $this->verify(),
                'cleanup' => $this->cleanup(),
                'refresh' => $this->refresh(),
                'export' => $this->export(),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function target(): array
    {
        $db = DB::connection()->getDatabaseName();
        $conn = config('database.default');

        return [
            'environment' => app()->environment(),
            'connection' => $conn,
            'database' => $db,
            'host_fingerprint' => substr(hash('sha256', config("database.connections.{$conn}.host").'|'.$db), 0, 12),
            'app_timezone' => config('app.timezone'),
            'business_timezone' => config('atice.timezone'),
            'today_manila' => now(Context::TZ)->toDateString(),
            'revision' => $this->revision(),
            'last_migration' => DB::table('migrations')->orderByDesc('id')->value('migration'),
            'mail_mailer' => config('mail.default'),
            'broadcast' => config('broadcasting.default'),
        ];
    }

    private function revision(): string
    {
        $head = base_path('../.git/HEAD');
        if (is_file($head)) {
            $ref = trim(file_get_contents($head));
            if (str_starts_with($ref, 'ref: ')) {
                $f = base_path('../.git/'.substr($ref, 5));

                return substr($ref, 16).' @ '.(is_file($f) ? substr(trim(file_get_contents($f)), 0, 7) : '?');
            }

            return substr($ref, 0, 7);
        }

        return env('SOURCE_COMMIT', env('COOLIFY_SOURCE_COMMIT', 'unknown (not a git checkout)'));
    }

    private function demoContext(bool $needDate = true): Context
    {
        $asOf = $this->option('as-of') ?: $this->storedAsOf();
        if (! $asOf || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            throw new \RuntimeException('Give --as-of=YYYY-MM-DD.');
        }
        $today = now(Context::TZ)->toDateString();
        if ($needDate && $asOf > $today) {
            throw new \RuntimeException("--as-of {$asOf} is after today ({$today}): the dataset must end before the demo day.");
        }
        if ($needDate && $asOf < $today && ! $this->option('allow-past-as-of') && $this->argument('action') !== 'verify' && $this->argument('action') !== 'export') {
            throw new \RuntimeException("--as-of {$asOf} is before today ({$today}). Use today's date, or add --allow-past-as-of (then days from the as-of date to today have no attendance and count as absences).");
        }
        $hr = $this->account('hr', 'hr');
        $admin = $this->account('admin', 'admin');

        return new Context($asOf, $hr, $admin, $this->existingIds(), (int) $this->option('existing-periods'),
            6, $this->option('domain') ?: $this->storedDomain(), ! $this->option('no-attendance-label'));
    }

    private function account(string $opt, string $role): int
    {
        $email = $this->option($opt) ?: $this->stored("{$opt}_email");
        if (! $email) {
            throw new \RuntimeException("Give --{$opt}=<email of your existing {$role} account>.");
        }
        $u = User::where('email', $email)->first();
        if (! $u || $u->role !== $role) {
            throw new \RuntimeException("--{$opt}: no {$role} account with that email.");
        }
        if (in_array($u->id, (new Manifest(Context::DATASET))->ids('users'), true)) {
            throw new \RuntimeException("--{$opt}: that account belongs to the dataset; use a real one.");
        }

        return $u->id;
    }

    private function existingIds(): array
    {
        $opt = $this->option('existing') ?? $this->stored('existing');
        if (! $opt) {
            return [];
        }
        $demo = (new Manifest(Context::DATASET))->ids('employees');
        $q = Employee::where('is_active', true)->whereNotIn('id', $demo ?: [0]);
        if ($opt !== 'all') {
            $codes = array_filter(array_map('trim', explode(',', $opt)));
            $found = (clone $q)->whereIn('employee_id', $codes)->pluck('employee_id')->all();
            if ($missing = array_diff($codes, $found)) {
                throw new \RuntimeException('--existing: not found or inactive: '.implode(', ', $missing));
            }
            $q->whereIn('employee_id', $codes);
        }

        return $q->orderBy('employee_id')->pluck('id')->all();
    }

    // options of the last install, kept so verify/export/cleanup need fewer flags
    private function stateFile(): string
    {
        return storage_path('app/private/thesis-demo/install-options.json');
    }

    private function stored(string $k): ?string
    {
        $f = $this->stateFile();
        $data = is_file($f) ? json_decode(file_get_contents($f), true) : [];

        return $data[$k] ?? null;
    }

    private function storedAsOf(): ?string
    {
        return $this->stored('as_of');
    }

    private function storedDomain(): ?string
    {
        return $this->stored('domain');
    }

    private function outDir(): string
    {
        return $this->option('out') ?: storage_path('app/private/thesis-demo');
    }

    private function guardWrite(): void
    {
        $db = DB::connection()->getDatabaseName();
        if ($this->option('confirm') !== $db) {
            throw new \RuntimeException("Add --confirm={$db} to confirm the target database.");
        }
        if (app()->environment('production') && ! $this->option('i-have-a-backup')) {
            throw new \RuntimeException('Production: back up the database and storage first, then add --i-have-a-backup.');
        }
    }

    private function preflight(): int
    {
        $this->info('TARGET');
        foreach ($this->target() as $k => $v) {
            $this->line(str_pad($k, 20).$v);
        }
        $c = $this->demoContext();
        $this->info("\nPAY PERIODS (as of {$c->asOf->toDateString()})");
        foreach ($c->ended as $p) {
            $tag = $p === $c->awaiting ? 'AWAITING HR APPROVAL' : 'paid history';
            $this->line("  {$p['start']} to {$p['end']}  pay {$p['pay_day']} (#".PayPeriod::payDayNumber($p['pay_day'])." of month)  {$tag}");
        }
        $this->line("  {$c->current['start']} to {$c->current['end']}  pay {$c->current['pay_day']}  CURRENT (attendance up to yesterday)");

        $this->info("\nEFFECTIVE SETTINGS (stored overrides marked *)");
        $stored = DB::table('settings')->pluck('key')->flip();
        foreach (Settings::all() as $s) {
            if (str_starts_with($s['key'], 'payroll.') || str_starts_with($s['key'], 'attendance.') || str_starts_with($s['key'], 'leave.') || str_starts_with($s['key'], 'memo.') || str_starts_with($s['key'], 'delivery.') || str_starts_with($s['key'], 'support.')) {
                $this->line('  '.(isset($stored[$s['key']]) ? '*' : ' ').str_pad($s['key'], 40).json_encode($s['value']));
            }
        }
        $this->info("\nCONTRIBUTION RATES / PAY MULTIPLIERS");
        foreach (DB::table('contribution_rates')->orderBy('type')->orderBy('effective_from')->get() as $r) {
            $this->line("  {$r->type} from {$r->effective_from}: {$r->rules}");
        }
        foreach (DB::table('pay_multipliers')->orderBy('key')->orderBy('effective_date')->get() as $r) {
            $this->line("  {$r->key} from {$r->effective_date}: {$r->value}");
        }
        $this->info("\nHOLIDAYS IN THE WINDOW (never changed by the installer)");
        foreach ($c->holidays as $d => $h) {
            if ($d >= $c->ended[0]['start'] && $d <= $c->current['end']) {
                $this->line("  {$d} {$h->type} {$h->name}".(in_array(CarbonImmutable::parse($d)->dayOfWeekIso, $c->workDays, true) ? '' : ' (rest day)'));
            }
        }
        $this->info("\nREFERENCE PRICES (read only)");
        foreach (DB::table('reference_prices')->get() as $r) {
            $this->line("  {$r->customer_type} {$r->product_type}: ".($r->unit_price ?? 'none'));
        }

        $this->info("\nEXISTING EMPLOYEES (candidates for --existing; profiles are never changed)");
        $ids = $c->existingEmployeeIds;
        foreach (Employee::where('is_active', true)->whereNotIn('id', $c->m->ids('employees') ?: [0])->orderBy('employee_id')->get() as $e) {
            $face = DB::table('face_data')->where('employee_id', $e->id)->exists() ? 'face enrolled' : 'NO FACE';
            $rate = $e->pay_basis === 'monthly' ? "monthly {$e->monthly_rate}" : "daily {$e->daily_rate}";
            $warn = ((float) $e->daily_rate <= 0 && ! $e->monthly_rate) ? '  <- no pay rate: payroll will be 0' : '';
            $this->line('  '.(in_array($e->id, $ids, true) ? '[x] ' : '[ ] ')."{$e->employee_id} {$e->first_name} {$e->last_name} ({$e->position}, {$e->shift} ".substr((string) $e->shift_start, 0, 5).'-'.substr((string) $e->shift_end, 0, 5).", hired {$e->date_hired->toDateString()}, {$rate}, {$face}){$warn}");
        }

        $this->info("\nCOLLISIONS");
        $problems = $this->collisions($c);
        $this->line($problems ? '  '.implode("\n  ", $problems) : '  none');

        $this->info("\nDRY RUN (full install inside a transaction, rolled back)");
        $already = $c->m->total();
        DB::beginTransaction();
        try {
            $this->build($c, true);
            $counts = $c->m->counts();
            DB::rollBack();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        foreach ($counts as $r) {
            $this->line('  '.str_pad($r['table_name'], 32).$r['c']);
        }
        $this->line('  total rows '.array_sum(array_column($counts, 'c')).($already ? " (already installed: {$already})" : ''));
        foreach ($c->log as $l) {
            $this->line('  '.$l);
        }
        $this->info("\nNothing was written.");

        return $problems ? self::FAILURE : self::SUCCESS;
    }

    private function collisions(Context $c): array
    {
        $out = [];
        $mine = $c->m->ids('users');
        foreach (Roster::people() as $code => $p) {
            if (! Roster::hasLogin($p[4])) {
                continue;
            }
            // email pattern last.NNNN@domain: only the number changes, so check the name part
            $like = preg_replace('/[^a-z]/', '', strtolower($p[2])).'.%@'.$c->domain;
            $hit = DB::table('users')->where('email', 'like', $like)->whereNotIn('id', $mine ?: [0])->pluck('email')->all();
            if ($hit) {
                $out[] = "{$code}: a non-dataset login already uses {$like} (".implode(', ', $hit).'): numbers differ, so no clash, but check it is not a person.';
            }
        }
        foreach (CommerceBuilder::customers() as $k => $def) {
            if ($def[4] && DB::table('users')->where('email', "{$def[4]}@{$c->domain}")->whereNotIn('id', $mine ?: [0])->exists()) {
                $out[] = "{$k}: {$def[4]}@{$c->domain} is already a non-dataset login. Use --domain.";
            }
        }

        return array_values(array_filter($out, fn ($l) => ! str_contains($l, 'no clash')));
    }

    // Builds everything; each module is its own transaction (resumable).
    private function build(Context $c, bool $dry = false): array
    {
        $files = new SampleFiles($c, $dry);
        $people = new PeopleBuilder($c);
        $dates = $people->dates();
        $leave = new LeaveBuilder($c, $files);
        $report = [];
        $step = function (string $name, callable $fn) use ($dry) {
            $dry ? $fn() : DB::transaction($fn);
            $this->line("  done: {$name}");
        };
        $step('employees and logins', fn () => $people->build());
        $step('leave requests', fn () => $leave->build());
        $att = new AttendanceBuilder($c, $leave->leaveDays(), $dates);
        $step('attendance (fictional)', fn () => $att->build());
        $step('attendance (existing employees)', function () use ($att, &$report) {
            $report = $att->buildExisting();
        });
        $step('payroll history', fn () => (new PayrollBuilder($c, $dates))->build());
        $step('memos', fn () => (new MemoBuilder($c, $files, $att->memoDays()))->build());
        $step('customers, deliveries, invoices, tickets', fn () => (new CommerceBuilder($c, $files))->build());
        $step('inventory, projects, HR records, expenses', fn () => (new OperationsBuilder($c, $files))->build());
        foreach ($report as $l) {
            $c->note($l);
        }

        return $report;
    }

    private function install(): int
    {
        $this->guardWrite();
        $c = $this->demoContext();
        if ($p = $this->collisions($c)) {
            throw new \RuntimeException("Collisions:\n".implode("\n", $p));
        }
        $this->info('Installing dataset '.Context::DATASET." into {$this->target()['database']} as of {$c->asOf->toDateString()}");
        $before = $c->m->total();
        $this->build($c);
        $after = $c->m->total();
        @mkdir(dirname($this->stateFile()), 0700, true);
        file_put_contents($this->stateFile(), json_encode([
            'as_of' => $c->asOf->toDateString(), 'hr_email' => $this->option('hr'), 'admin_email' => $this->option('admin'),
            'existing' => $this->option('existing'), 'domain' => $c->domain, 'installed_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));
        Audit::log('thesis_demo.installed', null, null, ['dataset' => Context::DATASET, 'as_of' => $c->asOf->toDateString(), 'rows_added' => $after - $before, 'rows_total' => $after]);
        $acc = (new Reports($c, $this->outDir()))->accounts();
        $this->writeManifest($c);
        foreach ($c->log as $l) {
            $this->line('  '.$l);
        }
        $this->info('Rows added this run: '.($after - $before)." (dataset total {$after}).");
        $this->line("Accounts CSV: {$acc}");
        $this->line('Next: php artisan thesis-demo verify');

        return self::SUCCESS;
    }

    private function writeManifest(Context $c): void
    {
        $dir = $this->outDir();
        @mkdir($dir, 0700, true);
        $data = ['dataset' => Context::DATASET, 'target' => $this->target(), 'as_of' => $c->asOf->toDateString(),
            'periods' => ['paid' => $c->paid, 'awaiting' => $c->awaiting, 'current' => $c->current], 'counts' => $c->m->counts(), 'ids' => []];
        foreach (DB::table(Manifest::TABLE)->where('dataset', Context::DATASET)->whereNotNull('record_id')->select('table_name')->distinct()->pluck('table_name') as $t) {
            $data['ids'][$t] = $c->m->ids($t);
        }
        $data['files'] = $c->m->files();
        file_put_contents($dir.DIRECTORY_SEPARATOR.'install-manifest.json', json_encode($data, JSON_PRETTY_PRINT));
    }

    private function verify(): int
    {
        $c = $this->demoContext(false);
        $rep = new Reports($c, $this->outDir());
        $this->info('CHECKS');
        $lines = $rep->checks();
        foreach ($lines as $l) {
            $this->line('  '.$l);
        }
        [$file, $n, $mismatch] = $rep->payroll();
        $this->info("\nPAYROLL RECONCILIATION: {$n} rows -> {$file}");
        $this->line($mismatch ? '  '.implode("\n  ", $mismatch) : '  every stored payroll matches the independent calculation');
        $scan = (new Cleanup($c->m))->scan();
        $this->info("\nROWS MADE ON DATASET RECORDS DURING THE DEMO (derived)");
        $this->line($scan['derived'] ? collect($scan['derived'])->map(fn ($ids, $t) => "  {$t}: ".count($ids))->implode("\n") : '  none');
        $this->info('REAL RECORDS TIED TO DATASET RECORDS (dispatcher checklist)');
        $this->line($scan['blockers'] ? collect($scan['blockers'])->map(fn ($ids, $t) => "  {$t}: ids ".implode(',', array_slice($ids, 0, 30)))->implode("\n") : '  none');
        $this->writeManifest($c);
        (new Docs($c, $this->outDir()))->write($this->target());
        file_put_contents($this->outDir().DIRECTORY_SEPARATOR.'verify-report.txt', implode("\n", array_merge(['verified '.now()->toIso8601String()], $lines,
            ['', "payroll rows {$n}"], $mismatch, ['', 'derived: '.json_encode(array_map('count', $scan['derived'])), 'blockers: '.json_encode(array_map('count', $scan['blockers']))])));

        return collect($lines)->contains(fn ($l) => str_starts_with($l, 'FAIL')) || collect($mismatch)->contains(fn ($l) => str_contains($l, 'MISMATCH') || str_contains($l, 'MISSING')) ? self::FAILURE : self::SUCCESS;
    }

    private function cleanup(): int
    {
        if (! $this->option('dry-run')) {
            $this->guardWrite();
        }
        $m = new Manifest(Context::DATASET);
        $done = (new Cleanup($m))->run((bool) $this->option('include-derived'), (bool) $this->option('keep-existing-attendance'), (bool) $this->option('dry-run'));
        foreach ($done as $t => $n) {
            $this->line('  '.str_pad($t, 40).$n);
        }
        if (! $this->option('dry-run')) {
            Audit::log('thesis_demo.removed', null, null, ['dataset' => Context::DATASET, 'deleted' => $done]);
            $this->info('Dataset removed. Remaining manifest rows: '.$m->total());
        }

        return self::SUCCESS;
    }

    private function refresh(): int
    {
        $this->guardWrite();
        if (! $this->option('as-of')) {
            throw new \RuntimeException('refresh needs the new --as-of date.');
        }
        $m = new Manifest(Context::DATASET);
        $this->info('Removing the current dataset');
        $done = (new Cleanup($m))->run((bool) $this->option('include-derived'), (bool) $this->option('keep-existing-attendance'));
        Audit::log('thesis_demo.removed', null, null, ['dataset' => Context::DATASET, 'deleted' => $done, 'by' => 'refresh']);
        // only now: a refused cleanup leaves the current accounts CSV in place
        $old = $this->accountsBackup();
        $this->info('Installing for '.$this->option('as-of'));
        $code = $this->install();
        if ($old) {
            $this->line("Previous accounts CSV kept as {$old} (old logins no longer exist).");
        }

        return $code;
    }

    private function accountsBackup(): ?string
    {
        $f = $this->outDir().DIRECTORY_SEPARATOR.'THESIS-DEMO-ACCOUNTS.csv';
        if (! is_file($f)) {
            return null;
        }
        $to = $this->outDir().DIRECTORY_SEPARATOR.'THESIS-DEMO-ACCOUNTS.previous-'.date('Ymd-His').'.csv';
        rename($f, $to);

        return $to;
    }

    private function export(): int
    {
        $c = $this->demoContext(false);
        $rep = new Reports($c, $this->outDir());
        $this->line('Accounts: '.$rep->accounts());
        [$file, $n] = $rep->payroll();
        $this->line("Payroll: {$file} ({$n} rows)");
        $this->writeManifest($c);
        foreach ((new Docs($c, $this->outDir()))->write($this->target()) as $doc) {
            $this->line("Doc: {$doc}");
        }

        return self::SUCCESS;
    }
}
