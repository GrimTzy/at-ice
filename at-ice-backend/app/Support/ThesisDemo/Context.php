<?php

namespace App\Support\ThesisDemo;

use App\Models\Holiday;
use App\Support\PayPeriod;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use RuntimeException;

// Everything the builders share: the as-of date, the pay periods around it,
// the effective settings, the existing HR and Admin accounts that seeded
// actions are attributed to, and a stateless deterministic "random" so a
// rerun produces the same values.
class Context
{
    public const DATASET = 'thesis-2026';

    public const TZ = 'Asia/Manila';

    public CarbonImmutable $asOf;

    /** ended periods, oldest first, each ['start','end','pay_day'] */
    public array $ended = [];

    /** ended periods that get paid payroll history (all but the last) */
    public array $paid = [];

    /** the last ended period: left for HR to approve live */
    public array $awaiting;

    public array $current;

    /** existing employees' periods (the last N ended + current) */
    public array $existingPeriods = [];

    public array $workDays;

    public array $holidays; // Y-m-d => Holiday

    public string $dayStart;

    public string $dayEnd;

    public string $nightStart;

    public string $nightEnd;

    public string $domain;

    public array $log = [];

    public Manifest $m;

    /** code => employees.id (fictional), filled by PeopleBuilder */
    public array $emp = [];

    /** code => users.id (fictional), filled by PeopleBuilder */
    public array $user = [];

    /** customer key => customers.id */
    public array $cust = [];

    /** generated credentials: code => [email, password] (new this run only) */
    public array $credentials = [];

    public function __construct(
        string $asOf,
        public readonly int $hrUserId,
        public readonly int $adminUserId,
        public readonly array $existingEmployeeIds = [],
        int $existingPeriodCount = 6,
        int $historyMonths = 6,
        ?string $domain = null,
        public readonly bool $labelAttendance = true,
    ) {
        $this->asOf = CarbonImmutable::createFromFormat('!Y-m-d', $asOf, self::TZ);
        $this->m = new Manifest(self::DATASET);
        $this->domain = $domain ?: 'atice.invalid';
        $this->workDays = array_map('intval', explode(',', (string) Settings::get('attendance.work_days')));
        $this->dayStart = Settings::get('attendance.shift_start').':00';
        $this->dayEnd = Settings::get('attendance.shift_end').':00';
        $this->nightStart = Settings::get('attendance.night_shift_start').':00';
        $this->nightEnd = Settings::get('attendance.night_shift_end').':00';
        $this->holidays = Holiday::byDate();

        $this->current = PayPeriod::for($this->asOf->toDateString());
        $historyStart = $this->asOf->startOfMonth()->subMonths($historyMonths)->toDateString();
        $p = PayPeriod::previous($this->asOf->toDateString());
        $list = [];
        while ($p['start'] >= $historyStart) {
            array_unshift($list, $p);
            $p = PayPeriod::previous($p['start']);
        }
        if (count($list) < 4) {
            throw new RuntimeException('Not enough ended pay periods before the as-of date.');
        }
        $this->ended = $list;
        $this->awaiting = end($list);
        $this->paid = array_slice($list, 0, -1);
        $this->existingPeriods = array_slice($list, -$existingPeriodCount);
        $this->loadFromManifest();
    }

    // ids of what an earlier install made (verify, export, reruns)
    public function loadFromManifest(): void
    {
        foreach (array_keys(Roster::people()) as $code) {
            if ($id = $this->m->id("employee:{$code}")) {
                $this->emp[$code] = $id;
                $this->user[$code] = \Illuminate\Support\Facades\DB::table('employees')->where('id', $id)->value('user_id');
            }
        }
        foreach (array_keys(CommerceBuilder::customers()) as $k) {
            if ($id = $this->m->id("customer:{$k}")) {
                $this->cust[$k] = $id;
            }
        }
    }

    public function date(string $ymd): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10), self::TZ);
    }

    public function isWorkDay(string $ymd): bool
    {
        return in_array($this->date($ymd)->dayOfWeekIso, $this->workDays, true);
    }

    public function isHoliday(string $ymd): bool
    {
        return isset($this->holidays[$ymd]);
    }

    // Work days (not holidays) of a period, in order, as Y-m-d.
    public function workDaysOf(array $period, bool $skipHolidays = true): array
    {
        $out = [];
        for ($d = $this->date($period['start']); $d->toDateString() <= $period['end']; $d = $d->addDay()) {
            $ymd = $d->toDateString();
            if ($this->isWorkDay($ymd) && (! $skipHolidays || ! $this->isHoliday($ymd))) {
                $out[] = $ymd;
            }
        }

        return $out;
    }

    // The nth (1-based) work day of a period, or the last one.
    public function wd(array $period, int $n): string
    {
        $days = $this->workDaysOf($period);

        return $days[min($n, count($days)) - 1];
    }

    // Paid period counted from the end: 1 = the one just before the awaiting one.
    public function paidFromEnd(int $n): array
    {
        return $this->paid[count($this->paid) - $n];
    }

    // Deterministic number in [min, max] for a key.
    public static function pick(string $key, int $min, int $max): int
    {
        return $min + (crc32($key) % ($max - $min + 1));
    }

    public function stamp(string $ymd, string $time): string
    {
        return CarbonImmutable::parse("{$ymd} {$time}", self::TZ)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    public function note(string $line): void
    {
        $this->log[] = $line;
    }
}
