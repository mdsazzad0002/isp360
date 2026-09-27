<?php

namespace App\Jobs;

use App\Models\MessagingChannel;
use App\Models\NotificationLog;
use App\Support\Phone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

// One customer notification by e-mail or WhatsApp, off the web request ("sms" queue, like SMS).
// Not retried, for the same reason as SendSms; every attempt is in notification_logs.
class SendChannelMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public int $branchId,
        public string $channel,
        public string $recipient,
        public string $body,
        public ?string $subject = null,
        public ?int $customerId = null,
        public ?string $event = null,
    ) {
        $this->onQueue('sms');
    }

    public function handle(): void
    {
        $settings = MessagingChannel::active($this->branchId, $this->channel);
        [$ok, $response] = $settings ? match ($this->channel) {
            'email' => $this->email($settings),
            'whatsapp' => $this->whatsapp($settings),
            default => [false, 'Unknown channel'],
        } : [false, 'Channel is off'];

        NotificationLog::create([
            'branch_id' => $this->branchId,
            'customer_id' => $this->customerId,
            'channel' => $this->channel,
            'event' => $this->event,
            'recipient' => mb_substr($this->recipient, 0, 191),
            'subject' => $this->subject,
            'body' => $this->body,
            'is_success' => $ok,
            'response' => $response ? mb_substr($response, 0, 2000) : null,
            'created_at' => now(),
        ]);
    }

    private function email(MessagingChannel $settings): array
    {
        try {
            Mail::raw($this->body, function ($m) use ($settings) {
                $m->to($this->recipient)->subject($this->subject ?: (company()?->title ?? config('app.name')));
                if ($from = $settings->credential('from_address')) {
                    $m->from($from, $settings->credential('from_name') ?: (company()?->title ?? config('app.name')));
                }
            });
            return [true, 'sent'];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    // WhatsApp Business Cloud API: business-initiated messages must use an approved template; ours
    // has one body variable {{1}} that carries the whole message.
    private function whatsapp(MessagingChannel $settings): array
    {
        $to = Phone::e164($this->recipient);
        if (! $to) {
            return [false, 'No valid phone number'];
        }
        $res = Http::timeout(20)->withToken((string) $settings->credential('access_token'))->acceptJson()
            ->post('https://graph.facebook.com/v20.0/' . $settings->credential('phone_number_id') . '/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($to, '+'),
                'type' => 'template',
                'template' => [
                    'name' => $settings->credential('template_name'),
                    'language' => ['code' => $settings->credential('template_language') ?: 'en'],
                    'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($this->body, 0, 1024)]]]],
                ],
            ]);
        return [$res->successful() && $res->json('messages.0.id'), $res->body()];
    }
}
