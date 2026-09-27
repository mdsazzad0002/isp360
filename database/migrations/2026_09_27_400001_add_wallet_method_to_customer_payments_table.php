<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 'wallet' = a reseller paid the customer's bill out of their own wallet balance.
// Like cash a reseller collects, it has no company account and counts against the wallet.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE customer_payments MODIFY method ENUM('cash','bank','bkash','nagad','rocket','card','gateway','wallet','other') NOT NULL DEFAULT 'cash'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE customer_payments MODIFY method ENUM('cash','bank','bkash','nagad','rocket','card','gateway','other') NOT NULL DEFAULT 'cash'");
    }
};
