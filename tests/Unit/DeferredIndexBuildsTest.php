<?php

namespace Multiapi\LaravelAuroraDsql\Tests\Unit;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Multiapi\LaravelAuroraDsql\Database\DeferredIndexBuilds;
use Multiapi\LaravelAuroraDsql\Database\DsqlConnection;
use Orchestra\Testbench\TestCase;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;

class DeferredIndexBuildsTest extends TestCase
{
    private const VALIDITY_CHECK = 'select c.relname from pg_index i join pg_class c on c.oid = i.indexrelid '
        .'join pg_namespace n on n.oid = c.relnamespace where not i.indisvalid and ';

    protected function setUp(): void
    {
        parent::setUp();

        DsqlConnection::sleepUsing(fn (int $ms) => null);
    }

    protected function tearDown(): void
    {
        DsqlConnection::sleepUsing(null);

        parent::tearDown();
    }

    private function deferring(string $mode, RecordingDsqlConnection $connection = new RecordingDsqlConnection): RecordingDsqlConnection
    {
        $connection->indexBuilds()->begin($mode);

        return $connection;
    }

    private function schema(DsqlConnection $connection, string $table, Closure $callback): void
    {
        $connection->getSchemaBuilder()->table($table, $callback);
    }

    #[Test]
    public function outside_a_run_each_index_is_built_and_awaited_in_turn(): void
    {
        $connection = new RecordingDsqlConnection;

        $this->schema($connection, 't', fn (Blueprint $table) => $table->index('a'));

        $this->assertSame([
            'create index async "t_a_index" on "t" ("a")',
            'call sys.wait_for_job(?) job-t_a_index',
        ], $connection->ran);
    }

    #[Test]
    public function index_builds_are_deferred_unless_the_connection_opts_out(): void
    {
        $this->assertTrue((new RecordingDsqlConnection)->defersIndexBuilds());
        $this->assertTrue((new RecordingDsqlConnection(['index_builds' => 'deferred']))->defersIndexBuilds());
        $this->assertFalse((new RecordingDsqlConnection(['index_builds' => 'per-index']))->defersIndexBuilds());
        $this->assertFalse((new RecordingDsqlConnection(['wait_for_indexes' => false]))->defersIndexBuilds());
    }

    #[Test]
    public function tables_are_created_in_order_and_their_indexes_queued(): void
    {
        $connection = $this->deferring(DeferredIndexBuilds::AT_RUN_END);

        $connection->getSchemaBuilder()->create('deployments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('environment_id');
            $table->string('status');
            $table->unsignedInteger('number');
            $table->unique(['environment_id', 'number']);
            $table->index('environment_id');
            $table->index('status');
        });
        $this->schema($connection, 'deployments', fn (Blueprint $table) => $table->string('kind')->nullable());
        $this->schema($connection, 'deployments', fn (Blueprint $table) => $table->dropIndex('deployments_status_index'));

        // The unique is inline in CREATE TABLE: nothing to queue for it. The dropped index is never built.
        $this->assertCount(2, $connection->ran);
        $this->assertStringStartsWith('create table "deployments"', $connection->ran[0]);
        $this->assertStringContainsString('constraint "deployments_environment_id_number_unique" unique ("environment_id", "number")', $connection->ran[0]);
        $this->assertSame('alter table "deployments" add column "kind" varchar(255) null', $connection->ran[1]);
        $this->assertSame(
            ['create index async "deployments_environment_id_index" on "deployments" ("environment_id")'],
            $connection->indexBuilds()->queued()
        );
    }

    #[Test]
    public function ddl_that_could_notice_a_queued_index_builds_that_tables_indexes_first(): void
    {
        $connection = $this->deferring(DeferredIndexBuilds::AT_RUN_END);

        $this->schema($connection, 'a', fn (Blueprint $table) => $table->index('x'));
        $this->schema($connection, 'b', fn (Blueprint $table) => $table->index('y'));
        $this->schema($connection, 'a', fn (Blueprint $table) => $table->dropColumn('x'));

        $this->assertSame([
            'create index async "a_x_index" on "a" ("x")',
            'call sys.wait_for_job(?) job-a_x_index',
            'select job_id, status, details from sys.jobs where job_id in (?) job-a_x_index',
            self::VALIDITY_CHECK.'((c.relname = ? and n.nspname = current_schema())) a_x_index',
            'alter table "a" drop column "x"',
        ], $connection->ran);
        $this->assertSame(['create index async "b_y_index" on "b" ("y")'], $connection->indexBuilds()->queued());
    }

    #[Test]
    public function raw_ddl_naming_the_table_unquoted_builds_its_queued_indexes_first(): void
    {
        $connection = $this->deferring(DeferredIndexBuilds::AT_RUN_END);

        $this->schema($connection, 'orders', fn (Blueprint $table) => $table->index('x'));
        $this->schema($connection, 'orders_archive', fn (Blueprint $table) => $table->index('x'));

        $connection->statement('alter table orders drop column x');

        $this->assertSame(
            ['create index async "orders_archive_x_index" on "orders_archive" ("x")'],
            $connection->indexBuilds()->queued()
        );
        $this->assertSame('alter table orders drop column x', end($connection->ran));
    }

    #[Test]
    public function a_handwritten_create_index_is_not_queued_and_is_awaited_as_always(): void
    {
        $connection = $this->deferring(DeferredIndexBuilds::AT_RUN_END);

        $connection->statement('create index async "manual" on "t" ("a")');

        $this->assertSame([], $connection->indexBuilds()->queued());
        $this->assertSame([
            'create index async "manual" on "t" ("a")',
            'call sys.wait_for_job(?) job-manual',
        ], $connection->ran);
    }

    #[Test]
    public function at_run_end_dml_runs_before_the_indexes_except_an_upsert(): void
    {
        $connection = $this->deferring(DeferredIndexBuilds::AT_RUN_END);
        $this->schema($connection, 't', fn (Blueprint $table) => $table->unique('a'));

        $connection->table('t')->insert(['a' => 1]);
        $this->assertCount(1, $connection->indexBuilds()->queued());

        $connection->table('t')->upsert(['a' => 1], ['a'], ['a']);
        $this->assertSame([], $connection->indexBuilds()->queued());
        $this->assertContains('create unique index async "t_a_unique" on "t" ("a")', $connection->ran);
    }

    #[Test]
    public function at_migration_end_any_dml_on_the_table_builds_its_queued_indexes_first(): void
    {
        $connection = $this->deferring(DeferredIndexBuilds::AT_MIGRATION_END);
        $this->schema($connection, 't', fn (Blueprint $table) => $table->unique('a'));
        $this->schema($connection, 'u', fn (Blueprint $table) => $table->index('a'));

        $connection->table('t')->where('a', 1)->update(['a' => 2]);

        $this->assertSame(['create index async "u_a_index" on "u" ("a")'], $connection->indexBuilds()->queued());
        $this->assertLessThan(
            array_search('update "t" set "a" = ? where "a" = ? 2,1', $connection->ran),
            array_search('create unique index async "t_a_unique" on "t" ("a")', $connection->ran)
        );
    }

    #[Test]
    public function at_migration_end_a_transaction_builds_the_queue_before_it_opens(): void
    {
        $connection = $this->deferring(DeferredIndexBuilds::AT_MIGRATION_END);
        $this->schema($connection, 't', fn (Blueprint $table) => $table->unique('a'));

        $connection->transaction(fn () => $connection->table('t')->insert(['a' => 1]));

        $this->assertSame([], $connection->indexBuilds()->queued());
        $this->assertSame('create unique index async "t_a_unique" on "t" ("a")', $connection->ran[0]);
        $this->assertSame('insert into "t" ("a") values (?) 1', end($connection->ran));
    }

    #[Test]
    public function an_upsert_inside_a_transaction_that_needs_a_queued_index_fails_clearly(): void
    {
        $connection = $this->deferring(DeferredIndexBuilds::AT_RUN_END);
        $this->schema($connection, 't', fn (Blueprint $table) => $table->unique('a'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot build indexes inside a transaction, and this statement needs t_a_unique first');

        $connection->transaction(fn () => $connection->table('t')->upsert(['a' => 1], ['a'], ['a']), 1);
    }

    #[Test]
    public function the_queue_is_built_at_once_retrying_oc001_then_every_job_is_awaited_and_read(): void
    {
        $connection = $this->deferring(DeferredIndexBuilds::AT_RUN_END, new RecordingDsqlConnection(conflicts: 2));
        $this->schema($connection, 't', function (Blueprint $table) {
            $table->index('a');
            $table->unique('b');
        });

        $connection->indexBuilds()->buildQueued();

        $this->assertSame([
            'create index async "t_a_index" on "t" ("a")',
            'create unique index async "t_b_unique" on "t" ("b")',
            'call sys.wait_for_job(?) job-t_a_index',
            'call sys.wait_for_job(?) job-t_b_unique',
            'select job_id, status, details from sys.jobs where job_id in (?, ?) job-t_a_index,job-t_b_unique',
            self::VALIDITY_CHECK.'((c.relname = ? and n.nspname = current_schema()) or (c.relname = ? and n.nspname = current_schema())) t_a_index,t_b_unique',
        ], $connection->ran);
        $this->assertSame([], $connection->indexBuilds()->queued());
    }

    #[Test]
    public function a_job_that_did_not_complete_fails_the_build(): void
    {
        $connection = $this->deferring(DeferredIndexBuilds::AT_MIGRATION_END, new RecordingDsqlConnection(rows: [
            'from sys.jobs' => [
                (object) ['job_id' => 'job-t_a_unique', 'status' => 'failed', 'details' => 'duplicate key value'],
                (object) ['job_id' => 'job-t_b_index', 'status' => 'completed', 'details' => null],
            ],
        ]));
        $this->schema($connection, 't', function (Blueprint $table) {
            $table->unique('a');
            $table->index('b');
        });

        try {
            $connection->indexBuilds()->buildQueued();
            $this->fail('The build should have failed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('"index":"t_a_unique","job_id":"job-t_a_unique","status":"failed","details":"duplicate key value"', $e->getMessage());
            $this->assertStringNotContainsString('t_b_index', $e->getMessage());
        }
    }

    #[Test]
    public function an_index_left_invalid_fails_the_build(): void
    {
        $connection = $this->deferring(DeferredIndexBuilds::AT_MIGRATION_END, new RecordingDsqlConnection(rows: [
            'from pg_index' => [(object) ['relname' => 't_a_unique']],
        ]));
        $this->schema($connection, 't', fn (Blueprint $table) => $table->unique('a'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid indexes: ["t_a_unique"]');

        $connection->indexBuilds()->buildQueued();
    }

    #[Test]
    public function without_sys_jobs_the_validity_check_still_decides(): void
    {
        $undefinedTable = new PDOException('relation "sys.jobs" does not exist');
        (new ReflectionProperty(PDOException::class, 'code'))->setValue($undefinedTable, '42P01');

        $connection = $this->deferring(DeferredIndexBuilds::AT_MIGRATION_END, new RecordingDsqlConnection(rows: [
            'from sys.jobs' => new QueryException('dsql', 'select', [], $undefinedTable),
        ]));
        $this->schema($connection, 't', fn (Blueprint $table) => $table->index('a'));

        $connection->indexBuilds()->buildQueued();

        $this->assertStringStartsWith(self::VALIDITY_CHECK, end($connection->ran));
    }

    #[Test]
    public function a_schema_qualified_or_prefixed_table_is_tracked_by_its_own_name(): void
    {
        $connection = $this->deferring(DeferredIndexBuilds::AT_RUN_END, new RecordingDsqlConnection(prefix: 'app_'));
        $this->schema($connection, 'tenant.users', fn (Blueprint $table) => $table->index('email', 'users_email_index'));

        // "tenant" is the schema, not the table: DDL on another table of that schema builds nothing.
        $connection->statement('alter table "tenant"."app_roles" drop column "x"');
        $this->assertCount(1, $connection->indexBuilds()->queued());

        $connection->statement('alter table "tenant"."app_users" drop column "x"');
        $this->assertSame([], $connection->indexBuilds()->queued());
        $this->assertContains(
            self::VALIDITY_CHECK.'((c.relname = ? and n.nspname = ?)) users_email_index,tenant',
            $connection->ran
        );
    }

    #[Test]
    public function two_connections_keep_separate_queues(): void
    {
        $one = $this->deferring(DeferredIndexBuilds::AT_RUN_END);
        $two = $this->deferring(DeferredIndexBuilds::AT_RUN_END);

        $this->schema($one, 't', fn (Blueprint $table) => $table->index('a'));

        $this->assertCount(1, $one->indexBuilds()->queued());
        $this->assertSame([], $two->indexBuilds()->queued());

        $two->indexBuilds()->buildQueued();
        $this->assertSame([], $two->ran);
        $this->assertCount(1, $one->indexBuilds()->queued());
    }

    #[Test]
    public function pretending_prints_every_statement_and_queues_nothing(): void
    {
        $connection = $this->deferring(DeferredIndexBuilds::AT_RUN_END);

        $sql = array_column(
            $connection->pretend(fn () => $this->schema($connection, 't', fn (Blueprint $table) => $table->index('a'))),
            'query'
        );

        $this->assertSame(['create index async "t_a_index" on "t" ("a")'], $sql);
        $this->assertSame([], $connection->indexBuilds()->queued());
    }

    #[Test]
    public function what_an_interrupted_run_left_queued_is_built_at_shutdown(): void
    {
        $connection = $this->deferring(DeferredIndexBuilds::AT_RUN_END);
        $this->schema($connection, 't', fn (Blueprint $table) => $table->index('a'));

        DeferredIndexBuilds::buildAbandoned();

        $this->assertSame('create index async "t_a_index" on "t" ("a")', $connection->ran[0]);
        $this->assertFalse($connection->indexBuilds()->active());
    }
}
