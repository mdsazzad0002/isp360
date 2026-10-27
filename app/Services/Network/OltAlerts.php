<?php

namespace App\Services\Network;

use App\Models\Connection;
use App\Models\Customer;
use App\Models\NetworkAlert;
use App\Models\Olt;
use App\Models\Onu;
use App\Services\Isp\AuditLogger;
use App\Services\Isp\IspNotifier;
use App\Support\SystemAlerts;
use Illuminate\Support\Collection;

// Network alerts from OLT polls, one row per problem from start to recovery. OLT unreachable and PON
// port down also raise the admin banner + e-mail (SystemAlerts); a weak ONU is listed on the OLT page.
class OltAlerts
{
    public static function oltDown(Olt $olt, string $error): void
    {
        self::open($olt, 'olt_down', null, null, "OLT {$olt->name} ({$olt->host}) does not answer: " . mb_substr($error, 0, 150), true);
    }

    public static function oltUp(Olt $olt): void
    {
        self::close($olt, 'olt_down', null);
    }

    /**
     * After a successful poll. $before: [onu id => status] before it. A PON port is down when every ONU
     * on it (at least isp.olt.pon_down_min_onus) is now not online and at least one of them was online.
     */
    public static function afterPoll(Olt $olt, array $before): void
    {
        $min = max(1, (int) config('isp.olt.pon_down_min_onus', 2));
        $onus = Onu::where('olt_id', $olt->id)->where('status', '!=', 'not_found')->get();
        foreach ($onus->groupBy('pon_port') as $port => $list) {
            $online = $list->where('status', 'online')->count();
            $wasOnline = $list->filter(fn ($o) => ($before[$o->id] ?? null) === 'online')->count();
            if ($online === 0 && $wasOnline > 0 && $list->count() >= $min) {
                $linked = $list->whereNotNull('connection_id')->count();
                self::open($olt, 'pon_down', null, (string) $port, "PON port {$port} on {$olt->name} is down: all {$list->count()} ONU(s) offline"
                    . ($linked ? ", {$linked} customer(s) affected" : ''), true);
            } elseif ($online > 0) {
                self::close($olt, 'pon_down', (string) $port);
            }
        }

        $weak = (float) config('isp.olt.weak_rx_dbm', -27);
        foreach ($onus as $onu) {
            $label = $onu->pon_port . ($onu->onu_id !== null ? ":{$onu->onu_id}" : '');
            if ($onu->rx_power !== null && $onu->rx_power < $weak) {
                self::open($olt, 'onu_weak', $onu->id, $label, "Weak signal on ONU {$label} ({$onu->serial}{$onu->mac}): {$onu->rx_power} dBm", false);
            } elseif ($onu->rx_power !== null) {
                self::close($olt, 'onu_weak', $label, $onu->id);
            }
        }
        // ONUs no longer on the OLT can't stay weak
        NetworkAlert::where('olt_id', $olt->id)->where('kind', 'onu_weak')->whereNull('resolved_at')
            ->whereNotIn('onu_id', $onus->pluck('id'))->update(['resolved_at' => now()]);
    }

    // The open outage (OLT unreachable or PON port down) a customer's line sits behind, if any.
    public static function forCustomer(int $customerId): ?NetworkAlert
    {
        $onus = Onu::whereIn('connection_id', Connection::where('customer_id', $customerId)->pluck('id'))->get(['olt_id', 'pon_port']);
        foreach ($onus as $onu) {
            $alert = NetworkAlert::where('olt_id', $onu->olt_id)->whereNull('resolved_at')
                ->where(fn ($q) => $q->where('kind', 'olt_down')->orWhere(fn ($w) => $w->where('kind', 'pon_down')->where('subject', $onu->pon_port)))
                ->first();
            if ($alert) {
                return $alert;
            }
        }
        return null;
    }

    // An outage is over: a note on the tickets it caused, and "service restored" to the customers
    // who were told about it.
    private static function recovered(NetworkAlert $alert): void
    {
        foreach (\App\Models\Ticket::where('network_alert_id', $alert->id)->whereNotIn('status', ['resolved', 'closed'])->get() as $ticket) {
            \App\Services\Isp\TicketService::systemNote($ticket, 'The network outage behind this ticket recovered at ' . now()->format('d M Y h:i A') . '. Check with the customer and resolve.');
        }
        if ($alert->notified_at && ! $alert->restored_notified_at) {
            $place = $alert->kind === 'pon_down' ? "{$alert->olt?->name} {$alert->subject}" : (string) $alert->olt?->name;
            foreach (self::affectedConnections($alert)->groupBy('customer_id') as $list) {
                if ($customer = $list->first()->customer) {
                    IspNotifier::send($alert->branch_id, $customer, 'outage_restored', ['connection' => $list->pluck('code')->implode(', '), 'place' => $place]);
                }
            }
            $alert->update(['restored_notified_at' => now()]);
        }
    }

    // Customers on the ONUs behind an alert (an OLT outage: every ONU of that OLT)
    public static function affectedConnections(NetworkAlert $alert): Collection
    {
        $onus = Onu::where('olt_id', $alert->olt_id)->whereNotNull('connection_id')
            ->when($alert->kind === 'pon_down', fn ($q) => $q->where('pon_port', $alert->subject))
            ->when($alert->kind === 'onu_weak', fn ($q) => $q->where('id', $alert->onu_id));
        return Connection::with('customer')->whereIn('id', $onus->pluck('connection_id'))->whereIn('status', ['active', 'suspended'])->get();
    }

    // Outage notice to the affected customers (SMS template "outage"); once per alert.
    public static function notifyCustomers(NetworkAlert $alert): int
    {
        if ($alert->notified_at) {
            throw new \RuntimeException('The customers of this outage were already told on ' . $alert->notified_at->format('Y-m-d H:i') . '.');
        }
        $connections = self::affectedConnections($alert);
        $place = $alert->kind === 'pon_down' ? "{$alert->olt?->name} {$alert->subject}" : (string) $alert->olt?->name;
        $sent = 0;
        foreach ($connections->groupBy('customer_id') as $list) {
            $customer = $list->first()->customer;
            if ($customer instanceof Customer) {
                IspNotifier::send($alert->branch_id, $customer, 'outage', ['connection' => $list->pluck('code')->implode(', '), 'place' => $place]);
                $sent++;
            }
        }
        $alert->update(['notified_at' => now(), 'notified_count' => $sent]);
        AuditLogger::log('network_alert.notified', $alert, null, ['customers' => $sent]);
        return $sent;
    }

    private static function open(Olt $olt, string $kind, ?int $onuId, ?string $subject, string $message, bool $banner): void
    {
        $alert = NetworkAlert::where('olt_id', $olt->id)->where('kind', $kind)->whereNull('resolved_at')
            ->when($onuId, fn ($q) => $q->where('onu_id', $onuId), fn ($q) => $q->where('subject', $subject))->first();
        if ($alert) {
            if ($kind === 'onu_weak' && $alert->message !== $message) {
                $alert->update(['subject' => $subject, 'message' => mb_substr($message, 0, 255)]); // keeps the latest reading
            }
            return;
        }
        NetworkAlert::create(['branch_id' => $olt->branch_id, 'olt_id' => $olt->id, 'onu_id' => $onuId, 'kind' => $kind, 'subject' => $subject, 'message' => mb_substr($message, 0, 255), 'started_at' => now()]);
        if ($banner) {
            SystemAlerts::raise(self::bannerKey($olt, $kind, $subject), $message);
        }
    }

    private static function close(Olt $olt, string $kind, ?string $subject, ?int $onuId = null): void
    {
        $open = NetworkAlert::where('olt_id', $olt->id)->where('kind', $kind)->whereNull('resolved_at')
            ->when($onuId, fn ($q) => $q->where('onu_id', $onuId), fn ($q) => $q->where('subject', $subject))->get();
        foreach ($open as $alert) {
            $alert->update(['resolved_at' => now()]);
            if ($kind !== 'onu_weak') {
                self::recovered($alert);
            }
        }
        if ($open->isNotEmpty() && $kind !== 'onu_weak') {
            SystemAlerts::clear(self::bannerKey($olt, $kind, $subject));
        }
    }

    private static function bannerKey(Olt $olt, string $kind, ?string $subject): string
    {
        return "olt{$olt->id}_" . ($kind === 'olt_down' ? 'down' : 'pon_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string) $subject));
    }
}
