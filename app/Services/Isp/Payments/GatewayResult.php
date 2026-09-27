<?php

namespace App\Services\Isp\Payments;

// What a gateway said about a payment after the customer came back.
class GatewayResult
{
    public function __construct(
        public string $status,           // completed | failed | cancelled
        public ?string $trxId = null,
        public ?float $amount = null,
        public ?string $reason = null,
        public array $raw = [],
    ) {
    }

    public static function completed(string $trxId, float $amount, array $raw = []): self
    {
        return new self('completed', $trxId, $amount, null, $raw);
    }

    public static function failed(string $reason, array $raw = [], string $status = 'failed'): self
    {
        return new self($status, null, null, $reason, $raw);
    }
}
