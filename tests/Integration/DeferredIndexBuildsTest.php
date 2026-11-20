<?php

namespace Multiapi\LaravelAuroraDsql\Tests\Integration;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Multiapi\LaravelAuroraDsql\DsqlServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * `migrate` building a run's indexes together, against a live cluster or an emulator. Requires DSQL_HOST, as
 * SchemaBuilderTest does. Each test passes on a first install and on a deploy (other migrations already recorded).
 */
class DeferredIndexBuildsTest extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('DSQL_HOST')) {
            $this->markTestSkipped('DSQL_HOST is not set.');
        }

        parent::setUp();

        $this->migrator()->setConnection('dsql');

        if (! $this->migrator()->repositoryExists()) {
            $this->migrator()->getRepository()->createRepository();
        }
    }

    protected function getPackageProviders($app)
    {
        return [DsqlServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.connections.dsql', [
            'driver' => 'dsql',
            'host' => getenv('DSQL_HOST'),
            'port' => getenv('DSQL_PORT') ?: 5432,
            'region' => getenv('AWS_REGION') ?: 'us-east-1',
            'username' => 'admin',
            'database' => 'postgres',
            'profile' => getenv('DSQL_PROFILE') ?: null,
            'sslrootcert' => getenv('DSQL_SSLROOTCERT') ?: null,
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            Schema::connection('dsql')->dropIfExists('it_deferred');
            Schema::connection('dsql')->dropIfExists('it_deferred_dup');
            DB::connection('dsql')->table('migrations')->where('migration', 'like', '%create_it_deferred%')->delete();
        }

        parent::tearDown();
    }

    private function migrator(): Migrator
    {
        return $this->app->make('migrator');
    }

    #[Test]
    public function a_migrate_run_builds_its_indexes_together_and_leaves_them_valid(): void
    {
        $this->migrator()->run([__DIR__.'/deferred/ok']);

        $indexes = DB::connection('dsql')->select(
            'select c.relname, i.indisvalid, i.indisunique from pg_index i join pg_class c on c.oid = i.indexrelid '
            ."where c.relname like 'it\\_deferred\\_%' and not i.indisprimary order by c.relname"
        );

        $this->assertSame(
            ['it_deferred_code_unique', 'it_deferred_kind_code_index', 'it_deferred_kind_index'],
            array_column($indexes, 'relname')
        );
        $this->assertSame([true, true, true], array_map(fn ($index) => (bool) $index->indisvalid, $indexes));

        // The upsert found its unique index: one row, updated.
        $this->assertSame([5], DB::connection('dsql')->table('it_deferred')->pluck('qty')->map(fn ($qty) => (int) $qty)->all());
        $this->assertContains('2026_01_02_000000_create_it_deferred', $this->migrator()->getRepository()->getRan());
        $this->assertFalse(DB::connection('dsql')->indexBuilds()->active());
    }

    #[Test]
    public function a_unique_index_over_duplicates_fails_the_run_with_the_job(): void
    {
        try {
            $this->migrator()->run([__DIR__.'/deferred/duplicates']);
            $this->fail('The run should have failed: the unique index cannot be built.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Aurora DSQL index builds failed', $e->getMessage());
            $this->assertStringContainsString('it_deferred_dup_code_unique', $e->getMessage());
        }

        $this->assertFalse(DB::connection('dsql')->indexBuilds()->active());
    }
}
