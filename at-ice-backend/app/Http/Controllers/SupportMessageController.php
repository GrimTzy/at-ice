<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\SupportMessage;
use App\Models\SupportReply;
use App\Models\User;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Contact Support as tickets: customers send messages, the Admin (dispatch
// room) handles them as tickets (TKT-20261022-001) with a priority, an
// assignee, replies the customer sees, internal notes, and a resolution note.
// There is no other recipient.
class SupportMessageController extends Controller
{
    private const DETAIL = ['customer:id,name,customer_code,contact_person,contact_number,email', 'resolver:id,name', 'assignee:id,name', 'escalator:id,name'];

    private function myCustomer(Request $request): ?Customer
    {
        return Customer::where('user_id', $request->user()->id)->first();
    }

    // GET /api/my-support-messages (customer): own tickets, newest first, with
    // the replies (never the internal notes) and the resolution note
    public function myIndex(Request $request)
    {
        $customer = $this->myCustomer($request);
        if (! $customer) {
            return response()->json([]);
        }

        return response()->json(
            SupportMessage::where('customer_id', $customer->id)
                ->with(['replies' => fn ($q) => $q->whereIn('kind', SupportReply::CUSTOMER_VISIBLE)->select('id', 'support_message_id', 'kind', 'body', 'created_at')])
                ->latest()->limit(20)
                ->get(['id', 'ticket_no', 'customer_id', 'topic', 'message', 'status', 'escalated_at', 'resolved_at', 'resolution_note', 'created_at'])
        );
    }

    // POST /api/my-support-messages (customer)
    public function myStore(Request $request)
    {
        $customer = $this->myCustomer($request);
        if (! $customer) {
            return response()->json(['message' => 'No customer account linked to this login.'], 404);
        }

        $data = $request->validate([
            'topic' => ['required', Rule::in(array_diff(array_keys(SupportMessage::TOPICS), SupportMessage::RETIRED_TOPICS))],
            'message' => 'required|string|min:5|max:2000',
        ]);

        // Limits: one open ticket per topic, the customer adds to it with Reply;
        // and at most support.max_open_tickets open tickets at once.
        $active = SupportMessage::where('customer_id', $customer->id)->whereIn('status', SupportMessage::ACTIVE);

        $same = (clone $active)->where('topic', $data['topic'])->value('ticket_no');
        if ($same) {
            return response()->json([
                'message' => "You already have an open ticket about this ({$same}). Add to it with Reply instead.",
                'errors' => ['topic' => ["You already have an open ticket about this ({$same}). Add to it with Reply instead."]],
                'ticket_no' => $same,
            ], 422);
        }

        $max = (int) Settings::get('support.max_open_tickets');
        if ($active->count() >= $max) {
            return response()->json([
                'message' => "You have {$max} open tickets. Please wait for one to be resolved, or add to an open one with Reply.",
                'errors' => ['topic' => ["You have {$max} open tickets. Please wait for one to be resolved, or add to an open one with Reply."]],
            ], 422);
        }

        $message = SupportMessage::create([
            'customer_id' => $customer->id,
            'user_id' => $request->user()->id,
            'topic' => $data['topic'],
            'message' => trim($data['message']),
            'status' => 'open',
        ]);

        return response()->json($message, 201);
    }

    // POST /api/my-support-messages/{supportMessage}/replies (customer): add to
    // one of their own open or in-progress tickets
    public function myReply(Request $request, SupportMessage $supportMessage)
    {
        $customer = $this->myCustomer($request);
        if (! $customer || $supportMessage->customer_id !== $customer->id) {
            return response()->json(['message' => 'Ticket not found.'], 404);
        }

        $data = $request->validate(['body' => 'required|string|min:2|max:2000']);

        if (! in_array($supportMessage->status, SupportMessage::ACTIVE, true)) {
            return response()->json(['message' => 'This ticket is already resolved. Open a new ticket if you still need help.'], 422);
        }

        $reply = $supportMessage->replies()->create([
            'user_id' => $request->user()->id,
            'kind' => 'customer',
            'body' => trim($data['body']),
        ]);

        return response()->json($reply->only(['id', 'support_message_id', 'kind', 'body', 'created_at']), 201);
    }

    // GET /api/support-messages (admin): every ticket, newest first, plus the
    // overdue limit and the people a ticket can be assigned to
    public function index(Request $request)
    {
        return response()->json([
            // last_customer_reply_at and read_at (this Admin's): the inbox shows
            // "New" when the customer wrote since this Admin last opened it
            'messages' => SupportMessage::with(self::DETAIL)->withCount('replies')
                ->withMax(['replies as last_customer_reply_at' => fn ($q) => $q->where('kind', 'customer')], 'created_at')
                ->addSelect(['read_at' => DB::table('support_message_reads')->select('read_at')
                    ->whereColumn('support_message_id', 'support_messages.id')->where('user_id', $request->user()->id)->limit(1)])
                ->latest()->limit(500)->get(),
            'open_count' => SupportMessage::where('status', 'open')->count(),
            'escalated_count' => SupportMessage::whereNotNull('escalated_at')->whereIn('status', SupportMessage::ACTIVE)->count(),
            'overdue_hours' => (int) Settings::get('support.overdue_hours'),
            'assignees' => User::where('role', 'admin')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    // GET /api/support-messages/{supportMessage} (admin): one ticket with its thread
    // Opening a ticket, or acting on it, marks it read for this Admin (the inbox
    // "New" badge).
    public function show(SupportMessage $supportMessage)
    {
        DB::table('support_message_reads')->updateOrInsert(
            ['support_message_id' => $supportMessage->id, 'user_id' => request()->user()->id],
            ['read_at' => now()],
        );

        return response()->json($supportMessage->load([...self::DETAIL, 'replies.user:id,name']));
    }

    // PATCH /api/support-messages/{supportMessage} (admin): priority, assignee,
    // or start work on it (status in_progress)
    public function update(Request $request, SupportMessage $supportMessage)
    {
        $data = $request->validate([
            'priority' => ['sometimes', Rule::in(SupportMessage::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', Rule::exists('users', 'id')->where('role', 'admin')],
            'status' => ['sometimes', Rule::in(['in_progress'])],
        ]);

        if (isset($data['status']) && $supportMessage->status !== 'open') {
            return response()->json(['message' => 'Only an open ticket can be moved to In progress.'], 422);
        }

        $before = $supportMessage->only(array_keys($data));
        $supportMessage->update($data);
        Audit::log('support.update', $supportMessage, $before, $data);

        return $this->show($supportMessage);
    }

    // POST /api/support-messages/{supportMessage}/replies (admin): a reply the
    // customer sees, or an internal note. A reply to an open ticket starts work on it.
    public function reply(Request $request, SupportMessage $supportMessage)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(SupportReply::KINDS)],
            'body' => 'required|string|min:2|max:2000',
        ]);

        if ($supportMessage->status === 'closed') {
            return response()->json(['message' => 'This ticket is closed. Reopen it first.'], 422);
        }

        $supportMessage->replies()->create([
            'user_id' => $request->user()->id,
            'kind' => $data['kind'],
            'body' => trim($data['body']),
        ]);

        if ($data['kind'] === 'reply' && $supportMessage->status === 'open') {
            $supportMessage->update(['status' => 'in_progress']);
        }

        return $this->show($supportMessage);
    }

    // PATCH /api/support-messages/{supportMessage}/resolve (admin): needs a resolution note
    public function resolve(Request $request, SupportMessage $supportMessage)
    {
        $data = $request->validate(['resolution_note' => 'required|string|min:5|max:2000']);

        if (! in_array($supportMessage->status, ['open', 'in_progress'], true)) {
            return response()->json(['message' => 'This ticket is already resolved.'], 422);
        }

        $before = $supportMessage->only(['status']);
        $supportMessage->update([
            'status' => 'resolved',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
            'resolution_note' => trim($data['resolution_note']),
        ]);
        Audit::log('support.resolve', $supportMessage, $before, $supportMessage->only(['status', 'resolution_note']));

        return $this->show($supportMessage);
    }

    // PATCH /api/support-messages/{supportMessage}/close (admin): a resolved ticket is filed away
    public function close(SupportMessage $supportMessage)
    {
        if ($supportMessage->status !== 'resolved') {
            return response()->json(['message' => 'Resolve the ticket before closing it.'], 422);
        }

        $supportMessage->update(['status' => 'closed', 'closed_at' => now()]);
        Audit::log('support.close', $supportMessage, ['status' => 'resolved'], ['status' => 'closed']);

        return $this->show($supportMessage);
    }

    // PATCH /api/support-messages/{supportMessage}/escalate (admin): a ticket that
    // needs management attention, with the reason. The status flow is unchanged.
    public function escalate(Request $request, SupportMessage $supportMessage)
    {
        $data = $request->validate(
            ['reason' => 'required|string|min:5|max:500'],
            ['reason.required' => 'Write why this ticket is escalated.', 'reason.min' => 'Write why this ticket is escalated (at least 5 characters).']
        );

        if (! in_array($supportMessage->status, SupportMessage::ACTIVE, true)) {
            return response()->json(['message' => 'Only an open or in-progress ticket can be escalated.'], 422);
        }
        if ($supportMessage->escalated_at) {
            return response()->json(['message' => 'This ticket is already escalated.'], 422);
        }

        $supportMessage->update([
            'escalated_at' => now(),
            'escalated_by' => $request->user()->id,
            'escalation_reason' => trim($data['reason']),
        ]);
        Audit::log('support.escalated', $supportMessage, null, ['reason' => trim($data['reason'])]);

        return $this->show($supportMessage);
    }

    // PATCH /api/support-messages/{supportMessage}/de-escalate (admin)
    public function deEscalate(SupportMessage $supportMessage)
    {
        if (! $supportMessage->escalated_at) {
            return response()->json(['message' => 'This ticket is not escalated.'], 422);
        }

        $before = ['reason' => $supportMessage->escalation_reason];
        $supportMessage->update(['escalated_at' => null, 'escalated_by' => null, 'escalation_reason' => null]);
        Audit::log('support.de_escalated', $supportMessage, $before, null);

        return $this->show($supportMessage);
    }

    // PATCH /api/support-messages/{supportMessage}/reopen (admin)
    public function reopen(SupportMessage $supportMessage)
    {
        $before = $supportMessage->only(['status', 'resolution_note']);
        $supportMessage->update(['status' => 'open', 'resolved_by' => null, 'resolved_at' => null, 'resolution_note' => null, 'closed_at' => null]);
        Audit::log('support.reopen', $supportMessage, $before, ['status' => 'open']);

        return $this->show($supportMessage);
    }
}
