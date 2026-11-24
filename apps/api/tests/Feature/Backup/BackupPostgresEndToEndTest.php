<?php

namespace Tests\Feature\Backup;

use App\Actions\Backup\CreateBackupAction;
use App\Actions\Backup\RestoreBackupAction;
use App\Actions\Backup\VerifyBackupAction;
use App\Enums\Backup\BackupRunKind;
use App\Enums\Backup\BackupRunStatus;
use App\Exceptions\Domain\BackupException;
use App\Models\BackupRun;
use App\Services\Backup\BackupService;
use App\Support\Backup\PostgresDriver;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/** U-05 / U-10 against the real pg_dump and pg_restore: skipped when the binaries or CREATEDB are not available. */
class BackupPostgresEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private PostgresDriver $driver;

    /** @var list<string> */
    private array $databases = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql' || (new ExecutableFinder)->find('pg_dump') === null || (new ExecutableFinder)->find('pg_restore') === null) {
            $this->markTestSkipped('pg_dump / pg_restore are not available.');
        }

        $this->driver = new PostgresDriver;
        try {
            $this->driver->databaseExists('postgres');
        } catch (\Throwable) {
            $this->markTestSkipped('The maintenance database is not reachable.');
        }

        $this->directory = sys_get_temp_dir().'/fl-backup-e2e-'.bin2hex(random_bytes(6));
        config(['backup.path' => $this->directory, 'backup.encryption_key' => base64_encode(random_bytes(32)), 'backup.key_tables' => ['demo_items', 'no_such_table']]);
    }

    protected function tearDown(): void
    {
        foreach ($this->databases as $name) {
            $this->driver->dropDatabase($name);
        }
        (new Filesystem)->deleteDirectory($this->directory ?? '/nonexistent');
        parent::tearDown();
    }

    private function newDatabase(string $suffix): string
    {
        $name = 'fl_u05_'.$suffix.'_'.bin2hex(random_bytes(3));
        $this->driver->createDatabase($name);

        return $this->databases[] = $name;
    }

    private function sourceWithRows(int $rows): string
    {
        $source = $this->newDatabase('src');
        $config = config('database.connections.pgsql');
        $pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $source), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('create table demo_items (id serial primary key, name text not null)');
        $pdo->exec("insert into demo_items (name) select 'item ' || g || ' ' || repeat('x', 200) from generate_series(1, {$rows}) g");

        return $source;
    }

    public function test_a_real_dump_is_encrypted_verified_and_restored_with_matching_counts(): void
    {
        $source = $this->sourceWithRows(2500);

        $manifest = app(CreateBackupAction::class)($source);
        $path = $this->directory.'/'.$manifest->file;

        $this->assertSame(['demo_items' => 2500], $manifest->tables, 'tables that do not exist are skipped');
        $this->assertStringNotContainsString('item 1 ', (string) file_get_contents($path));
        $this->assertGreaterThan(0, $manifest->plainBytes);
        app(VerifyBackupAction::class)($path);

        $target = 'fl_u05_restored_'.bin2hex(random_bytes(3));
        $this->databases[] = $target;
        $report = app(RestoreBackupAction::class)($path, $target);

        $this->assertTrue($report->allMatch());
        $this->assertSame(['demo_items' => 2500], $this->driver->countRows($target, ['demo_items']));
    }

    public function test_a_tampered_real_backup_never_creates_the_scratch_database(): void
    {
        $manifest = app(CreateBackupAction::class)($this->sourceWithRows(10));
        $path = $this->directory.'/'.$manifest->file;
        $bytes = file_get_contents($path);
        $bytes[intdiv(strlen($bytes), 2)] = chr(ord($bytes[intdiv(strlen($bytes), 2)]) ^ 1);
        file_put_contents($path, $bytes);

        $target = 'fl_u05_never_'.bin2hex(random_bytes(3));
        $this->assertThrowsException(BackupException::class, fn () => app(RestoreBackupAction::class)($path, $target));
        $this->assertFalse($this->driver->databaseExists($target));
    }

    public function test_the_service_backs_up_the_app_database_and_the_monthly_test_restores_and_drops_it(): void
    {
        config(['backup.key_tables' => ['users', 'tenants', 'audit_log']]);
        $service = app(BackupService::class);

        $service->run();
        $report = $service->restoreTest();

        $this->assertTrue($report->allMatch());
        $this->assertFalse($this->driver->databaseExists($report->database), 'the throwaway database is dropped');
        $this->assertSame(
            [BackupRunKind::Backup->value, BackupRunKind::RestoreTest->value],
            BackupRun::query()->where('status', BackupRunStatus::Succeeded)->orderBy('started_at')->pluck('kind')->map(fn ($kind) => $kind->value)->all(),
        );
    }
}
