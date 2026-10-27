<?php

namespace App\Services\Isp;

use App\Models\Connection;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Onu;
use App\Models\Ticket;
use App\Models\WorkOrder;
use App\Models\WorkOrderPhoto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

// Work orders: a job for a technician at a customer (installation, shifting, repair, disconnection,
// equipment pickup). Finishing one takes the materials used out of the technician's stock, brings
// recovered equipment back into it, links the ONU, and notes the result on the ticket it came from.
class WorkOrderService
{
    public static function create(array $data, int $branchId, ?int $userId): WorkOrder
    {
        return DB::transaction(function () use ($data, $branchId, $userId) {
            $connection = ! empty($data['connection_id']) ? Connection::where('branch_id', $branchId)->findOrFail($data['connection_id']) : null;
            $ticket = ! empty($data['ticket_id']) ? Ticket::where('branch_id', $branchId)->findOrFail($data['ticket_id']) : null;
            $customerId = $data['customer_id'] ?? $connection?->customer_id ?? $ticket?->customer_id;
            $customer = $customerId ? Customer::where('branch_id', $branchId)->findOrFail($customerId) : null;
            if (! $customer) {
                throw new RuntimeException('Pick the customer (or connection) the job is for.');
            }
            if ($connection && $connection->customer_id !== $customer->id) {
                throw new RuntimeException('That connection belongs to another customer.');
            }
            $type = array_key_exists($data['type'] ?? '', WorkOrder::TYPES) ? $data['type'] : 'other';
            $assigned = ! empty($data['assigned_to']) ? (int) $data['assigned_to'] : null;
            $wo = WorkOrder::create([
                'branch_id' => $branchId,
                'wo_no' => SequenceService::next($branchId, 'work_order', 'WO'),
                'type' => $type,
                'status' => $assigned ? 'assigned' : 'new',
                'priority' => in_array($data['priority'] ?? '', WorkOrder::PRIORITIES, true) ? $data['priority'] : 'normal',
                'customer_id' => $customer->id,
                'connection_id' => $connection?->id,
                'ticket_id' => $ticket?->id,
                'assigned_to' => $assigned,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'address' => mb_substr(($data['address'] ?? '') ?: self::addressOf($customer), 0, 255) ?: null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            AuditLogger::log('work_order.created', $wo, null, $wo->only(['wo_no', 'type', 'customer_id', 'connection_id', 'ticket_id', 'assigned_to']), null, $branchId);
            if ($ticket && $ticket->status !== 'closed') {
                TicketService::reply($ticket, 'admin', (int) ($userId ?? 0), "Work order {$wo->wo_no} ({$type}) opened.", null, true);
            }
            return $wo;
        });
    }

    // A new connection: an installation job, unless the branch turned that off or one is already open.
    public static function autoInstall(Connection $connection, ?int $userId): ?WorkOrder
    {
        if (! IspSettings::get($connection->branch_id, 'auto_install_work_order')
            || WorkOrder::where('connection_id', $connection->id)->where('type', 'installation')->whereIn('status', WorkOrder::OPEN)->exists()) {
            return null;
        }
        return self::create(['type' => 'installation', 'connection_id' => $connection->id, 'notes' => 'New connection ' . $connection->code . ($connection->package ? " — {$connection->package->name}" : '')], $connection->branch_id, $userId);
    }

    public static function assign(WorkOrder $wo, ?int $userId, ?string $scheduledAt, ?int $by): WorkOrder
    {
        self::open($wo);
        $old = $wo->only(['assigned_to', 'scheduled_at']);
        $wo->assigned_to = $userId;
        $wo->scheduled_at = $scheduledAt ?: $wo->scheduled_at;
        if ($wo->status !== 'in_progress') {
            $wo->status = $userId ? 'assigned' : 'new';
        }
        $wo->save();
        AuditLogger::log('work_order.assigned', $wo, $old, $wo->only(['assigned_to', 'scheduled_at']), null, $wo->branch_id);
        return $wo;
    }

    public static function start(WorkOrder $wo, int $userId): WorkOrder
    {
        self::open($wo);
        $wo->update(['status' => 'in_progress', 'started_at' => $wo->started_at ?? now(), 'assigned_to' => $wo->assigned_to ?? $userId]);
        AuditLogger::log('work_order.started', $wo, null, null, null, $wo->branch_id);
        return $wo;
    }

    /**
     * Finishes the job. $data: result, onu_serial, signature (PNG data URL), materials and recovered:
     * lists of ['item_id', 'qty', 'serials' => []]. Materials leave the technician's stock for the
     * customer's connection; recovered equipment comes back to the technician.
     */
    public static function complete(WorkOrder $wo, array $data, int $userId): WorkOrder
    {
        self::open($wo);
        return DB::transaction(function () use ($wo, $data, $userId) {
            $tech = InventoryService::tech($wo->assigned_to ?: $userId);
            $site = $wo->connection_id ? InventoryService::conn($wo->connection_id) : 'out';
            $serial = strtoupper(preg_replace('/[^A-Za-z0-9:\-]/', '', (string) ($data['onu_serial'] ?? ''))) ?: null;

            $usedSerials = [];
            foreach ($data['materials'] ?? [] as $m) {
                $item = InventoryItem::where('branch_id', $wo->branch_id)->findOrFail($m['item_id']);
                $usedSerials = array_merge($usedSerials, array_map('strtoupper', $m['serials'] ?? []));
                InventoryService::move($item, 'install', $tech, $site, (float) ($m['qty'] ?? 0), $m['serials'] ?? [], ['work_order_id' => $wo->id, 'note' => $wo->wo_no], $userId);
            }
            foreach ($data['recovered'] ?? [] as $m) {
                if (! $wo->connection_id) {
                    throw new RuntimeException('Equipment can only be recovered from a connection.');
                }
                $item = InventoryItem::where('branch_id', $wo->branch_id)->findOrFail($m['item_id']);
                InventoryService::move($item, 'recover', $site, $tech, (float) ($m['qty'] ?? 0), $m['serials'] ?? [], ['work_order_id' => $wo->id, 'note' => $wo->wo_no], $userId);
            }

            // the ONU put in: in stock it goes to the customer; on the OLT list it gets linked
            if ($serial && $wo->connection_id && ! in_array($serial, $usedSerials, true)) {
                $unit = \App\Models\InventorySerial::where('branch_id', $wo->branch_id)->where('serial', $serial)->first();
                if ($unit && ($unit->location === $tech || $unit->location === 'store')) {
                    InventoryService::move(InventoryItem::findOrFail($unit->item_id), 'install', $unit->location, $site, 1, [$serial], ['work_order_id' => $wo->id, 'note' => $wo->wo_no], $userId);
                }
            }
            if ($serial && $wo->connection_id) {
                $onu = Onu::where('branch_id', $wo->branch_id)->where(fn ($q) => $q->where('serial', $serial)->orWhere('mac', strtolower($serial)))->first();
                if ($onu && ! $onu->connection_id && ! Onu::where('connection_id', $wo->connection_id)->exists()) {
                    $onu->update(['connection_id' => $wo->connection_id]);
                }
            }

            $wo->fill([
                'status' => 'done',
                'result' => $data['result'] ?? null,
                'onu_serial' => $serial,
                'signature' => ! empty($data['signature']) ? self::storeSignature($wo, $data['signature']) : $wo->signature,
                'started_at' => $wo->started_at ?? now(),
                'completed_at' => now(),
                'completed_by' => $userId,
            ])->save();
            AuditLogger::log('work_order.completed', $wo, null, $wo->only(['result', 'onu_serial']), null, $wo->branch_id);
            if ($wo->ticket_id && ($ticket = Ticket::find($wo->ticket_id)) && $ticket->status !== 'closed') {
                TicketService::reply($ticket, 'admin', $userId, "Work order {$wo->wo_no} done" . ($wo->result ? ": {$wo->result}" : '.'), null, true);
            }
            return $wo;
        });
    }

    public static function cancel(WorkOrder $wo, string $reason, int $userId): WorkOrder
    {
        self::open($wo);
        $wo->update(['status' => 'cancelled', 'result' => $reason]);
        AuditLogger::log('work_order.cancelled', $wo, null, ['reason' => $reason], null, $wo->branch_id);
        return $wo;
    }

    public static function addPhoto(WorkOrder $wo, UploadedFile $file, int $userId): WorkOrderPhoto
    {
        $path = \App\Support\Upload::toPrivate($file, "work-orders/{$wo->branch_id}", \App\Support\Upload::IMAGES);
        return WorkOrderPhoto::create(['work_order_id' => $wo->id, 'path' => $path, 'uploaded_by' => $userId]);
    }

    private static function open(WorkOrder $wo): void
    {
        if (! in_array($wo->status, WorkOrder::OPEN, true)) {
            throw new RuntimeException("Work order {$wo->wo_no} is already {$wo->status}.");
        }
    }

    // a signature drawn on the phone: a PNG data URL, checked to really be a PNG
    private static function storeSignature(WorkOrder $wo, string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            throw new RuntimeException('The signature is not a PNG image.');
        }
        $png = base64_decode($m[1], true);
        if ($png === false || strlen($png) > 300_000 || ! str_starts_with($png, "\x89PNG\r\n\x1a\n") || ! @imagecreatefromstring($png)) {
            throw new RuntimeException('The signature is not a PNG image.');
        }
        $path = "work-orders/{$wo->branch_id}/sig_" . Str::random(32) . '.png';
        Storage::disk(\App\Support\Upload::PRIVATE_DISK)->put($path, $png);
        return $path;
    }

    private static function addressOf(Customer $customer): string
    {
        $area = $customer->area_id ? \App\Models\Area::withTrashed()->find($customer->area_id)?->name : null;
        return trim(implode(', ', array_filter([$customer->address ?? null, $area])));
    }
}
