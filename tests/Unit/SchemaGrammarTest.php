<?php

namespace Multiapi\LaravelAuroraDsql\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Multiapi\LaravelAuroraDsql\Database\DsqlConnection;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class SchemaGrammarTest extends TestCase
{
    private DsqlConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        // Lazy PDO that is never resolved: pretend() never touches the network.
        $this->connection = new DsqlConnection(
            fn () => throw new RuntimeException('no PDO in unit tests'),
            'postgres', '', ['name' => 'dsql', 'prefix_indexes' => true]
        );
        $this->connection->useDefaultSchemaGrammar();
    }

    /** @return list<string> */
    private function sql(callable $schema): array
    {
        return array_column(
            $this->connection->pretend(fn () => $schema($this->connection->getSchemaBuilder())),
            'query'
        );
    }

    #[Test]
    public function uuid_primary_key_is_inlined_in_create_table(): void
    {
        $sql = $this->sql(fn ($s) => $s->create('categories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
        }));

        $this->assertSame(
            ['create table "categories" ("id" uuid not null, "name" varchar(255) not null, primary key ("id"))'],
            $sql
        );
    }

    #[Test]
    public function composite_primary_key_is_inlined(): void
    {
        $sql = $this->sql(fn ($s) => $s->create('pivot', function (Blueprint $t) {
            $t->uuid('a_id');
            $t->uuid('b_id');
            $t->primary(['a_id', 'b_id']);
        }));

        $this->assertSame(['create table "pivot" ("a_id" uuid not null, "b_id" uuid not null, primary key ("a_id", "b_id"))'], $sql);
    }

    #[Test]
    public function foreign_keys_are_inlined_and_indexes_are_async(): void
    {
        $sql = $this->sql(fn ($s) => $s->create('products', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('category_id')->constrained()->cascadeOnDelete();
            $t->string('sku')->unique();
            $t->index(['category_id', 'sku']);
        }));

        $this->assertSame([
            'create table "products" ("id" uuid not null, "category_id" uuid not null, "sku" varchar(255) not null, '
                .'primary key ("id"), constraint "products_category_id_foreign" foreign key ("category_id") '
                .'references "categories" ("id") on delete cascade, constraint "products_sku_unique" unique ("sku"))',
            'create index async "products_category_id_sku_index" on "products" ("category_id", "sku")',
        ], $sql);
    }

    #[Test]
    public function uniques_declared_in_create_are_inline_table_constraints(): void
    {
        $sql = $this->sql(fn ($s) => $s->create('clusters', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('team_id')->nullable();
            $t->string('name');
            $t->string('slug')->unique();
            $t->unique(['team_id', 'name']);
            $t->unique(['team_id', 'slug'])->nullsNotDistinct();
        }));

        $this->assertSame([
            'create table "clusters" ("id" uuid not null, "team_id" uuid null, "name" varchar(255) not null, '
                .'"slug" varchar(255) not null, primary key ("id"), '
                .'constraint "clusters_team_id_name_unique" unique ("team_id", "name"), '
                .'constraint "clusters_team_id_slug_unique" unique nulls not distinct ("team_id", "slug"), '
                .'constraint "clusters_slug_unique" unique ("slug"))',
        ], $sql);
    }

    #[Test]
    public function a_unique_added_to_an_existing_table_is_an_async_index(): void
    {
        $sql = $this->sql(fn ($s) => $s->table('clusters', function (Blueprint $t) {
            $t->unique(['team_id', 'name']);
        }));

        $this->assertSame(['create unique index async "clusters_team_id_name_unique" on "clusters" ("team_id", "name")'], $sql);
    }

    #[Test]
    public function a_unique_with_an_index_method_stays_an_async_index_on_create(): void
    {
        $sql = $this->sql(fn ($s) => $s->create('t', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('a');
            $t->unique('a', null, 'btree');
        }));

        $this->assertSame([
            'create table "t" ("id" uuid not null, "a" varchar(255) not null, primary key ("id"))',
            'create unique index async "t_a_unique" on "t" using btree ("a")',
        ], $sql);
    }

    #[Test]
    public function drop_unique_and_drop_index_compile_to_drop_index(): void
    {
        $sql = $this->sql(fn ($s) => $s->table('clusters', function (Blueprint $t) {
            $t->dropUnique(['team_id', 'name']);
            $t->dropIndex('clusters_status_index');
        }));

        // The connection turns it into DROP CONSTRAINT when the name is a unique constraint (DropUniqueTest).
        $this->assertSame(['drop index "clusters_team_id_name_unique"', 'drop index "clusters_status_index"'], $sql);
    }

    #[Test]
    public function auto_increment_id_is_bigint_identity_with_cache(): void
    {
        $sql = $this->sql(fn ($s) => $s->create('migrations', function (Blueprint $t) {
            $t->increments('id');
            $t->string('migration');
        }));

        $this->assertSame([
            'create table "migrations" ("id" bigint generated by default as identity (cache 65536) not null primary key, '
                .'"migration" varchar(255) not null)',
        ], $sql);
    }

    #[Test]
    public function adding_foreign_key_to_existing_table_throws_clear_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FOREIGN KEY to an existing table');

        $this->sql(fn ($s) => $s->table('products', fn (Blueprint $t) => $t->foreignUuid('other_id')->constrained('categories')));
    }

    #[Test]
    public function adding_primary_key_to_existing_table_throws_clear_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PRIMARY KEY to an existing table');

        $this->sql(fn ($s) => $s->table('products', fn (Blueprint $t) => $t->primary('id')));
    }

    #[Test]
    public function adding_nullable_column_to_existing_table(): void
    {
        $sql = $this->sql(fn ($s) => $s->table('products', fn (Blueprint $t) => $t->string('notes')->nullable()));

        $this->assertSame(['alter table "products" add column "notes" varchar(255) null'], $sql);
    }

    #[Test]
    public function schema_transactions_are_disabled(): void
    {
        $this->assertFalse($this->connection->getSchemaGrammar()->supportsSchemaTransactions());
    }

    #[Test]
    public function table_listing_does_not_use_pg_total_relation_size(): void
    {
        $sql = $this->connection->getSchemaGrammar()->compileTables('public');

        $this->assertStringNotContainsString('pg_total_relation_size', $sql);
        $this->assertStringContainsString('null as size', $sql);
    }
}
