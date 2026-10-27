<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A ticket from a customer behind a down PON port / OLT is linked to that outage, so support knows
// the cause at once and the ticket is told when the outage recovers.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $t) {
            $t->unsignedBigInteger('network_alert_id')->nullable()->index()->after('connection_id');
        });
        Schema::table('network_alerts', function (Blueprint $t) {
            $t->timestamp('restored_notified_at')->nullable()->after('notified_count');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $t) => $t->dropColumn('network_alert_id'));
        Schema::table('network_alerts', fn (Blueprint $t) => $t->dropColumn('restored_notified_at'));
    }
};
