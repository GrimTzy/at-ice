<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeMemo;
use App\Models\EmployeeMemoFile;
use App\Models\LiveEvent;
use App\Models\MemoRecipient;
use App\Support\Audit;
use App\Support\DriverAvailability;
use App\Support\MemoEngine;
use App\Support\StoredFiles;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// Disciplinary memos. HR (and Admin): reviews the automatic drafts for missed
// Time Outs, issues memos by hand (zero-tolerance offenses go straight to a
// Notice to Explain), attaches files, sends them to the employee's account,
// uploads the scanned wet-signed copy, and closes an investigation. The
// employee: reads their sent memos and acknowledges a verbal or first written
// warning. Every HR action is audited.
class EmployeeMemoController extends Controller
{
    private const EMPLOYEE_COLUMNS = 'employee:id,employee_id,first_name,middle_initial,last_name,suffix,position,user_id,no_timeout_count,investigation_status,deactivated_at';

    // GET /api/memos (HR): every memo, the employees to pick from, and the lists
    public function index()
    {
        MemoEngine::run(); // pick up missed Time Outs right away when HR opens the page

        return response()->json([
            'memos' => EmployeeMemo::with([self::EMPLOYEE_COLUMNS, 'files', 'issuer:id,name', 'approver:id,name', 'returner:id,name'])
                ->withCount(['recipients', 'recipients as acknowledged_count' => fn ($q) => $q->whereNotNull('acknowledged_at')])
                ->latest()->limit(1000)->get(),
            'groups' => collect(EmployeeMemo::GROUPS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'employees' => Employee::whereNull('deactivated_at')->orderBy('employee_id')
                ->get(['id', 'employee_id', 'first_name', 'middle_initial', 'last_name', 'suffix', 'position', 'user_id', 'no_timeout_count', 'investigation_status', 'investigation_since']),
            'violations' => collect(EmployeeMemo::VIOLATIONS)->map(fn ($v, $k) => ['key' => $k, 'label' => $v[0], 'category' => $v[1]])->values(),
            'levels' => collect(EmployeeMemo::LEVELS)->map(fn ($v, $k) => ['key' => $k, 'label' => $v])->values(),
        ]);
    }

    // GET /api/memos/draft-text?employee_id=&violation=&level=&incident_date= (HR):
    // the suggested level and letter for the Issue Memo form
    public function draftText(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'violation' => ['required', Rule::in(array_keys(EmployeeMemo::VIOLATIONS))],
            'level' => ['nullable', Rule::in(array_keys(EmployeeMemo::LEVELS))],
            'incident_date' => 'nullable|date',
        ]);
        $employee = Employee::findOrFail($data['employee_id']);
        $offense = EmployeeMemo::nextOffenseNo($employee->id, $data['violation']);
        $level = $this->levelFor($data['violation'], $data['level'] ?? null, $offense);
        $incident = Carbon::parse($data['incident_date'] ?? now(config('atice.timezone'))->toDateString());

        return response()->json([
            'offense_no' => $offense,
            'level' => $level,
            'subject' => EmployeeMemo::subjectFor($data['violation'], $level),
            'body' => EmployeeMemo::draftBody($employee, $data['violation'], $level, $incident, $offense),
        ]);
    }

    // POST /api/memos (HR, multipart): a memo issued by hand, saved as a draft
    // or sent at once, with any dropped files
    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->whereNull('deactivated_at')],
            'violation' => ['required', Rule::in(array_keys(EmployeeMemo::VIOLATIONS))],
            'level' => ['nullable', Rule::in(array_keys(EmployeeMemo::LEVELS))],
            'incident_date' => 'required|date|before_or_equal:'.DriverAvailability::today(),
            'subject' => 'required|string|max:255',
            'body' => 'required|string|min:20|max:10000',
            'send' => 'boolean',
            'endorse' => 'boolean',
            'files' => 'array|max:10',
            'files.*' => 'file|max:10240|mimes:'.EmployeeMemoFile::MIMES,
        ], ['files.*.mimes' => 'Files must be PDF, Word (.docx) or pictures (JPG, PNG, WebP).']);

        $offense = EmployeeMemo::nextOffenseNo($data['employee_id'], $data['violation']);
        // A formal memo is never sent straight away: it goes to Admin first.
        $formal = in_array($this->levelFor($data['violation'], $data['level'] ?? null, $offense), EmployeeMemo::FORMAL_LEVELS, true)
            || EmployeeMemo::categoryOf($data['violation']) === 'zero_tolerance';
        if ($formal && $request->boolean('send')) {
            return response()->json([
                'message' => self::NEEDS_APPROVAL,
                'errors' => ['send' => [self::NEEDS_APPROVAL]],
            ], 422);
        }
        $stored = new StoredFiles('local');
        try {
            $memo = DB::transaction(function () use ($request, $data, $offense, $stored) {
                $memo = EmployeeMemo::create([
                    'memo_no' => EmployeeMemo::nextMemoNo(),
                    'employee_id' => $data['employee_id'],
                    'violation' => $data['violation'],
                    'category' => EmployeeMemo::categoryOf($data['violation']),
                    'level' => $this->levelFor($data['violation'], $data['level'] ?? null, $offense),
                    'offense_no' => $offense,
                    'status' => 'draft',
                    'source' => 'manual',
                    'subject' => trim($data['subject']),
                    'body' => trim($data['body']),
                    'incident_date' => $data['incident_date'],
                ]);
                $this->storeFiles($request, $memo, 'attachment', $stored);

                return $memo;
            });
        } catch (\Throwable $e) {
            $stored->discard(); // the transaction undid the records, not the files
            throw $e;
        }
        Audit::log('memo.create', $memo, null, $memo->only(['memo_no', 'employee_id', 'violation', 'level']));

        if ($request->boolean('send')) {
            $this->dispatch($request, $memo);
        } elseif ($formal && $request->boolean('endorse')) {
            $this->markEndorsed($request, $memo);
        }

        return response()->json($this->fresh($memo), 201);
    }

    private const NEEDS_APPROVAL = 'This is a formal memo (Final Written Warning, Notice to Explain or zero tolerance). Endorse it to Admin; it can be released once Admin approves it.';

    // POST /api/memos/announcements (HR): a policy notice to picked employees, a
    // role group or every active employee. Saved as a draft or released at once;
    // a group is turned into names only when it is released.
    public function storeAnnouncement(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'body' => 'required|string|min:20|max:10000',
            'mode' => ['required', Rule::in(['employees', 'group', 'all'])],
            'employee_ids' => 'required_if:mode,employees|array|max:500',
            'employee_ids.*' => ['integer', Rule::exists('employees', 'id')->where('is_active', true)->whereNull('deactivated_at')],
            'group' => ['required_if:mode,group', 'nullable', Rule::in(array_keys(EmployeeMemo::GROUPS))],
            'send' => 'boolean',
        ], [
            'employee_ids.required_if' => 'Pick at least one employee.',
            'employee_ids.*.exists' => 'Only active employees can receive an announcement.',
            'group.required_if' => 'Pick a group.',
        ]);

        $audience = ['mode' => $data['mode']]
            + ($data['mode'] === 'employees' ? ['employee_ids' => array_values(array_unique(array_map('intval', $data['employee_ids'])))] : [])
            + ($data['mode'] === 'group' ? ['group' => $data['group']] : []);

        $memo = EmployeeMemo::create([
            'kind' => 'announcement',
            'audience' => $audience,
            'memo_no' => EmployeeMemo::nextMemoNo(),
            'employee_id' => null,
            'violation' => 'announcement',
            'category' => 'announcement',
            'level' => 'announcement',
            'status' => 'draft',
            'source' => 'manual',
            'subject' => trim($data['subject']),
            'body' => trim($data['body']),
            'incident_date' => now(config('atice.timezone'))->toDateString(),
        ]);
        Audit::log('memo.create', $memo, null, ['memo_no' => $memo->memo_no, 'kind' => 'announcement', 'audience' => $audience]);

        if ($request->boolean('send')) {
            $error = $this->releaseAnnouncement($request, $memo);
            if ($error) {
                return response()->json(['message' => $error, 'memo' => $this->fresh($memo)], 422);
            }
        }

        return response()->json($this->fresh($memo), 201);
    }

    // GET /api/memos/{memo}/recipients (HR): who received an announcement, and
    // who has no account (they get a printed copy)
    public function recipients(EmployeeMemo $memo)
    {
        abort_unless($memo->isAnnouncement(), 404);
        $list = $memo->status === 'draft'
            ? EmployeeMemo::resolveAudience($memo->audience ?? [])->map(fn ($e) => ['employee' => $e, 'read_at' => null, 'acknowledged_at' => null])
            : $memo->recipients()->with('employee:id,employee_id,first_name,middle_initial,last_name,suffix,position,user_id')->get()
                ->map(fn ($r) => ['employee' => $r->employee, 'read_at' => $r->read_at, 'acknowledged_at' => $r->acknowledged_at]);

        return response()->json(['preview' => $memo->status === 'draft', 'recipients' => $list->values()]);
    }

    // The group becomes names now; nobody is sent the notice twice.
    private function releaseAnnouncement(Request $request, EmployeeMemo $memo): ?string
    {
        $people = EmployeeMemo::resolveAudience($memo->audience ?? []);
        if ($people->isEmpty()) {
            return 'Nobody active is in this group. Pick other recipients.';
        }

        DB::transaction(function () use ($request, $memo, $people) {
            foreach ($people as $e) {
                MemoRecipient::firstOrCreate(['memo_id' => $memo->id, 'employee_id' => $e->id]);
            }
            $memo->update(['status' => 'sent', 'issued_by' => $request->user()->id, 'issued_at' => now()]);
        });
        Audit::log('memo.announcement_sent', $memo, ['status' => 'draft'], ['status' => 'sent', 'recipients' => $people->count()]);

        $userIds = $people->pluck('user_id')->filter()->values()->all();
        if ($userIds) {
            LiveEvent::create([
                'module' => 'memos',
                'action' => 'created',
                'message' => mb_substr("New announcement from HR: {$memo->subject}", 0, 255),
                'roles' => [],
                'user_ids' => $userIds,
                'actor_id' => $request->user()->id,
            ]);
        }

        return null;
    }

    // PATCH /api/memos/{memo} (HR): edit a draft before sending
    public function update(Request $request, EmployeeMemo $memo)
    {
        if ($memo->status !== 'draft') {
            return response()->json(['message' => 'Only a draft can be edited. Cancel the memo and issue a new one instead.'], 422);
        }
        $data = $request->validate([
            'level' => ['sometimes', Rule::in(array_keys(EmployeeMemo::LEVELS))],
            'subject' => 'sometimes|string|max:255',
            'body' => 'sometimes|string|min:20|max:10000',
            'incident_date' => 'sometimes|date|before_or_equal:'.DriverAvailability::today(),
        ]);
        if ($memo->category === 'zero_tolerance' || $memo->isAnnouncement()) {
            unset($data['level']); // always a Notice to Explain; an announcement has no level
        }
        $before = $memo->only(array_keys($data));
        $memo->update($data);
        Audit::log('memo.update', $memo, $before, $data);

        return response()->json($this->fresh($memo));
    }

    // POST /api/memos/{memo}/send (HR): the draft reaches the employee's account.
    // A formal memo is released only after Admin approved it.
    public function send(Request $request, EmployeeMemo $memo)
    {
        if ($memo->isAnnouncement()) {
            if ($memo->status !== 'draft') {
                return response()->json(['message' => 'This announcement was already sent.'], 422);
            }
            if ($error = $this->releaseAnnouncement($request, $memo)) {
                return response()->json(['message' => $error], 422);
            }

            return response()->json($this->fresh($memo));
        }
        if ($memo->isFormal()) {
            if ($memo->status === 'draft' || $memo->status === 'for_approval') {
                return response()->json(['message' => $memo->status === 'draft' ? self::NEEDS_APPROVAL : 'This memo is waiting for Admin approval.'], 422);
            }
            if ($memo->status !== 'approved') {
                return response()->json(['message' => 'This memo was already sent.'], 422);
            }
        } elseif ($memo->status !== 'draft') {
            return response()->json(['message' => 'This memo was already sent.'], 422);
        }
        $this->dispatch($request, $memo);

        return response()->json($this->fresh($memo));
    }

    // POST /api/memos/{memo}/endorse (HR): a formal draft goes to Admin for approval
    public function endorse(Request $request, EmployeeMemo $memo)
    {
        if (! $memo->isFormal()) {
            return response()->json(['message' => 'Only a formal memo goes to Admin. Send this warning directly.'], 422);
        }
        if ($memo->status !== 'draft') {
            return response()->json(['message' => 'Only a draft can be endorsed.'], 422);
        }
        $this->markEndorsed($request, $memo);

        return response()->json($this->fresh($memo));
    }

    // GET /api/memo-approvals (Admin): formal memos waiting for approval, newest first
    public function approvals()
    {
        return response()->json([
            'memos' => EmployeeMemo::with([self::EMPLOYEE_COLUMNS, 'files', 'endorser:id,name'])
                ->where('status', 'for_approval')
                ->orderBy('endorsed_at')
                ->get(),
            'recent' => EmployeeMemo::with([self::EMPLOYEE_COLUMNS, 'approver:id,name', 'returner:id,name'])
                ->where(fn ($q) => $q->whereNotNull('approved_at')->orWhereNotNull('returned_at'))
                ->latest('updated_at')->limit(10)
                ->get(),
        ]);
    }

    // POST /api/memos/{memo}/approve (Admin): HR may now release it
    public function approve(Request $request, EmployeeMemo $memo)
    {
        if ($memo->status !== 'for_approval') {
            return response()->json(['message' => 'This memo is not waiting for approval.'], 422);
        }
        $memo->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now(), 'return_reason' => null]);
        Audit::log('memo.approved', $memo, ['status' => 'for_approval'], ['status' => 'approved']);

        return response()->json($this->fresh($memo));
    }

    // POST /api/memos/{memo}/return (Admin): back to HR as a draft, with the reason
    public function returnToHr(Request $request, EmployeeMemo $memo)
    {
        $data = $request->validate(
            ['reason' => 'required|string|min:5|max:1000'],
            ['reason.required' => 'Write why the memo goes back to HR.', 'reason.min' => 'Write why the memo goes back to HR (at least 5 characters).']
        );
        if ($memo->status !== 'for_approval') {
            return response()->json(['message' => 'This memo is not waiting for approval.'], 422);
        }
        $memo->update([
            'status' => 'draft',
            'returned_by' => $request->user()->id,
            'returned_at' => now(),
            'return_reason' => trim($data['reason']),
        ]);
        Audit::log('memo.returned', $memo, ['status' => 'for_approval'], ['status' => 'draft', 'reason' => trim($data['reason'])]);

        return response()->json($this->fresh($memo));
    }

    private function markEndorsed(Request $request, EmployeeMemo $memo): void
    {
        $memo->update(['status' => 'for_approval', 'endorsed_by' => $request->user()->id, 'endorsed_at' => now()]);
        Audit::log('memo.endorsed', $memo, ['status' => 'draft'], ['status' => 'for_approval']);
    }

    // POST /api/memos/{memo}/cancel (HR): a draft or a sent memo that should not
    // stand (kiosk down, wrong person); it no longer counts as an offense
    public function cancel(Request $request, EmployeeMemo $memo)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        if (! in_array($memo->status, ['draft', 'for_approval', 'approved', 'sent', 'awaiting_physical'], true)) {
            return response()->json(['message' => 'An acknowledged or archived memo cannot be cancelled.'], 422);
        }
        $before = $memo->only(['status']);
        $memo->update([
            'status' => 'cancelled',
            'cancelled_by' => $request->user()->id,
            'cancelled_at' => now(),
            'cancel_reason' => trim($data['reason']),
        ]);
        if ($memo->violation === 'no_time_out' && $memo->employee && $memo->employee->no_timeout_count > 0) {
            $memo->employee->decrement('no_timeout_count');
        }
        Audit::log('memo.cancel', $memo, $before, ['status' => 'cancelled', 'reason' => $memo->cancel_reason]);

        return response()->json($this->fresh($memo));
    }

    // POST /api/memos/{memo}/files (HR, multipart): kind attachment (evidence,
    // letters) or signed_copy (the scanned wet-signed memo, which archives it)
    public function upload(Request $request, EmployeeMemo $memo)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(EmployeeMemoFile::KINDS)],
            'files' => 'required|array|min:1|max:10',
            'files.*' => 'file|max:10240|mimes:'.EmployeeMemoFile::MIMES,
        ], ['files.*.mimes' => 'Files must be PDF, Word (.docx) or pictures (JPG, PNG, WebP).']);

        if ($memo->status === 'cancelled') {
            return response()->json(['message' => 'This memo was cancelled.'], 422);
        }
        if ($data['kind'] === 'signed_copy' && ! in_array($memo->status, ['awaiting_physical', 'sent', 'acknowledged'], true)) {
            return response()->json(['message' => 'Send the memo before uploading the signed copy.'], 422);
        }

        // All or nothing: the file records, the archive status and the audit entry are one
        // transaction, and the files written by this request are removed if it fails.
        $stored = new StoredFiles('local');
        try {
            DB::transaction(function () use ($request, $memo, $data, $stored) {
                $names = $this->storeFiles($request, $memo, $data['kind'], $stored);
                if ($data['kind'] === 'signed_copy') {
                    $before = $memo->only(['status']);
                    $memo->update(['status' => 'archived', 'signed_at' => now()]);
                    Audit::log('memo.signed', $memo, $before, ['status' => 'archived', 'files' => $names]);
                } else {
                    Audit::log('memo.attach', $memo, null, ['files' => $names]);
                }
            });
        } catch (\Throwable $e) {
            $stored->discard();
            throw $e;
        }

        return response()->json($this->fresh($memo));
    }

    // GET /api/memos/{memo}/files/{file} (HR)
    public function file(EmployeeMemo $memo, EmployeeMemoFile $file)
    {
        return $this->streamFile($memo, $file);
    }

    // POST /api/memos/{memo}/close-investigation (HR): the hearing is done; the
    // employee is no longer "Pending Investigation"
    public function closeInvestigation(Request $request, EmployeeMemo $memo)
    {
        $data = $request->validate(['outcome' => 'required|string|min:5|max:2000']);
        $employee = $memo->employee;
        if (! $employee || ! $employee->investigation_status) {
            return response()->json(['message' => 'This employee has no open investigation.'], 422);
        }
        $employee->update(['investigation_status' => null, 'investigation_since' => null]);
        Audit::log('memo.investigation_closed', $employee, ['investigation_status' => 'pending_investigation'], ['outcome' => trim($data['outcome']), 'memo_no' => $memo->memo_no]);

        return response()->json($this->fresh($memo));
    }

    // GET /api/my-memos (any employee with an account): memos HR has sent to
    // them, and the announcements they received
    public function myIndex(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();
        if (! $employee) {
            return response()->json([]);
        }

        $fields = ['id', 'kind', 'memo_no', 'subject', 'body', 'violation_label', 'level', 'level_label', 'status', 'needs_physical', 'incident_date', 'issued_at', 'acknowledged_at', 'signed_at', 'files'];
        $own = EmployeeMemo::where('employee_id', $employee->id)
            ->whereIn('status', EmployeeMemo::VISIBLE_TO_EMPLOYEE)
            ->with('files:id,employee_memo_id,kind,original_name,mime,size,created_at')
            ->get()
            ->map(fn ($m) => $m->only($fields));

        $received = MemoRecipient::where('employee_id', $employee->id)->pluck('acknowledged_at', 'memo_id');
        $announcements = $received->isEmpty() ? collect() : EmployeeMemo::whereIn('id', $received->keys())
            ->where('kind', 'announcement')
            ->whereIn('status', EmployeeMemo::VISIBLE_TO_EMPLOYEE)
            ->with('files:id,employee_memo_id,kind,original_name,mime,size,created_at')
            ->get()
            ->map(fn ($m) => ['acknowledged_at' => $received[$m->id]] + $m->only($fields));

        return response()->json($own->concat($announcements)->sortByDesc('issued_at')->values());
    }

    // POST /api/my-memos/{memo}/acknowledge: a verbal or first written warning,
    // or an announcement they received
    public function acknowledge(Request $request, EmployeeMemo $memo)
    {
        $this->ownMemo($request, $memo);
        if ($memo->isAnnouncement()) {
            $employeeId = Employee::where('user_id', $request->user()->id)->value('id');
            $row = MemoRecipient::where('memo_id', $memo->id)->where('employee_id', $employeeId)->firstOrFail();
            if ($row->acknowledged_at) {
                return response()->json(['message' => 'This announcement was already acknowledged.'], 422);
            }
            $row->update(['acknowledged_at' => now(), 'read_at' => $row->read_at ?? now()]);

            return response()->json(['status' => 'acknowledged', 'acknowledged_at' => $row->acknowledged_at]);
        }
        if ($memo->status !== 'sent') {
            return response()->json(['message' => $memo->status === 'awaiting_physical'
                ? 'This memo is signed in person. Please report to the HR office.'
                : 'This memo was already acknowledged.'], 422);
        }
        $memo->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);
        Audit::log('memo.acknowledge', $memo, ['status' => 'sent'], ['status' => 'acknowledged']);

        return response()->json(['status' => 'acknowledged', 'acknowledged_at' => $memo->acknowledged_at]);
    }

    // GET /api/my-memos/{memo}/files/{file}
    public function myFile(Request $request, EmployeeMemo $memo, EmployeeMemoFile $file)
    {
        $this->ownMemo($request, $memo);

        return $this->streamFile($memo, $file);
    }

    private function ownMemo(Request $request, EmployeeMemo $memo): void
    {
        $employeeId = Employee::where('user_id', $request->user()->id)->value('id');
        $mine = $memo->isAnnouncement()
            ? $employeeId && MemoRecipient::where('memo_id', $memo->id)->where('employee_id', $employeeId)->exists()
            : $employeeId && $memo->employee_id === $employeeId;
        abort_unless($mine && in_array($memo->status, EmployeeMemo::VISIBLE_TO_EMPLOYEE, true), 404);
    }

    private function streamFile(EmployeeMemo $memo, EmployeeMemoFile $file)
    {
        abort_unless($file->employee_memo_id === $memo->id && Storage::disk('local')->exists($file->path), 404);

        // Same protective headers as every other private download.
        return Storage::disk('local')->response($file->path, $file->original_name, [
            'Content-Type' => $file->mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    // Zero-tolerance offenses are always a Notice to Explain; otherwise HR's
    // choice, else the level for the offense number.
    private function levelFor(string $violation, ?string $level, int $offense): string
    {
        if (EmployeeMemo::categoryOf($violation) === 'zero_tolerance') {
            return 'nte';
        }

        return $level ?: EmployeeMemo::levelForOffense($offense);
    }

    private function dispatch(Request $request, EmployeeMemo $memo): void
    {
        $memo->loadMissing('employee');
        $was = $memo->status;
        $status = $memo->needs_physical ? 'awaiting_physical' : 'sent';
        $memo->update(['status' => $status, 'issued_by' => $request->user()->id, 'issued_at' => now()]);

        // a Notice to Explain or a zero-tolerance offense opens an investigation
        if (($memo->level === 'nte' || $memo->category === 'zero_tolerance') && $memo->employee && ! $memo->employee->investigation_status) {
            $memo->employee->update(['investigation_status' => 'pending_investigation', 'investigation_since' => now()]);
        }
        Audit::log('memo.send', $memo, ['status' => $was], ['status' => $status, 'level' => $memo->level]);
    }

    private function storeFiles(Request $request, EmployeeMemo $memo, string $kind, StoredFiles $stored): array
    {
        $names = [];
        foreach ((array) $request->file('files', []) as $file) {
            $memo->files()->create([
                'kind' => $kind,
                'path' => $stored->store($file, 'employee-memos', Str::uuid().'.'.$file->extension()),
                // cleaned like every other upload name
                'original_name' => mb_substr(preg_replace('/[^\pL\pN ._()-]/u', '_', $file->getClientOriginalName()), 0, 255),
                'mime' => $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
            $names[] = $file->getClientOriginalName();
        }

        return $names;
    }

    private function fresh(EmployeeMemo $memo): EmployeeMemo
    {
        return $memo->fresh([self::EMPLOYEE_COLUMNS, 'files', 'issuer:id,name', 'endorser:id,name', 'approver:id,name', 'returner:id,name']);
    }
}
