<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The customer's language for SMS (roadmap 2.9); null = the branch's default templates.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->string('language', 8)->nullable()->after('postcode'));
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('language'));
    }
};
