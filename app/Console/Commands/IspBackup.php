<?php

namespace App\Console\Commands;

use App\Services\Isp\AuditLogger;
use App\Services\Isp\BackupService;
use Illuminate\Console\Command;

// Full backup of the installation (database + uploaded and private files) into storage/app/backups.
class IspBackup extends Command
{
    protected $signature = 'isp:backup {--keep= : How many archives to keep (default config isp.backup.keep)}';

    protected $description = 'Back up the database, uploads and private files into one archive';

    public function handle(): int
    {
        try {
            $archive = BackupService::create();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            AuditLogger::log('backup.failed', null, null, ['error' => mb_substr($e->getMessage(), 0, 500)]);
            return self::FAILURE;
        }
        $removed = BackupService::prune((int) ($this->option('keep') ?: config('isp.backup.keep')));
        AuditLogger::log('backup.created', null, null, ['file' => basename($archive), 'size' => filesize($archive)]);
        $this->info('Backup written: ' . $archive . ' (' . number_format(filesize($archive) / 1048576, 1) . ' MB)' . ($removed ? ", {$removed} old archive(s) removed" : ''));
        return self::SUCCESS;
    }
}
