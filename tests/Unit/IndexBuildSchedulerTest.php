<?php

namespace Multiapi\LaravelAuroraDsql\Tests\Unit;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;
use Multiapi\LaravelAuroraDsql\Database\DsqlConnection;
use Multiapi\LaravelAuroraDsql\DsqlServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Real `migrate` runs (Laravel's Migrator and its events) against a DSQL connection that records instead of running.
 * The migrations table lives on the default SQLite connection.
 */
class IndexBuildSchedulerTest extends TestCase
{
    private const CREATE_A = 'create table "a" ("id" uuid not null, "x" varchar(255) not null, primary key ("id"))';

    private const CREATE_B = 'create table "b" ("id" uuid not null, "y" varchar(255) not null, primary key ("id"))';

    private const ADD_Z = 'alter table "a" add column "z" varchar(255) null';

    private const INDEX_A_X = 'create index async "a_x_index" on "a" ("x")';

    private const INDEX_B_Y = 'create index async "b_y_index" on "b" ("y")';

    private const UNIQUE_A_Z = 'create unique index async "a_z_unique" on "a" ("z")';

    private RecordingDsqlConnection $dsql;

    protected function getPackageProviders($app): array
    {
        return [DsqlServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.dsql', ['driver' => 'dsql']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        DsqlConnection::sleepUsing(fn (int $ms) => null);
        $this->useDsql(new RecordingDsqlConnection);
        $this->migrator()->getRepository()->createRepository();
    }

    protected function tearDown(): void
    {
        DsqlConnection::sleepUsing(null);

        parent::tearDown();
    }

    private function useDsql(RecordingDsqlConnection $connection): void
    {
        $this->dsql = $connection;
        $this->app['db']->purge('dsql');
        $this->app['db']->extend('dsql', fn () => $connection);
    }

    private function migrator(): Migrator
    {
        return $this->app->make('migrator');
    }

    /** @return list<string> the DDL the DSQL connection ran, without the waits and checks */
    private function ddl(): array
    {
        return array_values(array_filter($this->dsql->ran, fn (string $sql) => (bool) preg_match('/^(create|alter|drop)\b/', $sql)));
    }

    #[Test]
    public function a_first_install_builds_every_index_together_when_the_run_ends(): void
    {
        $this->migrator()->run([__DIR__.'/migrations/deferred']);

        $this->assertSame(
            [self::CREATE_A, self::CREATE_B, self::ADD_Z, self::INDEX_A_X, self::INDEX_B_Y, self::UNIQUE_A_Z],
            $this->ddl()
        );
        $this->assertSame([
            'call sys.wait_for_job(?) job-a_x_index',
            'call sys.wait_for_job(?) job-b_y_index',
            'call sys.wait_for_job(?) job-a_z_unique',
        ], array_values(array_filter($this->dsql->ran, fn (string $sql) => str_starts_with($sql, 'call'))));
        $this->assertFalse($this->dsql->indexBuilds()->active());
        $this->assertCount(3, $this->migrator()->getRepository()->getRan());

        // The migration on the SQLite connection ran as usual, index included.
        $this->assertTrue(Schema::hasIndex('c', ['w']));
    }

    #[Test]
    public function a_deploy_builds_each_migrations_indexes_together_before_it_is_recorded(): void
    {
        $this->migrator()->run([__DIR__.'/migrations/deferred/2026_01_01_000001_create_a.php']);

        $recordedWhenBuilt = null;
        $this->useDsql(new class($this->migrator(), $recordedWhenBuilt) extends RecordingDsqlConnection
        {
            public function __construct(private Migrator $migrator, private ?array &$recorded)
            {
                parent::__construct();
            }

            public function startIndexBuild(string $sql): ?string
            {
                $this->recorded ??= $this->migrator->getRepository()->getRan();

                return parent::startIndexBuild($sql);
            }
        });

        $this->migrator()->run([__DIR__.'/migrations/deferred']);

        $this->assertSame([self::CREATE_B, self::ADD_Z, self::INDEX_B_Y, self::UNIQUE_A_Z], $this->ddl());
        $this->assertSame(['2026_01_01_000001_create_a'], $recordedWhenBuilt);
        $this->assertCount(3, $this->migrator()->getRepository()->getRan());
    }

    #[Test]
    public function a_failed_index_build_leaves_its_migration_unrecorded_on_a_deploy(): void
    {
        $this->migrator()->run([__DIR__.'/migrations/deferred/2026_01_01_000001_create_a.php']);

        $this->useDsql(new RecordingDsqlConnection(rows: ['from pg_index' => [(object) ['relname' => 'a_z_unique']]]));

        try {
            $this->migrator()->run([__DIR__.'/migrations/deferred']);
            $this->fail('The run should have failed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Invalid indexes: ["a_z_unique"]', $e->getMessage());
        }

        $this->assertSame(['2026_01_01_000001_create_a'], $this->migrator()->getRepository()->getRan());
        $this->assertFalse($this->dsql->indexBuilds()->active());
    }

    #[Test]
    public function per_index_keeps_the_wait_after_each_index_in_migration_order(): void
    {
        $this->useDsql(new RecordingDsqlConnection(['index_builds' => 'per-index']));

        $this->migrator()->run([__DIR__.'/migrations/deferred']);

        $this->assertSame(
            [self::CREATE_A, self::INDEX_A_X, self::CREATE_B, self::INDEX_B_Y, self::ADD_Z, self::UNIQUE_A_Z],
            $this->ddl()
        );
        $this->assertSame([], array_values(array_filter($this->dsql->ran, fn (string $sql) => str_contains($sql, 'sys.jobs'))));
    }

    #[Test]
    public function pretend_prints_the_statements_in_order_and_defers_nothing(): void
    {
        $this->migrator()->run([__DIR__.'/migrations/deferred'], ['pretend' => true]);

        // The recording connection also records what a pretending connection only logs: migration order, no waits.
        $this->assertSame(
            [self::CREATE_A, self::INDEX_A_X, self::CREATE_B, self::INDEX_B_Y, self::ADD_Z, self::UNIQUE_A_Z],
            $this->dsql->ran
        );
        $this->assertFalse($this->dsql->indexBuilds()->active());
        $this->assertSame([], $this->migrator()->getRepository()->getRan());
    }

    #[Test]
    public function when_a_migration_fails_the_indexes_already_queued_are_still_built(): void
    {
        if (! class_exists('Illuminate\Database\Events\MigrationFailed')) {
            $this->markTestSkipped('This Laravel version has no MigrationFailed event: the queue is built at shutdown.');
        }

        try {
            $this->migrator()->run([__DIR__.'/migrations/failing']);
            $this->fail('The run should have failed.');
        } catch (RuntimeException $e) {
            $this->assertSame('this migration fails', $e->getMessage());
        }

        // Migration 1 is recorded as run: its index must exist. The failed migration's table got its index too.
        $this->assertSame([self::CREATE_A, self::CREATE_B, self::INDEX_A_X, self::INDEX_B_Y], $this->ddl());
        $this->assertSame(['2026_01_01_000001_create_a'], $this->migrator()->getRepository()->getRan());
        $this->assertFalse($this->dsql->indexBuilds()->active());
    }
}
