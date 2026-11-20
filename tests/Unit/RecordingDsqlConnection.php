<?php

namespace Multiapi\LaravelAuroraDsql\Tests\Unit;

use Closure;
use Illuminate\Database\QueryException;
use Multiapi\LaravelAuroraDsql\Database\DsqlConnection;
use PDO;
use PDOException;
use ReflectionProperty;

/**
 * A DsqlConnection that runs nothing: it records each query (with its bindings), answers lookups from $rows (keyed by
 * a fragment of the query; CREATE INDEX returns a job id named after the index), and fails the first $conflicts
 * CREATE INDEX statements with OC001.
 */
class RecordingDsqlConnection extends DsqlConnection
{
    /** @var list<string> */
    public array $ran = [];

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, list<object>|\Throwable>  $rows
     */
    public function __construct(array $config = [], private array $rows = [], private int $conflicts = 0, string $prefix = '')
    {
        parent::__construct(new PDO('sqlite::memory:'), 'postgres', $prefix, $config + ['name' => 'dsql', 'driver' => 'dsql']);

        $this->useDefaultSchemaGrammar();
    }

    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        if (preg_match('/^create (unique )?index async "([^"]+)"/', $query, $m)) {
            if ($this->conflicts-- > 0) {
                $e = new PDOException('OC001: schema has been updated by another transaction');
                (new ReflectionProperty(PDOException::class, 'code'))->setValue($e, '40001');

                throw new QueryException('dsql', $query, [], $e);
            }

            $this->ran[] = $query;

            return [(object) ['job_id' => 'job-'.$m[2]]];
        }

        $this->ran[] = trim($query.' '.implode(',', array_map(strval(...), $bindings)));

        foreach ($this->rows as $fragment => $result) {
            if (str_contains($query, $fragment)) {
                return $result instanceof \Throwable ? throw $result : $result;
            }
        }

        return str_starts_with($query, 'select') ? [] : true;
    }
}
