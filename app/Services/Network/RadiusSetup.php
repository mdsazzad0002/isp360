<?php

namespace App\Services\Network;

use App\Models\CompanyProfile;
use App\Models\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Network → RADIUS Setup: checks the RADIUS side end to end and writes what has to be put on the
// FreeRADIUS server and the routers, values filled in, so nobody has to work it out by hand.
class RadiusSetup
{
    public const SELF_TEST_NAS = 'isp-billing-selftest';

    public static function status(int $branchId): array
    {
        $company = CompanyProfile::first();
        $connection = config('database.connections.radius');
        $out = [
            'database' => ['host' => $connection['host'], 'database' => $connection['database'], 'same_as_app' => $connection['host'] === config('database.connections.mysql.host') && $connection['database'] === config('database.connections.mysql.database')],
            'server' => ['host' => $company?->radius_server, 'auth_port' => (int) ($company?->radius_auth_port ?: 1812), 'self_ip' => $company?->radius_self_ip ?: '127.0.0.1'],
            'db_error' => null,
            'missing_tables' => [],
            'routers' => [],
        ];
        try {
            DB::connection('radius')->getPdo();
            $out['missing_tables'] = RadiusSchema::missing();
        } catch (\Throwable $th) {
            $out['db_error'] = $th->getMessage();
            return $out;
        }
        $db = RadiusDriver::db();
        $hasNas = ! in_array('nas', $out['missing_tables'], true);
        $hasAcct = ! in_array('radacct', $out['missing_tables'], true);
        foreach (Router::where('branch_id', $branchId)->where('driver', 'radius')->orderBy('name')->get() as $r) {
            $out['routers'][] = [
                'id' => $r->id, 'name' => $r->name, 'host' => $r->host, 'nas_type' => $r->nas_type, 'coa_port' => $r->coa_port, 'is_active' => $r->is_active,
                'has_secret' => filled($r->radius_secret),
                'registered' => $hasNas && $db->table('nas')->where('nasname', $r->host)->exists(),
                'online' => $hasAcct ? $db->table('radacct')->where('nasipaddress', $r->host)->whereNull('acctstoptime')->count() : 0,
                'last_accounting' => $hasAcct ? $db->table('radacct')->where('nasipaddress', $r->host)->max('acctupdatetime') : null,
                'commands' => self::routerCommands($r, $company?->radius_server, false),
            ];
        }
        return $out;
    }

    /**
     * A real login through FreeRADIUS, like `radtest`: a throw-away user is written to radcheck, the
     * billing server asks FreeRADIUS as a RADIUS client of its own (nas row "isp-billing-selftest"), and
     * the user is removed again. Accept = database, sql module and client list all work.
     */
    public static function selfTest(): array
    {
        $company = CompanyProfile::firstOrFail();
        if (! $company->radius_server) {
            throw new \RuntimeException('Enter the FreeRADIUS server address first.');
        }
        if (! $company->radius_self_secret) {
            $company->radius_self_secret = Str::random(24);
            $company->save();
        }
        $db = RadiusDriver::db();
        $selfIp = $company->radius_self_ip ?: '127.0.0.1';
        $existing = $db->table('nas')->where('shortname', self::SELF_TEST_NAS)->first();
        $db->table('nas')->where('shortname', self::SELF_TEST_NAS)->where('nasname', '!=', $selfIp)->delete();
        $db->table('nas')->updateOrInsert(['nasname' => $selfIp], ['shortname' => self::SELF_TEST_NAS, 'type' => 'other', 'secret' => $company->radius_self_secret, 'description' => 'ISP billing: RADIUS setup login test']);
        $clientAdded = ! $existing || $existing->nasname !== $selfIp || $existing->secret !== $company->radius_self_secret;

        $user = 'isp-selftest-' . Str::lower(Str::random(8));
        $password = Str::random(16);
        $db->table('radcheck')->insert(['username' => $user, 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => $password]);
        try {
            $server = new Router(['name' => 'FreeRADIUS', 'host' => $company->radius_server]);
            $server->radius_secret = $company->radius_self_secret;
            $result = (new RadiusClient($server, 3.0, 1))->authenticate($user, $password, (int) ($company->radius_auth_port ?: 1812), filter_var($selfIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $selfIp : null);
            return $result['accepted']
                ? ['ok' => true, 'message' => 'FreeRADIUS accepted a test login from the database: the server, its sql module and its client list work.']
                : ['ok' => false, 'message' => 'FreeRADIUS answered but rejected the test user' . ($result['message'] ? " ({$result['message']})" : '') . ': it is not reading users from this database. Check its sql module (the installer below sets it up).'];
        } catch (\Throwable $th) {
            return ['ok' => false, 'message' => $th->getMessage() . ($clientAdded
                ? ' This server was just added as a FreeRADIUS client: FreeRADIUS loads its client list when it starts, so it answers after a restart (the installer\'s watcher restarts it within a minute). Try again in a minute.'
                : ' Check that FreeRADIUS runs, UDP ' . ($company->radius_auth_port ?: 1812) . " is open to it, and that it sees this server as {$selfIp}.")];
        } finally {
            $db->table('radcheck')->where('username', $user)->delete();
            $db->table('radpostauth')->where('username', $user)->delete();
        }
    }

    // Bash script for the FreeRADIUS server: install, sql module on our database, router-list watcher.
    public static function installer(bool $withPassword): string
    {
        $c = config('database.connections.radius');
        // values go inside "double quotes" in bash
        $q = fn ($v) => str_replace(['\\', '"', '$', '`', "\n", "\r"], ['\\\\', '\\"', '\\$', '\\`', '', ''], (string) $v);
        $dbHost = $q($c['host']);
        $dbPort = $q($c['port']);
        $dbName = $q($c['database']);
        $dbUser = $q($c['username']);
        $dbPass = $q($withPassword ? $c['password'] : 'PUT-THE-DATABASE-PASSWORD-HERE');
        $app = config('app.name');
        $date = now()->toDateTimeString();

        return <<<BASH
#!/bin/bash
# FreeRADIUS for {$app} (generated {$date}).
# Run once, as root, on the FreeRADIUS server (Ubuntu / Debian):  sudo bash freeradius-setup.sh
# It installs FreeRADIUS 3, points it at the billing database, and installs a watcher that restarts
# FreeRADIUS when a router is added or changed in the billing system (it reads its router list at start).
set -euo pipefail

# The database as THIS server reaches it. If FreeRADIUS runs on another machine than the database and the
# host below is 127.0.0.1, put the database server's address here and allow this user from that machine.
DB_HOST="{$dbHost}"
DB_PORT="{$dbPort}"
DB_NAME="{$dbName}"
DB_USER="{$dbUser}"
DB_PASS="{$dbPass}"

export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q freeradius freeradius-mysql freeradius-utils default-mysql-client
RADDB=/etc/freeradius/3.0

# database login for FreeRADIUS and the watcher
cat > /etc/freeradius/isp-db.cnf <<EOF
[client]
host=\${DB_HOST}
port=\${DB_PORT}
user=\${DB_USER}
password=\${DB_PASS}
database=\${DB_NAME}
EOF
chown root:freerad /etc/freeradius/isp-db.cnf
chmod 640 /etc/freeradius/isp-db.cnf
mysql --defaults-extra-file=/etc/freeradius/isp-db.cnf -e "SELECT COUNT(*) AS radius_users FROM radcheck" \
  || { echo "Cannot read the billing database from here: fix DB_HOST / the user's access, then run again."; exit 1; }

# sql module: users, speeds and routers come from the billing database
cp -n \$RADDB/mods-available/sql \$RADDB/mods-available/sql.orig || true
cat > \$RADDB/mods-available/sql <<EOF
sql {
	dialect = "mysql"
	driver = "rlm_sql_mysql"
	mysql {
		warnings = auto
	}
	server = "\${DB_HOST}"
	port = \${DB_PORT}
	login = "\${DB_USER}"
	password = "\${DB_PASS}"
	radius_db = "\${DB_NAME}"
	acct_table1 = "radacct"
	acct_table2 = "radacct"
	postauth_table = "radpostauth"
	authcheck_table = "radcheck"
	groupcheck_table = "radgroupcheck"
	authreply_table = "radreply"
	groupreply_table = "radgroupreply"
	usergroup_table = "radusergroup"
	delete_stale_sessions = yes
	read_clients = yes
	client_table = "nas"
	group_attribute = "SQL-Group"
	pool {
		start = 2
		min = 2
		max = 16
		spare = 4
		uses = 0
		retry_delay = 30
		lifetime = 0
		idle_timeout = 60
	}
	\\\$INCLUDE \\\${modconfdir}/\\\${.:name}/main/\\\${dialect}/queries.conf
}
EOF
chown root:freerad \$RADDB/mods-available/sql
chmod 640 \$RADDB/mods-available/sql
ln -sf ../mods-available/sql \$RADDB/mods-enabled/sql

# restart FreeRADIUS whenever the billing system changes the router list
cat > /usr/local/sbin/isp-radius-nas-watch <<'EOF'
#!/bin/bash
STATE=/var/lib/isp-radius-nas.sum
SUM=\$(mysql --defaults-extra-file=/etc/freeradius/isp-db.cnf -N -e "SET SESSION group_concat_max_len=1048576; SELECT MD5(IFNULL(GROUP_CONCAT(CONCAT_WS('|',nasname,secret,type) ORDER BY nasname),'')) FROM nas") || exit 0
if [ "\$SUM" != "\$(cat \$STATE 2>/dev/null)" ]; then
  echo "\$SUM" > \$STATE
  systemctl restart freeradius
fi
EOF
chmod 700 /usr/local/sbin/isp-radius-nas-watch
echo '* * * * * root /usr/local/sbin/isp-radius-nas-watch' > /etc/cron.d/isp-radius-nas-watch

# open RADIUS to the routers when ufw is on
if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then ufw allow 1812:1813/udp; fi

freeradius -XC >/dev/null || { echo "FreeRADIUS config check failed: run 'freeradius -X' to see why."; exit 1; }
systemctl enable freeradius
systemctl restart freeradius
/usr/local/sbin/isp-radius-nas-watch || true
echo "FreeRADIUS is set up. In the billing system: Network → RADIUS Setup → Test login."
BASH;
    }

    // What to set on the router so it uses FreeRADIUS (values filled in; the secret only when $withSecret).
    public static function routerCommands(Router $router, ?string $radiusServer, bool $withSecret): array
    {
        $server = $radiusServer ?: '<FreeRADIUS server IP>';
        $secret = $withSecret ? (string) $router->radius_secret : '<RADIUS secret of this router>';
        $coa = (int) ($router->coa_port ?: 3799);
        return match ($router->nas_type) {
            'mikrotik' => ['kind' => 'script', 'lines' => [
                "/radius add service=ppp,hotspot address={$server} secret=\"{$secret}\" authentication-port=1812 accounting-port=1813 timeout=3s comment=\"ISP billing\"",
                "/radius incoming set accept=yes port={$coa}",
                '/ppp aaa set use-radius=yes accounting=yes interim-update=5m',
                '/ip hotspot profile set [find] use-radius=yes radius-accounting=yes radius-interim-update=5m',
            ]],
            'vyos' => ['kind' => 'script', 'lines' => [
                'configure',
                'set service pppoe-server authentication mode radius',
                "set service pppoe-server authentication radius server {$server} key '{$secret}'",
                'set service pppoe-server authentication radius rate-limit enable',
                "set service pppoe-server authentication radius dynamic-author server {$router->host}",
                "set service pppoe-server authentication radius dynamic-author port {$coa}",
                "set service pppoe-server authentication radius dynamic-author key '{$secret}'",
                'commit', 'save',
            ]],
            default => ['kind' => 'settings', 'lines' => [
                "RADIUS server (authentication): {$server}, UDP 1812",
                "RADIUS server (accounting): {$server}, UDP 1813, interim update every 5 minutes",
                "Shared secret: {$secret}",
                "NAS-IP-Address the router sends: {$router->host}",
                "Dynamic authorization (CoA / Disconnect) from the billing server: on, UDP {$coa}, same secret",
            ]],
        };
    }
}
