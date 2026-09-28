<?php

namespace App\Console\Commands;

use App\Services\Isp\AuditLogger;
use App\Services\Isp\BackupService;
use Illuminate\Console\Command;

// Puts a backup from isp:backup back. It overwrites the current database and files, so it asks
// first (or needs --force) and runs with the site in maintenance mode.
class IspRestore extends Command
{
    protected $signature = 'isp:restore {archive : Path, or a file name in storage/app/backups}
        {--database-only : Only the database}
        {--files-only : Only the uploaded and private files}
        {--force : Do not ask}';

    protected $description = 'Restore a backup made by isp:backup (overwrites the current data)';

    public function handle(): int
    {
        $archive = $this->argument('archive');
        if (! is_file($archive)) {
            $archive = BackupService::directory() . '/' . basename($archive);
        }
        if (! is_file($archive)) {
            $this->error('Backup not found: ' . $this->argument('archive'));
            return self::FAILURE;
        }
        try {
            $manifest = BackupService::manifest($archive);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $this->line("Backup of {$manifest['created_at']} (" . implode(', ', array_keys($manifest['databases'])) . ' + files)');
        if (! $this->option('force') && ! $this->confirm('This replaces the current data with the backup. Continue?')) {
            return self::FAILURE;
        }

        $this->callSilently('down', ['--retry' => 60]);
        try {
            $done = BackupService::restore($archive, ! $this->option('files-only'), ! $this->option('database-only'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally {
            $this->callSilently('up');
        }
        $this->callSilently('optimize:clear');
        AuditLogger::log('backup.restored', null, null, ['file' => basename($archive), 'parts' => $done]);
        $this->info('Restored: ' . implode(', ', $done) . '. Run `php artisan migrate --force` if the backup is from an older version.');
        return self::SUCCESS;
    }
}
