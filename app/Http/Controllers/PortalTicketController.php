<?php

namespace App\Http\Controllers;

use App\Models\Connection;
use App\Models\Customer;
use App\Models\Ticket;
use App\Services\Isp\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

// Support tickets from the customer portal and the reseller portal. The route name
// (reseller.* / customerPortal.*) decides who the visitor is:
//  - customer: their own tickets
//  - reseller: their own tickets + their customers' tickets (they support those customers)
class PortalTicketController extends Controller
{
    private function portal(Request $request): string
    {
        return $request->routeIs('reseller.*') ? 'reseller' : 'customer';
    }

    private function actor(Request $request)
    {
        return Auth::guard($this->portal($request))->user();
    }

    private function visible(Request $request)
    {
        $actor = $this->actor($request);
        return $this->portal($request) === 'reseller'
            ? Ticket::where('reseller_id', $actor->id)
            : Ticket::where('customer_id', $actor->id);
    }

    public function page(Request $request)
    {
        $actor = $this->actor($request);
        $isReseller = $this->portal($request) === 'reseller';

        return \Inertia\Inertia::render('Portal/Tickets', [
            'portal' => $this->portal($request),
            'me' => $actor->only(['id', 'name']),
            'openId' => $request->id ? (int) $request->id : null,
            'categories' => array_values(array_filter(Ticket::CATEGORIES, fn ($c) => $isReseller || $c !== 'reseller_account')),
            'customers' => $isReseller ? Customer::where('reseller_id', $actor->id)->orderBy('name')->get(['id', 'code', 'name', 'phone']) : [],
            'connections' => $isReseller ? [] : Connection::where('customer_id', $actor->id)->get(['id', 'code', 'pppoe_username', 'status']),
        ]);
    }

    public function index(Request $request)
    {
        $query = $this->visible($request)->with('customer')
            ->when($request->status === 'active', fn ($q) => $q->whereNotIn('status', ['resolved', 'closed']))
            ->when($request->status && $request->status !== 'active', fn ($q) => $q->where('status', $request->status))
            ->when($request->mine === 'own', fn ($q) => $q->whereNull('customer_id'))
            ->when($request->mine === 'customers', fn ($q) => $q->whereNotNull('customer_id'))
            ->when($request->search, fn ($q, $term) => $q->where(fn ($w) => $w->where('ticket_no', 'like', "%{$term}%")->orWhere('subject', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))));

        return response()->json($query->orderByRaw("field(status, 'open', 'in_progress', 'waiting', 'resolved', 'closed')")->latest('last_reply_at')
            ->paginate(20, ['id', 'ticket_no', 'subject', 'category', 'priority', 'status', 'customer_id', 'reseller_id', 'opened_by_type', 'last_reply_by_type', 'last_reply_at', 'created_at']));
    }

    public function show(Request $request)
    {
        $ticket = $this->visible($request)->with(['customer', 'connection'])->findOrFail($request->id);
        return response()->json([
            'ticket' => $ticket->makeHidden(['assigned_to']),
            'replies' => TicketService::thread($ticket, false),
            'isRequester' => TicketService::isRequester($ticket, $this->portal($request)),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'nullable|integer',
            'connection_id' => 'nullable|integer',
            'subject' => 'required|max:200',
            'category' => 'nullable|in:' . implode(',', Ticket::CATEGORIES),
            'priority' => 'nullable|in:low,normal,high',
            'message' => 'required|max:5000',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:3072',
        ]);
        if ($validator->fails()) return send_error('Validation Error', $validator->errors(), 422);

        try {
            $actor = $this->actor($request);
            $data = $request->only(['customer_id', 'connection_id', 'subject', 'category', 'priority', 'message']);
            $data['attachment'] = TicketService::storeAttachment($request, (int) $actor->branch_id);
            $ticket = TicketService::open($data, $this->portal($request), $actor->id, $actor->branch_id);
            return response()->json(['status' => true, 'message' => "Ticket {$ticket->ticket_no} opened. We will reply here.", 'id' => $ticket->id]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return send_error('Customer or connection not found', null, 404);
        } catch (\RuntimeException $e) {
            return send_error($e->getMessage(), null, 422);
        }
    }

    public function reply(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'message' => 'required|max:5000',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:3072',
        ]);
        if ($validator->fails()) return send_error('Validation Error', $validator->errors(), 422);

        try {
            $ticket = $this->visible($request)->findOrFail($request->id);
            $file = TicketService::storeAttachment($request, (int) $ticket->branch_id);
            TicketService::reply($ticket, $this->portal($request), $this->actor($request)->id, $request->message, $file);
            return response()->json(['status' => true, 'message' => 'Reply sent']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return send_error('Ticket not found', null, 404);
        } catch (\RuntimeException $e) {
            return send_error($e->getMessage(), null, 422);
        }
    }

    public function status(Request $request)
    {
        $validator = Validator::make($request->all(), ['id' => 'required|integer', 'status' => 'required|in:' . implode(',', Ticket::STATUSES)]);
        if ($validator->fails()) return send_error('Validation Error', $validator->errors(), 422);

        try {
            $ticket = $this->visible($request)->findOrFail($request->id);
            $ticket = TicketService::setStatus($ticket, $request->status, $this->portal($request), $this->actor($request)->id);
            return response()->json(['status' => true, 'message' => "Ticket {$ticket->ticket_no} is now " . str_replace('_', ' ', $ticket->status)]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return send_error('Ticket not found', null, 404);
        } catch (\RuntimeException $e) {
            return send_error($e->getMessage(), null, 422);
        }
    }
}
