<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A connection's billing status (active/suspended...) says what SHOULD be on the router;
// network_sync_status says whether the router really matches:
//   pending      a change is waiting to be pushed
//   synced       last push succeeded (and the last verify, if any, matched)
//   failed       last push failed (network_sync_error has why)
//   not_managed  nothing to push: no router for it, or a type the driver doesn't manage
//   mismatch     a live verify found the router differs (network_sync_note lists how)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            if (! Schema::hasColumn('connections', 'network_sync_status')) {
                $table->enum('network_sync_status', ['pending', 'synced', 'failed', 'not_managed', 'mismatch'])->default('pending')->after('network_sync_error')->index();
                $table->string('network_sync_note', 500)->nullable()->after('network_sync_status');
                $table->timestamp('network_checked_at')->nullable()->after('network_sync_note');
            }
        });

        // Best guess for existing rows; a sync or verify corrects it.
        DB::table('connections')->whereNotNull('network_sync_error')->update(['network_sync_status' => 'failed']);
        DB::table('connections')->whereNull('network_sync_error')->whereNotIn('connection_type', ['pppoe', 'hotspot'])
            ->update(['network_sync_status' => 'not_managed', 'network_sync_note' => 'Static IP / DHCP is not pushed to the router automatically.']);
        DB::table('connections')->whereNull('network_sync_error')->whereIn('connection_type', ['pppoe', 'hotspot'])->whereNotNull('network_synced_at')
            ->update(['network_sync_status' => 'synced']);
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['network_sync_status', 'network_sync_note', 'network_checked_at']);
        });
    }
};
