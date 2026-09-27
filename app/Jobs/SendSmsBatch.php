<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\SmsGateway;
use App\Models\SmsLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

// Up to 100 promotional SMS in one gateway call (gateways like MRAM take many numbers at once),
// trying the chosen gateways in order. Not retried, for the same reason as SendSms.
class SendSmsBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const SIZE = 100;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public int $branchId,
        public array $customerIds,
        public string $message,
        public array $gatewayIds,
        public ?int $userId = null,
        public ?string $ipAddress = null,
    ) {
        $this->onQueue('sms');
    }

    // ['sent' => n, 'failed' => n, 'error' => last gateway error]
    public function handle(): array
    {
        $gateways = SmsGateway::where('branch_id', $this->branchId)->where('is_active', true)
            ->whereIn('id', $this->gatewayIds)->orderBy('id')->get();
        $customers = Customer::where('branch_id', $this->branchId)->whereIn('id', $this->customerIds)
            ->whereNotNull('phone')->where('phone', '!=', '')->get();
        if ($customers->isEmpty()) {
            return ['sent' => 0, 'failed' => 0, 'error' => null];
        }

        $numbers = $customers->pluck('phone')->all();
        $delivered = false;
        $usedGateway = null;
        $response = 'No active SMS gateway';
        $lastError = null;
        foreach ($gateways as $gateway) {
            $result = sendSmsViaGateway($gateway, $numbers, $this->message);
            $usedGateway = $gateway;
            $response = $result['response'];
            if ($result['status']) {
                $delivered = true;
                break;
            }
            $lastError = $result['response'];
        }

        $now = Carbon::now();
        foreach ($customers as $customer) {
            SmsLog::create([
                'customer_id' => $customer->id,
                'sms_gateway_id' => $usedGateway?->id,
                'gateway_name' => $usedGateway?->name,
                'phone' => $customer->phone,
                'message' => $this->message,
                'purpose' => 'promotional',
                'is_success' => $delivered,
                'response' => $response,
                'created_by' => $this->userId,
                'created_at' => $now,
                'ipAddress' => $this->ipAddress ?? '127.0.0.1',
                'branch_id' => $this->branchId,
            ]);
        }

        return $delivered
            ? ['sent' => count($numbers), 'failed' => 0, 'error' => null]
            : ['sent' => 0, 'failed' => count($numbers), 'error' => $lastError ?? $response];
    }
}
