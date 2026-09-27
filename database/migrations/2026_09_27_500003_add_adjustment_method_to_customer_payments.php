<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Package change credit: when a line moves to a cheaper package mid-period, the unused value
// of the paid days is given back as a customer payment with method 'adjustment' — advance
// credit that pays the next bills, with no cash-book entry (like 'referral').
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE customer_payments MODIFY method ENUM('cash','bank','bkash','nagad','rocket','card','gateway','wallet','referral','adjustment','other') NOT NULL DEFAULT 'cash'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE customer_payments MODIFY method ENUM('cash','bank','bkash','nagad','rocket','card','gateway','wallet','referral','other') NOT NULL DEFAULT 'cash'");
    }
};
