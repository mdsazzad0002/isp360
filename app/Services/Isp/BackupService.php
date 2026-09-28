<?php

namespace App\Services\Isp;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

// A complete backup of one installation in one file: the database (and the FreeRADIUS database when
// it is a separate one), public/uploads and the private disk (tickets, KYC). Restoring it on an empty
// server with the same APP_KEY gives back the whole installation. Archives are kept in
// storage/app/backups (only the newest `isp.backup.keep`); copy them off the server as well.
class BackupService
{
    public const MANIFEST = 'manifest.json';

    public static function directory(): string
    {
        return storage_path('app/backups');
    }

    // Folders that go into the archive, relative to the project root.
    public static function fileRoots(): array
    {
        return ['public/uploads', 'storage/app'];
    }

    // Never back up the backups, or the package's own staging areas.
    private static function excluded(): array
    {
        return ['storage/app/backups', 'storage/app/backup-staging', 'storage/app/update-staging'];
    }

    // Creates the archive and returns its path.
    public static function create(?string $name = null): string
    {
        File::ensureDirectoryExists(self::directory());
        $work = self::directory() . '/.work-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($work);
        try {
            $databases = [];
            foreach (self::databases() as $key => $connection) {
                $file = "{$key}.sql";
                self::dump($connection, "{$work}/{$file}");
                $databases[$key] = ['file' => $file, 'database' => $connection['database']];
            }
            File::put("{$work}/" . self::MANIFEST, json_encode([
                'app' => config('app.name'),
                'version' => config('subandl.version'),
                'created_at' => now()->toIso8601String(),
                'databases' => $databases,
                'files' => self::fileRoots(),
            ], JSON_PRETTY_PRINT));

            $name = $name ?: 'isp-backup-' . now()->format('Ymd-His') . '.tar.gz';
            $archive = self::directory() . '/' . basename($name);
            $args = ['tar', '-czf', $archive];
            foreach (self::excluded() as $path) {
                $args[] = '--exclude=' . $path;
            }
            $args = array_merge($args, ['-C', $work, '.', '-C', base_path()], array_filter(self::fileRoots(), fn ($p) => is_dir(base_path($p))));
            self::run($args, 1800);
            @chmod($archive, 0600);
            return $archive;
        } finally {
            File::deleteDirectory($work);
        }
    }

    // Deletes all but the newest $keep archives; returns how many were removed.
    public static function prune(int $keep): int
    {
        $archives = collect(File::glob(self::directory() . '/isp-backup-*.tar.gz'))->sortDesc()->values();
        $old = $archives->slice(max(1, $keep));
        $old->each(fn ($path) => File::delete($path));
        return $old->count();
    }

    public static function list(): array
    {
        return collect(File::glob(self::directory() . '/*.tar.gz'))->sortDesc()
            ->map(fn ($path) => ['name' => basename($path), 'size' => filesize($path), 'created_at' => date('Y-m-d H:i:s', filemtime($path))])
            ->values()->all();
    }

    // Reads the manifest without extracting everything.
    public static function manifest(string $archive): array
    {
        $out = self::run(['tar', '-xzOf', $archive, './' . self::MANIFEST]);
        $manifest = json_decode($out, true);
        if (! is_array($manifest) || empty($manifest['databases'])) {
            throw new RuntimeException('Not an isp backup (no manifest).');
        }
        return $manifest;
    }

    // Puts the archive's database(s) and files back. Overwrites the current data.
    public static function restore(string $archive, bool $database = true, bool $files = true): array
    {
        $manifest = self::manifest($archive);
        $work = self::directory() . '/.restore-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($work);
        try {
            self::run(['tar', '-xzf', $archive, '-C', $work], 1800);
            $done = [];
            if ($database) {
                $connections = self::databases();
                foreach ($manifest['databases'] as $key => $entry) {
                    if (! isset($connections[$key])) {
                        continue; // e.g. a separate RADIUS database that this server doesn't use
                    }
                    self::import($connections[$key], "{$work}/" . basename($entry['file']));
                    $done[] = "database {$key}";
                }
            }
            if ($files) {
                foreach ($manifest['files'] as $root) {
                    if (is_dir("{$work}/{$root}")) {
                        File::copyDirectory("{$work}/{$root}", base_path($root));
                        $done[] = $root;
                    }
                }
            }
            return $done;
        } finally {
            File::deleteDirectory($work);
        }
    }

    // The app database, plus FreeRADIUS when it lives in another database.
    private static function databases(): array
    {
        $main = config('database.connections.' . config('database.default'));
        $list = ['app' => $main];
        $radius = config('database.connections.radius');
        if ($radius && ($radius['database'] !== $main['database'] || $radius['host'] !== $main['host'])) {
            $list['radius'] = $radius;
        }
        return $list;
    }

    private static function dump(array $c, string $target): void
    {
        $credentials = self::credentialsFile($c);
        try {
            $process = new Process([self::binary(['mariadb-dump', 'mysqldump']), "--defaults-extra-file={$credentials}",
                '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces', '--hex-blob',
                '--result-file=' . $target, $c['database']]);
            $process->setTimeout(3600)->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Database dump failed: ' . trim($process->getErrorOutput()));
            }
        } finally {
            @unlink($credentials);
        }
    }

    private static function import(array $c, string $file): void
    {
        if (! is_file($file)) {
            throw new RuntimeException('The backup has no ' . basename($file) . '.');
        }
        $credentials = self::credentialsFile($c);
        try {
            $process = new Process([self::binary(['mariadb', 'mysql']), "--defaults-extra-file={$credentials}", $c['database']]);
            $process->setInput(fopen($file, 'r'))->setTimeout(3600)->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Database restore failed: ' . trim($process->getErrorOutput()));
            }
        } finally {
            @unlink($credentials);
        }
    }

    // The password goes in a private file, never on the command line (where `ps` shows it).
    private static function credentialsFile(array $c): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ispdb');
        chmod($path, 0600);
        $lines = ['[client]', 'user="' . addslashes((string) $c['username']) . '"', 'password="' . addslashes((string) $c['password']) . '"'];
        if (! empty($c['unix_socket'])) {
            $lines[] = 'socket="' . addslashes($c['unix_socket']) . '"';
        } else {
            $lines[] = 'host="' . addslashes((string) $c['host']) . '"';
            $lines[] = 'port=' . (int) $c['port'];
        }
        file_put_contents($path, implode("\n", $lines) . "\n");
        return $path;
    }

    private static function binary(array $names): string
    {
        foreach ($names as $name) {
            $found = trim((string) @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
            if ($found !== '') {
                return $found;
            }
        }
        throw new RuntimeException(implode(' / ', $names) . ' is not installed on this server.');
    }

    private static function run(array $command, int $timeout = 120): string
    {
        $process = new Process($command);
        $process->setTimeout($timeout)->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Command failed: ' . $command[0]);
        }
        return $process->getOutput();
    }
}
