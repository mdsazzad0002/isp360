<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Security deposits (roadmap 2.7): refundable money the company holds for the customer (a
// liability). Outside the customer ledger, so a deposit never pays bills by itself; it is in the
// cash/bank books when received and when refunded. Applying it to dues turns it into a normal
// payment (method 'deposit').
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_deposits', function (Blueprint $t) {
            $t->id();
            $t->string('deposit_no', 40);
            $t->unsignedBigInteger('customer_id')->index();
            $t->unsignedBigInteger('connection_id')->nullable()->index();
            $t->decimal('amount', 18, 3);
            $t->decimal('refunded_amount', 18, 3)->default(0);
            $t->decimal('applied_amount', 18, 3)->default(0);
            $t->string('method', 20)->default('cash');
            $t->unsignedBigInteger('bank_id')->nullable();
            $t->date('received_date');
            $t->string('status', 20)->default('held'); // held | partially_released | released
            $t->string('notes', 255)->nullable();
            $t->unsignedBigInteger('branch_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('ipAddress', 45)->nullable();
            $t->timestamps();
            $t->unique(['branch_id', 'deposit_no']);
        });
        DB::statement("ALTER TABLE customer_payments MODIFY method ENUM('cash','bank','bkash','nagad','rocket','card','gateway','wallet','referral','adjustment','deposit','other') NOT NULL DEFAULT 'cash'");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_deposits');
        DB::statement("ALTER TABLE customer_payments MODIFY method ENUM('cash','bank','bkash','nagad','rocket','card','gateway','wallet','referral','adjustment','other') NOT NULL DEFAULT 'cash'");
    }
};
