<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Support\Upload;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

// Streams a private ticket attachment to whoever may read that ticket: staff with ticket access in
// its branch, the customer who owns it, or the reseller it belongs to. Internal notes stay staff-only.
class TicketFileController extends Controller
{
    public function show(int $id)
    {
        $reply = TicketReply::with('ticket')->findOrFail($id);
        abort_unless($reply->ticket && $this->canRead($reply->ticket, $reply->is_internal), 404);
        abort_unless(Upload::privateExists($reply->attachment), 404);
        return Storage::disk(Upload::PRIVATE_DISK)->response($reply->attachment, basename($reply->attachment), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function canRead(Ticket $ticket, bool $internal): bool
    {
        if (($user = Auth::guard('web')->user()) && checkAccess('ticket')) {
            $branchId = session('branch')?->id ?? $user->branch_id;
            if ((int) $ticket->branch_id === (int) $branchId) {
                return true;
            }
        }
        if ($internal) {
            return false;
        }
        if (($reseller = Auth::guard('reseller')->user()) && (int) $ticket->reseller_id === (int) $reseller->id) {
            return true;
        }
        return ($customer = Auth::guard('customer')->user()) && (int) $ticket->customer_id === (int) $customer->id;
    }
}
