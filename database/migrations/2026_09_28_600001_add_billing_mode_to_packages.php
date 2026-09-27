<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Postpaid packages (roadmap 2.7): the service runs on credit and the bill falls due later;
// prepaid (the default, unchanged) needs the bill paid for the time to start.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $t) {
            $t->string('billing_mode', 10)->default('prepaid')->after('billing_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('packages', fn (Blueprint $t) => $t->dropColumn('billing_mode'));
    }
};
