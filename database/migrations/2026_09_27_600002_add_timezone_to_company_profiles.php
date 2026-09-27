<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The company's timezone (IANA name). The app runs in it: every stored date and time is
// the company's wall-clock time, and prepaid expiry keeps the same local time across DST.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('company_profiles', 'timezone')) {
                $table->string('timezone', 64)->default('Asia/Dhaka')->after('currency_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
