<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

// One transactional SMS (invoice, payment, suspend, reactivate) sent off the web request.
// Not retried: a gateway may have delivered before it failed to answer, and a repeat SMS is worse
// than a missed one; every attempt is in the SMS log (sendTransactionalSms).
class SendSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public int $branchId,
        public string $phone,
        public string $message,
        public ?int $customerId = null,
        public string $purpose = 'transactional',
        public ?int $userId = null,
        public ?string $ipAddress = null,
    ) {
        $this->onQueue('sms');
    }

    public function handle(): void
    {
        sendTransactionalSms($this->branchId, $this->userId, $this->phone, $this->message, $this->customerId, $this->purpose, $this->ipAddress);
    }
}
