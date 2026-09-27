<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Proration (roadmap 2.7): a first bill can cover its cycle plus the days up to the branch's
// billing day, so every line then renews on the same day of the month.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->unsignedSmallInteger('service_days')->default(0)->after('service_months');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('service_days'));
    }
};
