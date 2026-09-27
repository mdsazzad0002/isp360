<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Billing rules per market (roadmap 2.7), all off by default: grace period, notice before
// suspension, late fees. Connections remember the notice sent for their current paid time;
// invoices remember the late fees charged on them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $t) {
            // expire_at the notice was about (a new paid time needs a new notice), and when it was sent
            $t->dateTime('expiry_notice_for')->nullable();
            $t->dateTime('expiry_notice_at')->nullable();
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->unsignedSmallInteger('late_fee_count')->default(0);
            $t->decimal('late_fee_total', 18, 3)->default(0);
            $t->dateTime('last_late_fee_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('connections', fn (Blueprint $t) => $t->dropColumn(['expiry_notice_for', 'expiry_notice_at']));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['late_fee_count', 'late_fee_total', 'last_late_fee_at']));
    }
};
