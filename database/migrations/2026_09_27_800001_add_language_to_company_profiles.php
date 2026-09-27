<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The company's default UI language (resources/js/lang), set by its country pack. A user's own
// choice in the language switcher still wins.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('company_profiles', 'language')) {
                $table->string('language', 8)->default('en')->after('timezone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_profiles', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
