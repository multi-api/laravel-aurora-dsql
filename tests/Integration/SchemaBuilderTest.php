<?php

namespace Multiapi\LaravelAuroraDsql\Tests\Integration;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Multiapi\LaravelAuroraDsql\DsqlServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ItCategory extends Model
{
    use HasUuids;

    protected $connection = 'dsql';
    protected $table = 'it_categories';
    protected $guarded = [];
}

class ItProduct extends Model
{
    use HasUuids;

    protected $connection = 'dsql';
    protected $table = 'it_products';
    protected $guarded = [];
}

/**
 * Runs against a live cluster. Requires DSQL_HOST (and DSQL_PROFILE for SSO):
 *   DSQL_HOST=xxx.dsql.us-east-1.on.aws DSQL_PROFILE=my-profile vendor/bin/phpunit --testsuite Integration
 */
class SchemaBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('DSQL_HOST')) {
            $this->markTestSkipped('DSQL_HOST is not set.');
        }

        parent::setUp();
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
            'region' => getenv('AWS_REGION') ?: 'us-east-1',
            'username' => 'admin',
            'database' => 'postgres',
            'profile' => getenv('DSQL_PROFILE') ?: null,
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            Schema::connection('dsql')->dropIfExists('it_uniques');
            Schema::connection('dsql')->dropIfExists('it_products');
            Schema::connection('dsql')->dropIfExists('it_categories');
            DB::connection('dsql')->table('migrations')->where('migration', 'like', '%create_it_tables')->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function schema_builder_migration_and_eloquent_end_to_end(): void
    {
        $this->artisan('migrate', [
            '--database' => 'dsql',
            '--path' => __DIR__.'/migrations',
            '--realpath' => true,
        ])->assertSuccessful();

        $schema = Schema::connection('dsql');
        $this->assertTrue($schema->hasTable('it_products'));

        $pk = collect($schema->getIndexes('it_products'))->firstWhere('primary', true);
        $this->assertSame('id', $pk['columns'][0]);
        $this->assertCount(1, $schema->getForeignKeys('it_products'));

        // Eloquent + HasUuids (v7) + inline default
        $cat = ItCategory::create(['name' => 'cat-'.Str::random(6)]);
        $this->assertSame('7', Str::replace('-', '', $cat->id)[12]);
        $prod = ItProduct::create(['category_id' => $cat->id, 'sku' => 'A']);
        $this->assertSame(0, (int) $prod->fresh()->price);

        // the foreign key is enforced
        try {
            ItProduct::create(['category_id' => (string) Str::uuid7(), 'sku' => 'X']);
            $this->fail('The foreign key was not enforced');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode());
        }

        // unique (ASYNC index, already built because the connection waits for the job)
        $this->expectException(UniqueConstraintViolationException::class);
        try {
            ItCategory::create(['name' => $cat->name]);
        } finally {
            // on delete cascade
            $cat->delete();
            $this->assertSame(0, ItProduct::count());
        }
    }

    #[Test]
    public function a_transaction_runs_more_than_one_statement(): void
    {
        $results = DB::connection('dsql')->transaction(fn () => [
            DB::connection('dsql')->selectOne('select ? as n', [1])->n,
            DB::connection('dsql')->selectOne('select ? as b', [true])->b,
            DB::connection('dsql')->selectOne('select 3 as n')->n,
        ]);

        // With emulated prepares the boolean reaches the server as the literal 'true', so it comes back as text.
        $this->assertSame([1, 'true', 3], array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $results));
    }

    #[Test]
    public function uniques_are_inline_on_create_async_later_and_both_drop(): void
    {
        $schema = Schema::connection('dsql');
        $db = DB::connection('dsql');

        $schema->create('it_uniques', function ($t) {
            $t->uuid('id')->primary();
            $t->uuid('team_id')->nullable();
            $t->string('name');
            $t->string('slug');
            $t->unique(['team_id', 'name']);
        });

        // Inline: a valid unique constraint right after CREATE TABLE, with no build job.
        $constraint = $db->selectOne(
            "select c.contype, i.indisvalid from pg_constraint c join pg_index i on i.indexrelid = c.conindid "
            ."where c.conname = 'it_uniques_team_id_name_unique'"
        );
        $this->assertSame('u', $constraint->contype);
        $this->assertTrue((bool) $constraint->indisvalid);

        $db->table('it_uniques')->insert(['id' => (string) Str::uuid7(), 'team_id' => null, 'name' => 'a', 'slug' => 'a']);
        $db->table('it_uniques')->insert(['id' => (string) Str::uuid7(), 'team_id' => null, 'name' => 'a', 'slug' => 'b']); // nulls distinct
        $team = (string) Str::uuid7();
        $db->table('it_uniques')->insert(['id' => (string) Str::uuid7(), 'team_id' => $team, 'name' => 'a', 'slug' => 'c']);

        try {
            $db->table('it_uniques')->insert(['id' => (string) Str::uuid7(), 'team_id' => $team, 'name' => 'a', 'slug' => 'd']);
            $this->fail('The inline unique was not enforced');
        } catch (UniqueConstraintViolationException) {
        }

        // Later: an async unique index (no constraint), awaited by the connection.
        $schema->table('it_uniques', fn ($t) => $t->unique('slug'));
        $this->assertNull($db->selectOne("select 1 from pg_constraint where conname = 'it_uniques_slug_unique'"));
        $this->assertNotNull($db->selectOne("select 1 from pg_class where relname = 'it_uniques_slug_unique'"));

        // Either kind drops with either method (the cross cases: an inline constraint by dropIndex(), an async
        // index by dropUnique(); the plain ones compile to the same DROP INDEX).
        $schema->table('it_uniques', fn ($t) => $t->dropIndex('it_uniques_team_id_name_unique'));
        $schema->table('it_uniques', fn ($t) => $t->dropUnique(['slug']));

        $this->assertSame([], array_values(array_filter(
            $schema->getIndexes('it_uniques'),
            fn ($index) => ! $index['primary'],
        )));
    }
}
