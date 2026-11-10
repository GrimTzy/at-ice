<?php

namespace App\Http\Controllers;

use App\Models\PayMultiplier;
use App\Models\Payroll;
use App\Support\Audit;
use App\Support\AuditLabels;
use App\Support\PayPeriod;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Rates and rules (App\Support\Settings) and pay rates: HR only (moved from
// Admin, owner 2026-11-09). The audit log: Admin only.
class SettingsController extends Controller
{
    // HR: every setting except the ones kept with Admin
    public function index()
    {
        return response()->json(array_values(array_filter(Settings::all(), fn ($s) => ! Settings::isAdminKey($s['key']))));
    }

    // GET settings/{page} (HR): one Rates and rules page, only its own keys
    public function page(string $page)
    {
        $this->assertPage($page, false);

        return response()->json(Settings::page($page));
    }

    // PUT settings/{page} { "key": "...", "value": ... } (HR): a key of that page only
    public function updatePage(Request $request, string $page)
    {
        return $this->savePage($request, $page, false);
    }

    // GET admin-settings/{page} (Admin): Deliveries or Support tickets
    public function adminPage(string $page)
    {
        $this->assertPage($page, true);

        return response()->json(Settings::page($page));
    }

    // PUT admin-settings/{page} (Admin)
    public function adminUpdatePage(Request $request, string $page)
    {
        return $this->savePage($request, $page, true);
    }

    // The page exists and belongs to this side (Admin pages: Settings::ADMIN_PAGES)
    private function assertPage(string $page, bool $admin): void
    {
        abort_unless(array_key_exists($page, Settings::PAGES) && in_array($page, Settings::ADMIN_PAGES, true) === $admin, 404);
    }

    private function savePage(Request $request, string $page, bool $admin)
    {
        $this->assertPage($page, $admin);
        if (! in_array($request->input('key'), array_column(Settings::page($page), 'key'), true)) {
            return response()->json(['message' => 'This setting is not on this page.'], 422);
        }

        return $this->save($request, $admin);
    }

    // PUT settings { "key": "...", "value": ... } (HR)
    public function update(Request $request)
    {
        return $this->save($request, false);
    }

    // Admin: only the settings kept with Admin (Settings::ADMIN_KEYS)
    public function adminIndex()
    {
        return response()->json(array_values(array_filter(Settings::all(), fn ($s) => in_array($s['key'], Settings::ADMIN_KEYS, true))));
    }

    // PUT admin-settings { "key": "...", "value": ... } (Admin)
    public function adminUpdate(Request $request)
    {
        return $this->save($request, true);
    }

    private function save(Request $request, bool $admin)
    {
        $request->validate(['key' => 'required|string', 'value' => 'present']);
        $key = $request->input('key');
        if (Settings::isAdminKey($key) !== $admin) {
            return response()->json(['message' => $admin
                ? 'This setting is in HR > Rates and rules.'
                : 'Only Admin can change this setting.'], 403);
        }
        if ($error = Settings::validate($key, $request->input('value'))) {
            throw ValidationException::withMessages(['value' => [$error]]);
        }
        // These two decide where every pay period starts and ends. Changing them after a
        // payroll exists would re-slice the periods, and a new period could overlap a
        // payroll that was already made (the same days paid twice).
        if (in_array($key, ['payroll.anchor', 'payroll.cutoff_days'], true)
            && (string) Settings::cast($key, $request->input('value')) !== (string) Settings::get($key)
            && Payroll::exists()) {
            throw ValidationException::withMessages(['value' => [
                'The pay period cannot change because payrolls already exist. Changing it would move every period and could pay the same days twice. Ask the developer to plan the change.',
            ]]);
        }
        Settings::set($key, Settings::cast($key, $request->input('value')), $request->user()->id);

        return response()->json(['key' => $key, 'value' => Settings::get($key)]);
    }

    // GET /api/pay-multipliers (HR): each holiday/overtime rate in effect
    // today, with its DOLE note and the history of changes.
    public function multipliers()
    {
        $rows = PayMultiplier::orderBy('key')->orderByDesc('effective_date')->get()->groupBy('key');
        $today = now(config('atice.timezone'))->toDateString();

        return response()->json(collect(PayMultiplier::KEYS)->map(fn ($key) => [
            'key' => $key,
            'value' => PayMultiplier::rate($key, $today),
            'history' => ($rows[$key] ?? collect())->map(fn ($r) => $r->only(['value', 'effective_date', 'note']))->values(),
        ]));
    }

    // POST /api/pay-multipliers { key, value, effective_date } (HR): a new
    // rate from a date; older payrolls keep the rate of their day.
    public function setMultiplier(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', \Illuminate\Validation\Rule::in(PayMultiplier::KEYS)],
            'value' => 'required|numeric|min:0|max:10',
            'effective_date' => 'required|date_format:Y-m-d',
        ]);
        $before = PayMultiplier::rate($data['key'], $data['effective_date']);
        PayMultiplier::updateOrCreate(
            ['key' => $data['key'], 'effective_date' => $data['effective_date']],
            ['value' => $data['value'], 'created_by' => $request->user()->id, 'note' => PayMultiplier::where('key', $data['key'])->orderBy('effective_date')->value('note')]
        );
        Audit::log('pay_multiplier.changed', null, ['key' => $data['key'], 'value' => $before], $data);

        return response()->json(['message' => "{$data['key']} is {$data['value']} from {$data['effective_date']}."]);
    }

    // GET pay-period?date=Y-m-d : the cutoff containing a date (any role that
    // works with attendance or payroll; read-only start and end).
    public function payPeriod(Request $request)
    {
        $request->validate(['date' => 'required|date_format:Y-m-d']);

        return response()->json(PayPeriod::for($request->input('date')));
    }

    // GET audit-logs?action=&subject_type=&subject_id= : newest first.
    public function auditLogs(Request $request)
    {
        $logs = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->input('action')))
            ->when($request->filled('subject_type'), fn ($q) => $q->where('subject_type', $request->input('subject_type')))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->input('subject_id')))
            ->orderByDesc('audit_logs.id')
            ->limit(200)
            ->get(['audit_logs.*', 'users.name as user_name', 'users.role as user_role_key', 'users.sub_role as user_sub_role']);

        // Who did it (name and role), what in plain words, and to what
        $subjects = AuditLabels::subjects($logs);

        return response()->json($logs->map(function ($l) use ($subjects) {
            $l->before = json_decode($l->before ?? 'null', true);
            $l->after = json_decode($l->after ?? 'null', true);
            $l->user_role = AuditLabels::role($l->user_role_key, $l->user_sub_role);
            $l->action_label = AuditLabels::action($l->action);
            $l->subject_label = $l->subject_type ? ($subjects[$l->subject_type.':'.$l->subject_id] ?? null) : null;
            unset($l->user_role_key, $l->user_sub_role);

            return $l;
        }));
    }
}
