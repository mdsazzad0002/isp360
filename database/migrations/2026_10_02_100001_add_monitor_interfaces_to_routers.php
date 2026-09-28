<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Router live monitor (roadmap 4.6, monitoring step 1): the interfaces pinned on a router (WAN /
// uplink), listed first and graphed when the monitor opens; the history collector reads them too.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('routers', 'monitor_interfaces')) {
            Schema::table('routers', function (Blueprint $table) {
                $table->json('monitor_interfaces')->nullable()->after('last_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('routers', 'monitor_interfaces')) {
            Schema::table('routers', function (Blueprint $table) {
                $table->dropColumn('monitor_interfaces');
            });
        }
    }
};
