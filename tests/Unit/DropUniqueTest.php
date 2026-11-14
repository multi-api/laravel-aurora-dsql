<?php

namespace Multiapi\LaravelAuroraDsql\Tests\Unit;

use Closure;
use Multiapi\LaravelAuroraDsql\Database\DsqlConnection;
use Orchestra\Testbench\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Test;

class DropUniqueTest extends TestCase
{
    /**
     * A DsqlConnection that runs nothing: it records each statement and answers the pg_constraint lookup with
     * $constraintTable (null = no such unique constraint).
     */
    private function connection(?string $constraintTable): DsqlConnection
    {
        return new class(new PDO('sqlite::memory:'), 'postgres', '', ['name' => 'dsql'], $constraintTable) extends DsqlConnection
        {
            /** @var list<array{0: string, 1: array<mixed>}> */
            public array $ran = [];

            public function __construct($pdo, $database, $prefix, $config, private ?string $constraintTable)
            {
                parent::__construct($pdo, $database, $prefix, $config);
            }

            protected function runQueryCallback($query, $bindings, Closure $callback)
            {
                $this->ran[] = [$query, $bindings];

                if (str_contains($query, 'from pg_constraint')) {
                    return $this->constraintTable === null ? [] : [(object) ['tbl' => $this->constraintTable]];
                }

                return true;
            }
        };
    }

    #[Test]
    public function a_unique_constraint_is_dropped_with_alter_table(): void
    {
        $connection = $this->connection('clusters');

        $connection->statement('drop index "clusters_team_id_name_unique"');

        $this->assertSame(['clusters_team_id_name_unique'], $connection->ran[0][1]);
        $this->assertSame('alter table clusters drop constraint "clusters_team_id_name_unique"', $connection->ran[1][0]);
    }

    #[Test]
    public function an_index_is_dropped_as_written(): void
    {
        $connection = $this->connection(null);

        $connection->statement('drop index if exists "clusters_status_index"');

        $this->assertSame(['clusters_status_index'], $connection->ran[0][1]);
        $this->assertSame('drop index if exists "clusters_status_index"', $connection->ran[1][0]);
    }

    #[Test]
    public function pretending_never_looks_anything_up(): void
    {
        $connection = $this->connection('clusters');

        $sql = $connection->pretend(fn () => $connection->statement('drop index "clusters_team_id_name_unique"'));

        $this->assertSame('drop index "clusters_team_id_name_unique"', $sql[0]['query']);
        // Only the statement itself reaches the (pretended) query callback: no pg_constraint lookup.
        $this->assertSame([['drop index "clusters_team_id_name_unique"', []]], $connection->ran);
    }
}
