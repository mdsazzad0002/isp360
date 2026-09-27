<?php

namespace App\Http\Controllers\Isp;

use App\Models\MessagingChannel;
use App\Models\NotificationLog;
use App\Services\Isp\AuditLogger;
use Illuminate\Http\Request;

// E-mail and WhatsApp channels of the branch (ISP Settings) and their delivery log.
class MessagingController extends IspController
{
    public function index()
    {
        $rows = MessagingChannel::where('branch_id', $this->branchId)->get()->keyBy('channel');
        return response()->json(collect(MessagingChannel::CHANNELS)->map(function ($meta, $key) use ($rows) {
            $row = $rows->get($key);
            $values = [];
            foreach ($meta['fields'] as $field => $label) {
                $value = $row?->credential($field);
                $values[$field] = in_array($field, $meta['secret'], true) ? '' : ($value ?? '');
                $values["has_{$field}"] = (bool) $value;
            }
            return ['channel' => $key, 'label' => $meta['label'], 'fields' => $meta['fields'], 'secret' => $meta['secret'], 'is_active' => (bool) $row?->is_active, 'values' => $values];
        })->values());
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('ispSettings')) return $r;
        $meta = MessagingChannel::CHANNELS[$request->channel] ?? null;
        if (! $meta) {
            return send_error('Unknown channel', null, 422);
        }
        $row = MessagingChannel::firstOrNew(['branch_id' => $this->branchId, 'channel' => $request->channel]);
        $values = $row->credentials ?? [];
        foreach (array_keys($meta['fields']) as $field) {
            $value = trim((string) data_get($request->values, $field, ''));
            if ($value !== '' || ! in_array($field, $meta['secret'], true)) {
                $values[$field] = $value; // blank secret = keep the saved one
            }
        }
        if ($request->channel === 'email' && ! empty($values['from_address']) && ! filter_var($values['from_address'], FILTER_VALIDATE_EMAIL)) {
            return send_error('The from address is not a valid e-mail address.', null, 422);
        }
        $row->fill(['credentials' => $values, 'is_active' => $request->boolean('is_active')]);
        if ($row->is_active && $request->channel === 'whatsapp') {
            foreach (['phone_number_id', 'access_token', 'template_name'] as $f) {
                if (empty($values[$f])) {
                    return send_error("To turn WhatsApp on, fill in: {$meta['fields'][$f]}", null, 422);
                }
            }
        }
        $row->save();
        AuditLogger::log('messaging.updated', $row, null, ['channel' => $row->channel, 'is_active' => $row->is_active], null, $this->branchId);
        return $this->ok("{$meta['label']} settings saved");
    }

    public function log(Request $request)
    {
        if ($r = $this->deny('smsSetting')) return $r;
        return response()->json(NotificationLog::where('branch_id', $this->branchId)
            ->when($request->channel, fn ($q, $c) => $q->where('channel', $c))
            ->when($request->customer_id, fn ($q, $id) => $q->where('customer_id', $id))
            ->latest('id')->paginate(50));
    }
}
