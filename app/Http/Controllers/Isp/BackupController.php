<?php

namespace App\Http\Controllers\Isp;

use App\Services\Isp\AuditLogger;
use App\Services\Isp\BackupService;
use Illuminate\Http\Request;

// Backups page: the archives isp:backup keeps in storage/app/backups, "Back up now" and download.
// An archive holds every customer's data, so every download is audited. Restoring stays on the
// command line (isp:restore), where it can put the site in maintenance mode.
class BackupController extends IspController
{
    public function create()
    {
        return $this->page('backup', 'Isp/Backup');
    }

    public function index()
    {
        if ($r = $this->deny('backup')) return $r;
        return response()->json([
            'backups' => BackupService::list(),
            'schedule' => config('isp.backup'),
        ]);
    }

    public function store()
    {
        if ($r = $this->deny('backup')) return $r;
        @set_time_limit(1800);
        try {
            $archive = BackupService::create();
            BackupService::prune((int) config('isp.backup.keep'));
            AuditLogger::log('backup.created', null, null, ['file' => basename($archive), 'size' => filesize($archive)]);
            return $this->ok('Backup written: ' . basename($archive));
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function download(Request $request, string $name)
    {
        if (! checkAccess('backup')) abort(403);
        $path = BackupService::directory() . '/' . basename($name);
        abort_unless(preg_match('/^[\w.-]+\.tar\.gz$/', $name) && is_file($path), 404);
        AuditLogger::log('backup.downloaded', null, null, ['file' => basename($path)]);
        return response()->download($path);
    }
}
