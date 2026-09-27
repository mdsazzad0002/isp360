<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;

// Admin-configured online payment method for one branch.
class PaymentGateway extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'sandbox' => 'boolean',
        'credentials' => 'encrypted:array',
        'min_amount' => MoneyCast::class,
        'max_amount' => MoneyCast::class,
    ];

    protected $hidden = ['credentials'];

    // gateway => [label, modes it supports, credential fields for API mode, customer_payments.method,
    // currencies it can take ('*' = any) — a gateway is usable only when the company bills in one of them]
    public const GATEWAYS = [
        'bkash' => [
            'label' => 'bKash',
            'modes' => ['api', 'manual'],
            'fields' => ['app_key' => 'App Key', 'app_secret' => 'App Secret', 'username' => 'Username', 'password' => 'Password'],
            'secret' => ['app_secret', 'password'],
            'method' => 'bkash',
            'currencies' => ['BDT'],
        ],
        'nagad' => [
            'label' => 'Nagad',
            'modes' => ['api', 'manual'],
            'fields' => ['merchant_id' => 'Merchant ID', 'merchant_number' => 'Merchant Number', 'merchant_private_key' => 'Merchant Private Key', 'nagad_public_key' => 'Nagad Public Key'],
            'secret' => ['merchant_private_key'],
            'method' => 'nagad',
            'currencies' => ['BDT'],
        ],
        'rocket' => [
            'label' => 'Rocket',
            'modes' => ['manual'],
            'fields' => [],
            'secret' => [],
            'method' => 'rocket',
            'currencies' => ['BDT'],
        ],
        'sslcommerz' => [
            'label' => 'SSLCommerz',
            'modes' => ['api'],
            'fields' => ['store_id' => 'Store ID', 'store_password' => 'Store Password'],
            'secret' => ['store_password'],
            'method' => 'gateway',
            'currencies' => ['BDT'],
        ],
        // Stripe Checkout (hosted page: card data never reaches this server). Any company currency.
        'stripe' => [
            'label' => 'Stripe',
            'modes' => ['api'],
            'fields' => ['secret_key' => 'Secret key (sk_… or restricted rk_…)', 'webhook_secret' => 'Webhook signing secret (whsec_…)'],
            'secret' => ['secret_key', 'webhook_secret'],
            'method' => 'card',
            'currencies' => ['*'],
        ],
    ];

    public static function supportsCurrency(string $gateway, string $currency): bool
    {
        $currencies = self::GATEWAYS[$gateway]['currencies'] ?? [];
        return $currencies === ['*'] || in_array($currency, $currencies, true);
    }

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
