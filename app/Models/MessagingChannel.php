<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// E-mail or WhatsApp notifications of a branch, with its (encrypted) settings.
class MessagingChannel extends Model
{
    // channel => fields (secret ones never go back to the browser)
    public const CHANNELS = [
        'email' => ['label' => 'E-mail', 'fields' => ['from_address' => 'From address', 'from_name' => 'From name'], 'secret' => []],
        'whatsapp' => ['label' => 'WhatsApp (Cloud API)', 'fields' => ['phone_number_id' => 'Phone number ID', 'access_token' => 'Access token',
            'template_name' => 'Approved template name (one {{1}} body variable)', 'template_language' => 'Template language (e.g. en, bn)'], 'secret' => ['access_token']],
    ];

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'credentials' => 'encrypted:array'];

    protected $hidden = ['credentials'];

    public function credential(string $key): ?string
    {
        $v = ($this->credentials ?? [])[$key] ?? null;
        return $v === '' ? null : $v;
    }

    public static function active(int $branchId, string $channel): ?self
    {
        return self::where('branch_id', $branchId)->where('channel', $channel)->where('is_active', true)->first();
    }
}
