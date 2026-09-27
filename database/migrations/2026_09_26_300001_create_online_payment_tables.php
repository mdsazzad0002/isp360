<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per gateway per branch, configured from the admin panel.
        if (! Schema::hasTable('payment_gateways')) {
            Schema::create('payment_gateways', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('gateway', 20); // bkash | nagad | rocket | sslcommerz
                $table->boolean('is_active')->default(false);
                $table->enum('mode', ['api', 'manual'])->default('manual');
                $table->boolean('sandbox')->default(true);
                // API keys / secrets, encrypted with APP_KEY (see PaymentGateway::$casts)
                $table->text('credentials')->nullable();
                // manual mode: the number customers send money to
                $table->string('manual_number', 30)->nullable();
                $table->string('manual_account_type', 20)->nullable(); // personal | agent | merchant
                $table->text('instructions')->nullable();
                // cash/bank-book account the money lands in (banks.id)
                $table->unsignedBigInteger('bank_id')->nullable();
                $table->decimal('min_amount', 14, 2)->default(10);
                $table->decimal('max_amount', 14, 2)->default(50000);
                $table->unsignedSmallInteger('sort')->default(0);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'gateway']);
            });
        }

        // Every online payment attempt: gateway checkouts and manual TrxID submissions.
        // Money only reaches the ledger when one of these is completed (-> customer_payment_id).
        if (! Schema::hasTable('online_payments')) {
            Schema::create('online_payments', function (Blueprint $table) {
                $table->id();
                $table->string('ref', 30)->unique(); // our id, sent to the gateway as invoice / order / tran id
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('customer_id')->index();
                $table->string('gateway', 20)->index();
                $table->enum('mode', ['api', 'manual']);
                $table->enum('purpose', ['bill', 'wallet'])->default('bill');
                $table->decimal('amount', 14, 2);
                $table->enum('status', ['initiated', 'pending_review', 'completed', 'failed', 'cancelled', 'rejected'])->default('initiated')->index();
                $table->string('gateway_payment_id')->nullable()->index();
                $table->string('trx_id')->nullable()->index();
                $table->string('sender_number', 30)->nullable();
                $table->json('payload')->nullable();
                $table->string('failure_reason')->nullable();
                $table->unsignedBigInteger('customer_payment_id')->nullable()->index();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('ipAddress', 45)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payments');
        Schema::dropIfExists('payment_gateways');
    }
};
