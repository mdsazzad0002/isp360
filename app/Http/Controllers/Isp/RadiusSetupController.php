<?php

namespace App\Http\Controllers\Isp;

use App\Models\CompanyProfile;
use App\Models\Router;
use App\Services\Isp\AuditLogger;
use App\Services\Network\RadiusDriver;
use App\Services\Network\RadiusSchema;
use App\Services\Network\RadiusSetup;
use Illuminate\Http\Request;

// Network → RADIUS Setup: every RADIUS check on one page, with a button for each fix.
class RadiusSetupController extends IspController
{
    public function create()
    {
        return $this->page('router', 'Isp/RadiusSetup');
    }

    public function index()
    {
        if ($r = $this->deny('router')) return $r;
        return response()->json(RadiusSetup::status($this->branchId));
    }

    public function server(Request $request)
    {
        if ($r = $this->deny('router')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'radius_server' => 'required|max:255|regex:/^[A-Za-z0-9.\-:]+$/',
            'radius_auth_port' => 'nullable|integer|min:1|max:65535',
            'radius_self_ip' => 'nullable|ip',
        ])) return $r;
        $company = CompanyProfile::firstOrFail();
        $company->fill(['radius_server' => $request->radius_server, 'radius_auth_port' => $request->radius_auth_port ?: 1812, 'radius_self_ip' => $request->radius_self_ip ?: '127.0.0.1'])->save();
        AuditLogger::log('radius.server_saved', null, null, $request->only(['radius_server', 'radius_auth_port', 'radius_self_ip']));
        return $this->ok('Saved');
    }

    public function createTables()
    {
        if ($r = $this->deny('router')) return $r;
        try {
            $created = RadiusSchema::ensure();
        } catch (\Throwable $th) {
            return send_error('RADIUS database: ' . $th->getMessage(), null, 422);
        }
        AuditLogger::log('radius.tables_created', null, null, ['tables' => $created]);
        return $this->ok($created ? 'Created ' . implode(', ', $created) : 'All tables were already there');
    }

    public function test()
    {
        if ($r = $this->deny('router')) return $r;
        try {
            $result = RadiusSetup::selfTest();
        } catch (\Throwable $th) {
            return send_error($th->getMessage(), null, 422);
        }
        return $result['ok'] ? $this->ok($result['message']) : send_error($result['message'], null, 422);
    }

    // writes every RADIUS router of the branch into FreeRADIUS's client list again
    public function registerAll()
    {
        if ($r = $this->deny('router')) return $r;
        $routers = Router::where('branch_id', $this->branchId)->where('driver', 'radius')->get();
        try {
            foreach ($routers as $router) {
                RadiusDriver::syncNas($router);
            }
        } catch (\Throwable $th) {
            return send_error('RADIUS database: ' . $th->getMessage(), null, 422);
        }
        $noSecret = $routers->filter(fn ($r) => ! $r->radius_secret)->pluck('name');
        return $this->ok("{$routers->count()} router(s) written to the client list" . ($noSecret->isNotEmpty() ? ' — no secret set on ' . $noSecret->implode(', ') : ''));
    }

    // the installer or a router's commands with the real password / secret in them (audited)
    public function reveal(Request $request)
    {
        if ($r = $this->deny('router')) return $r;
        if ($request->what === 'router') {
            $router = Router::where('branch_id', $this->branchId)->where('driver', 'radius')->findOrFail($request->id);
            AuditLogger::log('radius.secret_viewed', $router);
            return response()->json(RadiusSetup::routerCommands($router, CompanyProfile::first()?->radius_server, true));
        }
        AuditLogger::log('radius.installer_viewed', null, null, ['with_password' => true]);
        return response()->json(['script' => RadiusSetup::installer(true)]);
    }

    public function installer(Request $request)
    {
        if ($r = $this->deny('router')) return $r;
        $withPassword = $request->boolean('password');
        if ($withPassword) {
            AuditLogger::log('radius.installer_viewed', null, null, ['with_password' => true, 'download' => true]);
        }
        return response(RadiusSetup::installer($withPassword), 200, [
            'Content-Type' => 'text/x-shellscript; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="freeradius-setup.sh"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
