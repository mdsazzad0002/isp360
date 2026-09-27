<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// bonus_days: free days added once, to a connection's first paid time.
// Referrals: a new customer can name the existing customer who referred them. When the new
// customer's first service bill is paid, the referrer gets a commission as wallet credit
// (a customer payment with method 'referral': advance credit, no cash-book entry).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            if (! Schema::hasColumn('connections', 'bonus_days')) {
                $table->unsignedSmallInteger('bonus_days')->default(0)->after('discount');
            }
        });
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'referred_by_id')) {
                $table->unsignedBigInteger('referred_by_id')->nullable()->index();
            }
        });
        if (! Schema::hasTable('referral_rewards')) {
            Schema::create('referral_rewards', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('referrer_id')->index();
                $table->unsignedBigInteger('referred_id')->unique(); // one reward per new customer
                $table->unsignedBigInteger('invoice_id');
                $table->decimal('amount', 14, 2);
                $table->unsignedBigInteger('customer_payment_id');
                $table->unsignedBigInteger('branch_id')->index();
                $table->timestamp('created_at')->nullable();
            });
        }
        DB::statement("ALTER TABLE customer_payments MODIFY method ENUM('cash','bank','bkash','nagad','rocket','card','gateway','wallet','referral','other') NOT NULL DEFAULT 'cash'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE customer_payments MODIFY method ENUM('cash','bank','bkash','nagad','rocket','card','gateway','wallet','other') NOT NULL DEFAULT 'cash'");
        Schema::dropIfExists('referral_rewards');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('referred_by_id');
        });
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('bonus_days');
        });
    }
};
