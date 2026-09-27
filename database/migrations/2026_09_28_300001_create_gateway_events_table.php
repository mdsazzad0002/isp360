<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Webhook / IPN events received from payment gateways, by the provider's own event id. A
// provider re-sends an event until it gets a 2xx, and may send it twice anyway: an event
// already processed is answered at once and never touches the payment again.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_events', function (Blueprint $t) {
            $t->id();
            $t->string('gateway', 20);
            $t->string('event_id', 191);
            $t->string('type', 100)->nullable();
            $t->unsignedBigInteger('online_payment_id')->nullable()->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->dateTime('processed_at')->nullable();
            $t->string('result', 255)->nullable();
            $t->json('payload')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['gateway', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_events');
    }
};
