<?php

namespace App\Services\Isp;

use App\Models\Connection;
use App\Models\Customer;
use App\Models\Reseller;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Support tickets. Three kinds of author: 'customer', 'reseller', 'admin' (company staff).
//
// Who is on which side of a ticket:
//  - requester: the customer the ticket is about, or the reseller for a reseller's own ticket
//  - support:   the company, and the customer's reseller for a reseller customer's ticket
// A requester reply re-opens a resolved/waiting ticket; a support reply moves an open one
// to in_progress. Closed tickets are final. Internal notes are company-only.
class TicketService
{
    public static function open(array $data, string $authorType, int $authorId, int $branchId): Ticket
    {
        return DB::transaction(function () use ($data, $authorType, $authorId, $branchId) {
            $customer = null;
            $resellerId = null;

            if ($authorType === 'customer') {
                $customer = Customer::where('branch_id', $branchId)->findOrFail($authorId);
            } elseif ($authorType === 'reseller') {
                $resellerId = $authorId;
                if (! empty($data['customer_id'])) {
                    $customer = Customer::where('reseller_id', $authorId)->findOrFail($data['customer_id']);
                }
            } else {
                if (! empty($data['customer_id'])) {
                    $customer = Customer::where('branch_id', $branchId)->findOrFail($data['customer_id']);
                } elseif (! empty($data['reseller_id'])) {
                    $resellerId = Reseller::where('branch_id', $branchId)->findOrFail($data['reseller_id'])->id;
                } else {
                    throw new RuntimeException('Select the customer or reseller this ticket is for.');
                }
            }
            if ($customer) {
                $resellerId = $customer->reseller_id;
            }

            $connectionId = null;
            if (! empty($data['connection_id'])) {
                if (! $customer) {
                    throw new RuntimeException('A connection can only be linked to a customer ticket.');
                }
                $connectionId = Connection::where('customer_id', $customer->id)->findOrFail($data['connection_id'])->id;
            }

            $ticket = Ticket::create([
                'ticket_no' => SequenceService::next($branchId, 'ticket', 'TKT'),
                'subject' => mb_substr($data['subject'], 0, 255),
                'category' => in_array($data['category'] ?? '', Ticket::CATEGORIES, true) ? $data['category'] : 'other',
                'priority' => in_array($data['priority'] ?? '', Ticket::PRIORITIES, true) ? $data['priority'] : 'normal',
                'status' => 'open',
                'opened_by_type' => $authorType,
                'opened_by_id' => $authorId,
                'customer_id' => $customer?->id,
                'reseller_id' => $resellerId,
                'connection_id' => $connectionId,
                'branch_id' => $branchId,
            ]);
            self::addReply($ticket, $authorType, $authorId, $data['message'], $data['attachment'] ?? null, false);
            AuditLogger::log('ticket.opened', $ticket, null, $ticket->only(['ticket_no', 'subject', 'customer_id', 'reseller_id', 'priority']), null, $branchId);
            return $ticket;
        });
    }

    public static function reply(Ticket $ticket, string $authorType, int $authorId, string $message, ?string $attachment = null, bool $internal = false): TicketReply
    {
        return DB::transaction(function () use ($ticket, $authorType, $authorId, $message, $attachment, $internal) {
            $ticket = Ticket::lockForUpdate()->findOrFail($ticket->id);
            if ($ticket->status === 'closed') {
                throw new RuntimeException('This ticket is closed. Open a new ticket instead.');
            }
            if ($internal && $authorType !== 'admin') {
                throw new RuntimeException('Only company staff can add internal notes.');
            }
            $reply = self::addReply($ticket, $authorType, $authorId, $message, $attachment, $internal);

            if (! $internal) {
                if (self::isRequester($ticket, $authorType)) {
                    if (in_array($ticket->status, ['resolved', 'waiting'], true)) {
                        $ticket->status = 'open';
                        $ticket->resolved_at = null;
                    }
                } elseif ($ticket->status === 'open') {
                    $ticket->status = 'in_progress';
                }
                $ticket->save();
            }
            return $reply;
        });
    }

    // $authorType decides what is allowed: admin anything; support-side reseller anything
    // but reopening a closed ticket; the requester may only resolve or close their own ticket.
    public static function setStatus(Ticket $ticket, string $status, string $authorType, int $authorId): Ticket
    {
        return DB::transaction(function () use ($ticket, $status, $authorType, $authorId) {
            $ticket = Ticket::lockForUpdate()->findOrFail($ticket->id);
            if (! in_array($status, Ticket::STATUSES, true)) {
                throw new RuntimeException('Unknown status.');
            }
            if ($ticket->status === $status) {
                return $ticket;
            }
            if ($ticket->status === 'closed' && $authorType !== 'admin') {
                throw new RuntimeException('This ticket is closed.');
            }
            if ($authorType !== 'admin' && self::isRequester($ticket, $authorType) && ! in_array($status, ['resolved', 'closed'], true)) {
                throw new RuntimeException('You can only mark your ticket as resolved or close it.');
            }

            $old = $ticket->status;
            $ticket->status = $status;
            $ticket->resolved_at = $status === 'resolved' ? now() : ($status === 'closed' ? ($ticket->resolved_at ?? now()) : null);
            $ticket->closed_at = $status === 'closed' ? now() : null;
            $ticket->save();

            TicketReply::create([
                'ticket_id' => $ticket->id, 'author_type' => 'system', 'author_id' => null, 'is_internal' => false,
                'message' => 'Status changed from ' . str_replace('_', ' ', $old) . ' to ' . str_replace('_', ' ', $status) . ' by ' . self::authorName($authorType, $authorId) . '.',
            ]);
            AuditLogger::log('ticket.status', $ticket, ['status' => $old], ['status' => $status], null, $ticket->branch_id);
            return $ticket;
        });
    }

    public static function isRequester(Ticket $ticket, string $authorType): bool
    {
        return $authorType === 'customer' || ($authorType === 'reseller' && ! $ticket->customer_id);
    }

    public static function authorName(?string $type, $id): string
    {
        return match ($type) {
            'customer' => (string) (Customer::withTrashed()->whereKey($id)->value('name') ?? 'Customer'),
            'reseller' => (string) (Reseller::withTrashed()->whereKey($id)->value('name') ?? 'Reseller'),
            'admin' => (string) (DB::table('users')->where('id', $id)->value('name') ?? 'Support'),
            default => 'System',
        };
    }

    // Thread for one viewer: internal notes only for the company, author names resolved.
    public static function thread(Ticket $ticket, bool $includeInternal): array
    {
        return $ticket->replies()
            ->when(! $includeInternal, fn ($q) => $q->where('is_internal', false))
            ->get()
            ->map(fn ($r) => $r->toArray() + ['author_name' => self::authorName($r->author_type, $r->author_id)])
            ->all();
    }

    private static function addReply(Ticket $ticket, string $authorType, int $authorId, string $message, ?string $attachment, bool $internal): TicketReply
    {
        $reply = TicketReply::create([
            'ticket_id' => $ticket->id,
            'author_type' => $authorType,
            'author_id' => $authorId,
            'message' => $message,
            'attachment' => $attachment,
            'is_internal' => $internal,
            'ipAddress' => request()->ip(),
        ]);
        if (! $internal) {
            $ticket->last_reply_by_type = $authorType;
            $ticket->last_reply_at = now();
            $ticket->save();
        }
        return $reply;
    }
}
