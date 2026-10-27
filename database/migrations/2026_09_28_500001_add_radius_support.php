<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RADIUS (FreeRADIUS SQL) next to the MikroTik API: each router/NAS picks its driver.
// The FreeRADIUS tables follow its standard MySQL schema (raddb/mods-config/sql/main/mysql/schema.sql)
// and are only created when missing, so an existing FreeRADIUS database is used as it is.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $t) {
            $t->string('driver', 20)->default('mikrotik')->after('name'); // mikrotik (REST API) | radius
            $t->string('nas_type', 20)->default('mikrotik')->after('driver'); // speed attributes: mikrotik | huawei | cisco | other
            $t->text('radius_secret')->nullable();
            $t->unsignedInteger('coa_port')->default(3799);
            $t->string('username')->nullable()->change(); // API login: MikroTik only
        });
        Schema::table('connections', function (Blueprint $t) {
            $t->string('radius_username')->nullable(); // name last written to RADIUS, to clean up after a rename
        });

        // FreeRADIUS tables (only those missing): App\Services\Network\RadiusSchema
        \App\Services\Network\RadiusSchema::ensure();
    }

    public function down(): void
    {
        Schema::table('routers', fn (Blueprint $t) => $t->dropColumn(['driver', 'nas_type', 'radius_secret', 'coa_port']));
        Schema::table('connections', fn (Blueprint $t) => $t->dropColumn('radius_username'));
        // the FreeRADIUS tables stay: FreeRADIUS may be using them
    }
};
