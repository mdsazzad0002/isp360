<?php

namespace App\Http\Controllers\Isp;

use App\Models\Ticket;
use App\Models\User;
use App\Services\Isp\AuditLogger;
use App\Services\Isp\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Company side of support: every ticket of the branch (customers' and resellers').
class TicketController extends IspController
{
    public function create(Request $request)
    {
        return $this->page('ticket', 'Isp/Ticket', [
            'openId' => $request->id ? (int) $request->id : null,
            'staff' => User::where('status', 'a')->orderBy('name')->get(['id', 'name', 'username']),
            'categories' => Ticket::CATEGORIES,
        ]);
    }

    public function index(Request $request)
    {
        if ($r = $this->deny('ticket')) return $r;
        $base = Ticket::where('branch_id', $this->branchId);
        $query = (clone $base)->with(['customer', 'reseller', 'assignee'])
            ->when($request->status === 'active', fn ($q) => $q->whereNotIn('status', ['resolved', 'closed']))
            ->when($request->status && $request->status !== 'active', fn ($q) => $q->where('status', $request->status))
            ->when($request->needsReply, fn ($q) => $q->whereNotIn('status', ['resolved', 'closed'])->where('last_reply_by_type', '!=', 'admin'))
            ->when($request->priority, fn ($q, $p) => $q->where('priority', $p))
            ->when($request->from === 'reseller', fn ($q) => $q->whereNotNull('reseller_id'))
            ->when($request->from === 'company', fn ($q) => $q->whereNull('reseller_id'))
            ->when($request->assigned === 'me', fn ($q) => $q->where('assigned_to', $this->userId))
            ->when($request->search, function ($q, $term) {
                $q->where(fn ($w) => $w->where('ticket_no', 'like', "%{$term}%")->orWhere('subject', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"))
                    ->orWhereHas('reseller', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")));
            });

        return response()->json([
            'page' => $query->orderByRaw("field(status, 'open', 'in_progress', 'waiting', 'resolved', 'closed')")->latest('last_reply_at')->paginate(min(100, (int) ($request->per_page ?: 20))),
            'counts' => (clone $base)->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status'),
            'needsReply' => (clone $base)->whereNotIn('status', ['resolved', 'closed'])->where('last_reply_by_type', '!=', 'admin')->count(),
        ]);
    }

    public function show(Request $request)
    {
        if ($r = $this->deny('ticket')) return $r;
        $ticket = Ticket::with(['customer', 'reseller', 'connection', 'assignee'])->where('branch_id', $this->branchId)->findOrFail($request->id);
        return response()->json(['ticket' => $ticket, 'replies' => TicketService::thread($ticket, true)]);
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('ticket')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'customer_id' => 'nullable|integer',
            'reseller_id' => 'nullable|integer',
            'connection_id' => 'nullable|integer',
            'subject' => 'required|max:200',
            'category' => 'nullable|in:' . implode(',', Ticket::CATEGORIES),
            'priority' => 'nullable|in:' . implode(',', Ticket::PRIORITIES),
            'message' => 'required|max:5000',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:3072',
        ])) return $r;
        try {
            $data = $request->only(['customer_id', 'reseller_id', 'connection_id', 'subject', 'category', 'priority', 'message']);
            $data['attachment'] = TicketService::storeAttachment($request, (int) $this->branchId);
            $ticket = TicketService::open($data, 'admin', $this->userId, $this->branchId);
            return $this->ok("Ticket {$ticket->ticket_no} opened", ['id' => $ticket->id]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function reply(Request $request)
    {
        if ($r = $this->deny('ticket')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'required|integer',
            'message' => 'required|max:5000',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:3072',
        ])) return $r;
        try {
            $ticket = Ticket::where('branch_id', $this->branchId)->findOrFail($request->id);
            $file = TicketService::storeAttachment($request, (int) $ticket->branch_id);
            TicketService::reply($ticket, 'admin', $this->userId, $request->message, $file, $request->boolean('internal'));
            if ($request->status) {
                TicketService::setStatus($ticket, $request->status, 'admin', $this->userId);
            }
            return $this->ok($request->boolean('internal') ? 'Internal note added' : 'Reply sent');
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function status(Request $request)
    {
        if ($r = $this->deny('ticket')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required|integer', 'status' => 'required|in:' . implode(',', Ticket::STATUSES)])) return $r;
        try {
            $ticket = TicketService::setStatus(Ticket::where('branch_id', $this->branchId)->findOrFail($request->id), $request->status, 'admin', $this->userId);
            return $this->ok("Ticket {$ticket->ticket_no} is now " . str_replace('_', ' ', $ticket->status));
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    // Priority, category and assignee.
    public function update(Request $request)
    {
        if ($r = $this->deny('ticket')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'required|integer',
            'priority' => 'nullable|in:' . implode(',', Ticket::PRIORITIES),
            'category' => 'nullable|in:' . implode(',', Ticket::CATEGORIES),
            'assigned_to' => 'nullable|integer|exists:users,id',
        ])) return $r;
        try {
            return DB::transaction(function () use ($request) {
                $ticket = Ticket::where('branch_id', $this->branchId)->lockForUpdate()->findOrFail($request->id);
                $old = $ticket->only(['priority', 'category', 'assigned_to']);
                foreach (['priority', 'category'] as $field) {
                    if ($request->filled($field)) {
                        $ticket->{$field} = $request->{$field};
                    }
                }
                if ($request->has('assigned_to')) {
                    $ticket->assigned_to = $request->assigned_to ?: null;
                }
                $ticket->save();
                AuditLogger::log('ticket.updated', $ticket, $old, $ticket->only(['priority', 'category', 'assigned_to']));
                return $this->ok('Ticket updated');
            });
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }
}
