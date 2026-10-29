<?php

namespace App\Support\ThesisDemo;

use App\Models\Employee;
use App\Models\EmployeeMemo;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

// Disciplinary memos already issued (history) and memos left for HR to act on
// live. The memo for E19's latest missed Time Out is NOT made here: the
// app's MemoEngine drafts it (offense 4 -> Notice to Explain) within minutes.
// Memo texts come from the app (EmployeeMemo::draftBody / subjectFor).
class MemoBuilder
{
    public function __construct(private Context $c, private SampleFiles $files, private array $memoDays) {}

    public function build(): void
    {
        $c = $this->c;
        $att = fn (string $code, string $ymd) => $c->m->id("att:{$code}:{$ymd}");
        $md = $this->memoDays;
        $p = fn (int $n) => $c->paidFromEnd($n);

        // E19 no Time Out ladder. memo0 is more than 12 months old: it no
        // longer counts, so memo1 is a 1st offense again.
        $ladder = [
            ['m19-0', 'E19', 'no_time_out', 'verbal_warning', 1, $md['memo0'], 'acknowledged', $att('E19', $md['memo0'])],
            ['m19-1', 'E19', 'no_time_out', 'verbal_warning', 1, $md['memo1'], 'archived', $att('E19', $md['memo1'])],
            ['m19-2', 'E19', 'no_time_out', 'first_written', 2, $md['memo2'], 'acknowledged', $att('E19', $md['memo2'])],
            ['m19-3', 'E19', 'no_time_out', 'final_written', 3, $md['memo3'], 'awaiting_physical', $att('E19', $md['memo3'])],
            // E08 habitual tardiness, sent: E08 acknowledges in the demo
            ['m08', 'E08', 'habitual_tardiness', 'verbal_warning', 1, $c->wd($c->awaiting, 1), 'sent', null],
            // E09 zero tolerance: always a Notice to Explain, investigation open
            ['m09', 'E09', 'product_dumping', 'nte', 1, $c->wd($p(2), 5), 'awaiting_physical', null],
            // E10 draft (HR cancels it in the demo)
            ['m10', 'E10', 'misconduct', 'verbal_warning', 1, $c->wd($c->awaiting, 5), 'draft', null],
            // E07 cancelled memo (wrong employee picked): no longer counts
            ['m07', 'E07', 'insubordination', 'verbal_warning', 1, $c->wd($p(3), 3), 'cancelled', null],
        ];

        foreach ($ladder as [$key, $code, $violation, $level, $offense, $incident, $status, $attendanceId]) {
            $mkey = "memo:{$key}";
            if ($c->m->has($mkey)) {
                continue;
            }
            $employee = Employee::find($c->emp[$code]);
            $made = $c->date($incident)->addDay();
            $made = $made->isWeekend() ? $made->next('Monday') : $made;
            $madeAt = $c->stamp($made->toDateString(), '09:00:00');
            $issuedAt = $status === 'draft' ? null : $c->stamp($made->toDateString(), '10:00:00');
            $body = EmployeeMemo::draftBody($employee, $violation, $level, Carbon::parse($incident), $offense);
            if ($violation === 'habitual_tardiness') {
                $late = DB::table('attendances')->where('employee_id', $employee->id)->where('late_minutes', '>', 0)
                    ->where('date', '<=', $incident)->orderBy('date')->get(['date', 'late_minutes'])
                    ->map(fn ($a) => substr($a->date, 0, 10)." ({$a->late_minutes} min)")->implode(', ');
                $body = str_replace("\n\nThis serves", "\n\nLate Time Ins on record: {$late}.\n\nThis serves", $body);
            }
            $row = [
                'memo_no' => $this->memoNo($made->format('Ymd')),
                'employee_id' => $employee->id,
                'violation' => $violation,
                'category' => EmployeeMemo::categoryOf($violation),
                'level' => $level,
                'offense_no' => $offense,
                'status' => $status,
                'source' => 'manual',
                'subject' => EmployeeMemo::subjectFor($violation, $level),
                'body' => $body,
                'incident_date' => $incident,
                'attendance_id' => $attendanceId,
                'issued_by' => $issuedAt ? $c->hrUserId : null,
                'issued_at' => $issuedAt,
                'acknowledged_at' => in_array($status, ['acknowledged', 'archived'], true) ? $c->stamp($made->addDay()->toDateString(), '08:30:00') : null,
                'signed_at' => $status === 'archived' ? $c->stamp($made->addDays(2)->toDateString(), '14:00:00') : null,
                'cancelled_by' => $status === 'cancelled' ? $c->hrUserId : null,
                'cancelled_at' => $status === 'cancelled' ? $c->stamp($made->toDateString(), '15:00:00') : null,
                'cancel_reason' => $status === 'cancelled' ? 'Issued to the wrong employee; the incident involved another worker.' : null,
                'created_at' => $madeAt,
                'updated_at' => $madeAt,
            ];
            $id = $c->m->insert($mkey, 'employee_memos', $row);

            if ($status === 'archived') {
                $this->file($id, $key, 'signed_copy', "Signed copy of {$row['memo_no']}", $employee);
            }
            if ($key === 'm09') {
                $this->file($id, $key, 'attachment', 'Incident report (sample)', $employee);
                DB::table('employees')->where('id', $employee->id)->update([
                    'investigation_status' => 'pending_investigation',
                    'investigation_since' => $issuedAt,
                ]);
            }
        }

        // memos 0-3 were no Time Out offenses that were not cancelled
        DB::table('employees')->where('id', $c->emp['E19'])->update(['no_timeout_count' => 4]);
    }

    private function file(int $memoId, string $key, string $kind, string $title, Employee $e): void
    {
        $path = $this->files->pdf("memo:{$key}:{$kind}", 'local', 'employee-memos', $title, [
            'Employee: '.$e->first_name.' '.$e->last_name.' ('.$e->employee_id.')',
            $kind === 'signed_copy' ? 'Signed by the employee and HR (sample, no real signature).' : 'Written account of the incident (fictional).',
        ]);
        $this->c->m->insert("memofile:{$key}:{$kind}", 'employee_memo_files', [
            'employee_memo_id' => $memoId,
            'kind' => $kind,
            'path' => $path,
            'original_name' => str_replace(' ', '-', strtolower($title)).'.pdf',
            'mime' => 'application/pdf',
            'size' => (\Illuminate\Support\Facades\Storage::disk('local')->exists($path) ? \Illuminate\Support\Facades\Storage::disk('local')->size($path) : 0),
            'uploaded_by' => $this->c->hrUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // MEMO-YYYYMMDD-### for that day, one above the highest in use.
    private function memoNo(string $ymd): string
    {
        $prefix = "MEMO-{$ymd}-";
        $max = DB::table('employee_memos')->where('memo_no', 'like', $prefix.'%')->pluck('memo_no')
            ->map(fn ($n) => (int) substr($n, -3))->max() ?? 0;

        return $prefix.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }
}
