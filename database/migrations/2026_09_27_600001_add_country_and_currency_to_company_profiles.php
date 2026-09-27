<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One installation runs one company in one country: its country and billing currency
// (config/countries.php, config/currencies.php) apply to every branch.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('company_profiles', 'country_code')) {
                $table->char('country_code', 2)->default('BD')->after('url');
            }
            if (! Schema::hasColumn('company_profiles', 'currency_code')) {
                $table->char('currency_code', 3)->default('BDT')->after('country_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn(['country_code', 'currency_code']);
        });
    }
};
