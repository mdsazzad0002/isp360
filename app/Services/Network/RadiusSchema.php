<?php

namespace App\Services\Network;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The FreeRADIUS SQL tables (its standard MySQL schema, raddb/mods-config/sql/main/mysql/schema.sql) on
// the "radius" connection. Only missing tables are created, so an existing FreeRADIUS database is used
// as it is. Run by the migrations, and from Network → RADIUS Setup after RADIUS_DB_* points elsewhere.
class RadiusSchema
{
    public const TABLES = ['radcheck', 'radreply', 'radgroupcheck', 'radgroupreply', 'radusergroup', 'radacct', 'radpostauth', 'nas'];

    // tables that are not there yet
    public static function missing(): array
    {
        $radius = Schema::connection('radius');
        return array_values(array_filter(self::TABLES, fn ($t) => ! $radius->hasTable($t)));
    }

    // creates the missing tables; returns their names
    public static function ensure(): array
    {
        $missing = self::missing();
        $radius = Schema::connection('radius');
        if (! $radius->hasTable('radcheck')) {
            $radius->create('radcheck', function (Blueprint $t) {
                $t->increments('id');
                $t->string('username', 64)->default('')->index();
                $t->string('attribute', 64)->default('');
                $t->char('op', 2)->default('==');
                $t->string('value', 253)->default('');
            });
        }
        if (! $radius->hasTable('radreply')) {
            $radius->create('radreply', function (Blueprint $t) {
                $t->increments('id');
                $t->string('username', 64)->default('')->index();
                $t->string('attribute', 64)->default('');
                $t->char('op', 2)->default('=');
                $t->string('value', 253)->default('');
            });
        }
        if (! $radius->hasTable('radgroupcheck')) {
            $radius->create('radgroupcheck', function (Blueprint $t) {
                $t->increments('id');
                $t->string('groupname', 64)->default('')->index();
                $t->string('attribute', 64)->default('');
                $t->char('op', 2)->default('==');
                $t->string('value', 253)->default('');
            });
        }
        if (! $radius->hasTable('radgroupreply')) {
            $radius->create('radgroupreply', function (Blueprint $t) {
                $t->increments('id');
                $t->string('groupname', 64)->default('')->index();
                $t->string('attribute', 64)->default('');
                $t->char('op', 2)->default('=');
                $t->string('value', 253)->default('');
            });
        }
        if (! $radius->hasTable('radusergroup')) {
            $radius->create('radusergroup', function (Blueprint $t) {
                $t->increments('id');
                $t->string('username', 64)->default('')->index();
                $t->string('groupname', 64)->default('');
                $t->integer('priority')->default(1);
            });
        }
        if (! $radius->hasTable('radacct')) {
            $radius->create('radacct', function (Blueprint $t) {
                $t->bigIncrements('radacctid');
                $t->string('acctsessionid', 64)->default('')->index();
                $t->string('acctuniqueid', 32)->default('')->unique();
                $t->string('username', 64)->default('')->index();
                $t->string('realm', 64)->nullable()->default('');
                $t->string('nasipaddress', 15)->default('')->index();
                $t->string('nasportid', 32)->nullable();
                $t->string('nasporttype', 32)->nullable();
                $t->dateTime('acctstarttime')->nullable()->index();
                $t->dateTime('acctupdatetime')->nullable();
                $t->dateTime('acctstoptime')->nullable()->index();
                $t->integer('acctinterval')->nullable();
                $t->unsignedInteger('acctsessiontime')->nullable();
                $t->string('acctauthentic', 32)->nullable();
                $t->string('connectinfo_start', 128)->nullable();
                $t->string('connectinfo_stop', 128)->nullable();
                $t->bigInteger('acctinputoctets')->nullable();
                $t->bigInteger('acctoutputoctets')->nullable();
                $t->string('calledstationid', 50)->default('');
                $t->string('callingstationid', 50)->default('');
                $t->string('acctterminatecause', 32)->default('');
                $t->string('servicetype', 32)->nullable();
                $t->string('framedprotocol', 32)->nullable();
                $t->string('framedipaddress', 15)->default('')->index();
                $t->string('framedipv6address', 45)->default('');
                $t->string('framedipv6prefix', 45)->default('');
                $t->string('framedinterfaceid', 44)->default('');
                $t->string('delegatedipv6prefix', 45)->default('');
                $t->string('class', 64)->nullable();
            });
        }
        if (! $radius->hasTable('radpostauth')) {
            $radius->create('radpostauth', function (Blueprint $t) {
                $t->increments('id');
                $t->string('username', 64)->default('')->index();
                $t->string('pass', 64)->default('');
                $t->string('reply', 32)->default('');
                $t->timestamp('authdate', 6)->useCurrent();
                $t->string('class', 64)->nullable();
            });
        }
        if (! $radius->hasTable('nas')) {
            $radius->create('nas', function (Blueprint $t) {
                $t->increments('id');
                $t->string('nasname', 128)->index();
                $t->string('shortname', 32)->nullable();
                $t->string('type', 30)->default('other');
                $t->integer('ports')->nullable();
                $t->string('secret', 60)->default('secret');
                $t->string('server', 64)->nullable();
                $t->string('community', 50)->nullable();
                $t->string('description', 200)->default('RADIUS Client');
            });
        }
        return $missing;
    }
}
