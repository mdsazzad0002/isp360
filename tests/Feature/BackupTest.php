<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\Isp\BackupService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

// isp:backup writes one archive with the database dump, uploads and private files; old archives
// rotate; isp:restore puts files back. (A database restore is tried by hand on a scratch database:
// in the suite it would overwrite the test database.)
class BackupTest extends TestCase
{
    private array $archives = [];
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = public_path('uploads/backup-test-' . uniqid() . '.txt');
        File::ensureDirectoryExists(dirname($this->marker));
        File::put($this->marker, 'original');
    }

    protected function tearDown(): void
    {
        File::delete($this->marker);
        foreach ($this->archives as $archive) {
            File::delete($archive);
        }
        parent::tearDown();
    }

    private function backup(string $name): string
    {
        return $this->archives[] = BackupService::create($name);
    }

    public function test_the_archive_holds_the_database_and_the_files(): void
    {
        $archive = $this->backup('isp-backup-19990101-000001.tar.gz');
        $entries = explode("\n", trim(shell_exec('tar -tzf ' . escapeshellarg($archive))));

        $this->assertContains('./app.sql', $entries);
        $this->assertContains('./manifest.json', $entries);
        $this->assertContains('public/uploads/' . basename($this->marker), $entries);
        $this->assertEmpty(preg_grep('#^storage/app/backups#', $entries), 'backups must not contain backups');

        $sql = shell_exec('tar -xzOf ' . escapeshellarg($archive) . ' ./app.sql');
        $this->assertStringContainsString('CREATE TABLE `users`', $sql);
        $this->assertSame(['app'], array_keys(BackupService::manifest($archive)['databases']));
        $this->assertSame('600', substr(sprintf('%o', fileperms($archive)), -3));
    }

    public function test_files_come_back_and_old_archives_rotate(): void
    {
        $archive = $this->backup('isp-backup-19990101-000002.tar.gz');
        File::put($this->marker, 'changed');
        $this->artisan('isp:restore', ['archive' => basename($archive), '--files-only' => true, '--force' => true])->assertSuccessful();
        $this->assertSame('original', File::get($this->marker));

        $this->backup('isp-backup-19990101-000003.tar.gz');
        $this->backup('isp-backup-19990101-000004.tar.gz');
        $before = count(File::glob(BackupService::directory() . '/isp-backup-*.tar.gz'));
        BackupService::prune($before - 2);
        $this->assertFileDoesNotExist($archive); // the oldest went first
        $this->assertCount($before - 2, File::glob(BackupService::directory() . '/isp-backup-*.tar.gz'));
    }

    public function test_restore_refuses_a_file_that_is_not_a_backup(): void
    {
        $fake = BackupService::directory() . '/not-a-backup.tar.gz';
        File::ensureDirectoryExists(dirname($fake));
        shell_exec('tar -czf ' . escapeshellarg($fake) . ' -C ' . escapeshellarg(base_path()) . ' composer.json');
        $this->archives[] = $fake;
        $this->artisan('isp:restore', ['archive' => 'not-a-backup.tar.gz', '--force' => true])->assertFailed();
    }

    public function test_the_backups_page_takes_lists_and_downloads_with_an_audit_trail(): void
    {
        $admin = User::where('role', 'Superadmin')->firstOrFail();
        $as = fn () => $this->actingAs($admin, 'web')->withSession(['branch' => Branch::firstOrFail()]);
        $as()->postJson('/isp/backup')->assertOk();
        $name = $as()->postJson('/isp/get-backups')->assertOk()->json('backups.0.name');
        $this->archives[] = BackupService::directory() . '/' . $name;

        $as()->get('/isp/backup-download/' . $name)->assertOk()->assertDownload($name);
        $this->assertTrue(AuditLog::where('action', 'backup.downloaded')->where('new_values->file', $name)->exists());
        $as()->get('/isp/backup-download/..%2F..%2F.env')->assertNotFound();
    }
}
