<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Admin-configured online payment method for one branch.
class PaymentGateway extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'sandbox' => 'boolean',
        'credentials' => 'encrypted:array',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
    ];

    protected $hidden = ['credentials'];

    // gateway => [label, modes it supports, credential fields for API mode, customer_payments.method]
    public const GATEWAYS = [
        'bkash' => [
            'label' => 'bKash',
            'modes' => ['api', 'manual'],
            'fields' => ['app_key' => 'App Key', 'app_secret' => 'App Secret', 'username' => 'Username', 'password' => 'Password'],
            'secret' => ['app_secret', 'password'],
            'method' => 'bkash',
        ],
        'nagad' => [
            'label' => 'Nagad',
            'modes' => ['api', 'manual'],
            'fields' => ['merchant_id' => 'Merchant ID', 'merchant_number' => 'Merchant Number', 'merchant_private_key' => 'Merchant Private Key', 'nagad_public_key' => 'Nagad Public Key'],
            'secret' => ['merchant_private_key'],
            'method' => 'nagad',
        ],
        'rocket' => [
            'label' => 'Rocket',
            'modes' => ['manual'],
            'fields' => [],
            'secret' => [],
            'method' => 'rocket',
        ],
        'sslcommerz' => [
            'label' => 'SSLCommerz',
            'modes' => ['api'],
            'fields' => ['store_id' => 'Store ID', 'store_password' => 'Store Password'],
            'secret' => ['store_password'],
            'method' => 'gateway',
        ],
    ];

    public function bank()
    {
        return $this->belongsTo(Bank::class)->withTrashed();
    }

    public function label(): string
    {
        return self::GATEWAYS[$this->gateway]['label'] ?? $this->gateway;
    }

    public function credential(string $key): ?string
    {
        $value = ($this->credentials ?? [])[$key] ?? null;
        return $value === '' ? null : $value;
    }

    // True when every API credential is filled in.
    public function hasCredentials(): bool
    {
        foreach (array_keys(self::GATEWAYS[$this->gateway]['fields'] ?? []) as $field) {
            if (! $this->credential($field)) {
                return false;
            }
        }
        return true;
    }
}
