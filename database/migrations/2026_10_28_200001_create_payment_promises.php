<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Promise to pay (roadmap 4.3): the customer names a date; until then the line is not suspended for
// non-payment (and a suspended one comes back). Paid by then = kept; otherwise broken and the normal
// suspension follows. No money moves: the bills stay due.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_promises', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('branch_id')->index();
            $t->unsignedBigInteger('customer_id')->index();
            $t->unsignedBigInteger('connection_id')->index();
            $t->date('promised_date');
            $t->decimal('amount', 14, 2)->nullable(); // what they said they'd pay (the due may differ)
            $t->string('note', 255)->nullable();
            $t->string('status', 10)->default('open')->index(); // open | kept | broken | cancelled
            $t->boolean('reactivated')->default(false); // the promise switched a suspended line back on
            $t->timestamp('reminder_sent_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_promises');
    }
};
