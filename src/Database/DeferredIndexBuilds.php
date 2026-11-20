<?php

namespace Multiapi\LaravelAuroraDsql\Database;

use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * The index builds one DsqlConnection holds back during a `migrate` run, to build them together.
 *
 * Aurora DSQL builds a secondary index only with CREATE [UNIQUE] INDEX ASYNC: a background job of ~10-13 s even on
 * an empty table, while CREATE TABLE / ALTER TABLE take milliseconds. Waiting for each job in turn makes a run wait
 * for every index one after another. Not waiting is not enough: a job that finishes while a CREATE/ALTER TABLE runs
 * fails that statement with OC001 ("schema has been updated by another transaction"). So while a run defers
 * (Migrations\IndexBuildScheduler starts and ends it):
 *
 *  - every CREATE [UNIQUE] INDEX ASYNC the schema grammar compiled is queued instead of run; everything else runs in
 *    migration order, with no build job alive;
 *  - a DROP INDEX of a queued index takes it off the queue (it is never built);
 *  - a statement that names a table with queued indexes and could notice they are missing first builds that table's
 *    queued indexes and waits for them: any DDL but the grammar's CREATE TABLE / ADD COLUMN, and DML (with
 *    AT_RUN_END only an upsert, which needs its unique index to exist);
 *  - the queue is built at once (an OC001 between the CREATE INDEX statements is retried), every job is awaited, and
 *    the run fails if a job did not complete or an index is left invalid:
 *      - AT_RUN_END (no migration had run before: a first install, a throwaway cluster): when the run ends;
 *      - AT_MIGRATION_END (a deploy): when each migration ends, before Laravel records it as run.
 *
 * A CREATE INDEX ASYNC written by hand (DB::statement) is not queued: the connection waits for it as it always did.
 */
class DeferredIndexBuilds
{
    public const AT_RUN_END = 'run';

    public const AT_MIGRATION_END = 'migration';

    /** Statements that cannot notice a missing index. */
    private const READ_ONLY = '/^\s*(select|call|set|show|explain|begin|start|commit|rollback)\b/i';

    private const DML = '/^\s*(insert|update|delete|with|merge)\b/i';

    /** Job states in sys.jobs that mean the index was not built. */
    private const FAILED_STATES = ['failed', 'cancelling', 'cancelled'];

    private ?string $mode = null;

    /** @var array<string, array{table: string, schema: ?string, index: string}> CREATE INDEX statements the grammar compiled during this run, by SQL */
    private array $compiled = [];

    /** @var array<string, true> statements the grammar compiled that cannot notice a missing index, by SQL */
    private array $unaffected = [];

    /** @var array<string, array{table: string, schema: ?string, index: string}> queued CREATE INDEX statements, by SQL */
    private array $queue = [];

    private bool $building = false;

    /** @var WeakMap<self, true>|null instances that deferred in this process (see buildAbandoned()) */
    private static ?WeakMap $started = null;

    public function __construct(private DsqlConnection $connection) {}

    /** Starts deferring: self::AT_RUN_END or self::AT_MIGRATION_END. */
    public function begin(string $mode): void
    {
        $this->end();
        $this->mode = $mode;

        if (self::$started === null) {
            self::$started = new WeakMap;
            register_shutdown_function(self::buildAbandoned(...));
        }

        self::$started[$this] = true;
    }

    /** Stops deferring. Whatever is still queued is dropped: call buildQueued() first. */
    public function end(): void
    {
        $this->mode = null;
        $this->compiled = [];
        $this->unaffected = [];
        $this->queue = [];
    }

    public function active(): bool
    {
        return $this->mode !== null;
    }

    public function mode(): ?string
    {
        return $this->mode;
    }

    /** @return list<string> the queued CREATE INDEX statements */
    public function queued(): array
    {
        return array_keys($this->queue);
    }

    /**
     * The schema grammar compiled this CREATE [UNIQUE] INDEX ASYNC. $table is the table as the blueprint names it
     * (it may be "schema.table"); the connection's prefix goes before the table name.
     */
    public function compiledIndex(string $sql, string $table, string $prefix, string $index): void
    {
        if ($this->mode === null) {
            return;
        }

        $dot = strrpos($table, '.');

        $this->compiled[$sql] = [
            'table' => $prefix.($dot === false ? $table : substr($table, $dot + 1)),
            'schema' => $dot === false ? null : substr($table, 0, $dot),
            'index' => $index,
        ];
    }

    /** The schema grammar compiled this statement, and it cannot notice a missing index (CREATE TABLE, ADD COLUMN). */
    public function compiledUnaffected(string $sql): void
    {
        if ($this->mode !== null) {
            $this->unaffected[$sql] = true;
        }
    }

    /** Queues the statement if it is a CREATE INDEX the grammar compiled during this run. */
    public function queue(string $sql): bool
    {
        if ($this->mode === null || ! isset($this->compiled[$sql])) {
            return false;
        }

        $this->queue[$sql] = $this->compiled[$sql];

        return true;
    }

    /** Takes a queued index off the queue; false if no index of that name is queued. */
    public function forget(string $index): bool
    {
        foreach ($this->queue as $sql => $entry) {
            if ($entry['index'] === $index) {
                unset($this->queue[$sql]);

                return true;
            }
        }

        return false;
    }

    /** Before the connection runs a statement: builds the queued indexes that statement could notice. */
    public function beforeQuery(string $query): void
    {
        if ($this->queue === [] || $this->building || isset($this->unaffected[$query]) || preg_match(self::READ_ONLY, $query)) {
            return;
        }

        // With AT_RUN_END the database was empty: a unique index built at the end still rejects duplicates the run
        // wrote (its build fails, and with it the run). Only an upsert needs its unique index to exist already.
        if ($this->mode === self::AT_RUN_END && preg_match(self::DML, $query) && stripos($query, 'on conflict') === false) {
            return;
        }

        $this->buildQueued($this->queuedTablesIn($query));
    }

    /**
     * Before the connection opens a transaction. DSQL does not mix DDL and DML in one transaction, so nothing can be
     * built once it is open: with AT_MIGRATION_END, where any DML needs the indexes of its table, build them now.
     */
    public function beforeTransaction(): void
    {
        if ($this->mode === self::AT_MIGRATION_END && ! $this->building) {
            $this->buildQueued();
        }
    }

    /**
     * Creates the queued indexes (of the given tables, or all), waits for their jobs and fails if one did not
     * complete or left its index invalid.
     *
     * @param  list<string>|null  $tables
     */
    public function buildQueued(?array $tables = null): void
    {
        $pending = $tables === null
            ? $this->queue
            : array_filter($this->queue, fn (array $entry) => in_array($entry['table'], $tables, true));

        if ($pending === []) {
            return;
        }

        if ($this->connection->transactionLevel() > 0) {
            throw new RuntimeException(sprintf(
                'Aurora DSQL cannot build indexes inside a transaction, and this statement needs %s first. '
                .'Run it outside the transaction, or set \'index_builds\' => \'per-index\' on the connection.',
                implode(', ', array_column($pending, 'index')),
            ));
        }

        $this->building = true;

        try {
            $jobs = [];

            foreach ($pending as $sql => $entry) {
                unset($this->queue[$sql]);

                $jobs[$entry['index']] = $this->connection->startIndexBuild($sql);
            }

            $jobs = array_filter($jobs);

            foreach ($jobs as $job) {
                $this->connection->statement('call sys.wait_for_job(?)', [$job]);
            }

            $this->assertBuilt(array_values($pending), $jobs);
        } finally {
            $this->building = false;
        }
    }

    /**
     * Builds what a run left queued when it stopped without ending (a migration threw): the migrations before it
     * are already recorded as run, and their indexes would never be built. Registered as a shutdown function for
     * Laravel versions without the MigrationFailed event; the scheduler normally gets there first.
     */
    public static function buildAbandoned(): void
    {
        foreach (self::$started ?? [] as $builds => $_) {
            if ($builds->queue === []) {
                continue;
            }

            try {
                $builds->buildQueued();
            } catch (Throwable $e) {
                error_log('Aurora DSQL: building the indexes of an interrupted migrate run failed: '.$e->getMessage());
            } finally {
                $builds->end();
            }
        }
    }

    /**
     * @param  list<array{table: string, schema: ?string, index: string}>  $indexes
     * @param  array<string, string>  $jobs  job ids by index name
     */
    private function assertBuilt(array $indexes, array $jobs): void
    {
        $failed = $this->failedJobs($jobs);
        $invalid = $this->invalidIndexes($indexes);

        if ($failed === [] && $invalid === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Aurora DSQL index builds failed. Failed jobs: %s. Invalid indexes: %s.',
            json_encode($failed),
            json_encode($invalid),
        ));
    }

    /**
     * sys.wait_for_job returns when the job stops, whatever its outcome: read it.
     *
     * @param  array<string, string>  $jobs  job ids by index name
     * @return list<array{index: string, job_id: string, status: string, details: ?string}>
     */
    private function failedJobs(array $jobs): array
    {
        if ($jobs === []) {
            return [];
        }

        try {
            $rows = $this->connection->select(
                'select job_id, status, details from sys.jobs where job_id in ('.implode(', ', array_fill(0, count($jobs), '?')).')',
                array_values($jobs)
            );
        } catch (QueryException $e) {
            // An emulator without sys.jobs (SQLSTATE 42P01, undefined table): the validity check still runs.
            if ((string) $e->getCode() === '42P01') {
                return [];
            }

            throw $e;
        }

        $indexes = array_flip($jobs);
        $failed = [];

        foreach ($rows as $row) {
            if (in_array(strtolower((string) $row->status), self::FAILED_STATES, true)) {
                $failed[] = [
                    'index' => $indexes[$row->job_id] ?? '',
                    'job_id' => (string) $row->job_id,
                    'status' => (string) $row->status,
                    'details' => $row->details ?? null,
                ];
            }
        }

        return $failed;
    }

    /**
     * A failed build leaves the index defined but invalid.
     *
     * @param  list<array{table: string, schema: ?string, index: string}>  $indexes
     * @return list<string>
     */
    private function invalidIndexes(array $indexes): array
    {
        $conditions = [];
        $bindings = [];

        foreach ($indexes as $entry) {
            if ($entry['schema'] === null) {
                $conditions[] = '(c.relname = ? and n.nspname = current_schema())';
                $bindings[] = $entry['index'];
            } else {
                $conditions[] = '(c.relname = ? and n.nspname = ?)';
                array_push($bindings, $entry['index'], $entry['schema']);
            }
        }

        $rows = $this->connection->select(
            'select c.relname from pg_index i join pg_class c on c.oid = i.indexrelid '
            .'join pg_namespace n on n.oid = c.relnamespace where not i.indisvalid and ('.implode(' or ', $conditions).')',
            $bindings
        );

        return array_values(array_map(strval(...), array_column($rows, 'relname')));
    }

    /**
     * The tables with queued indexes that the SQL names, quoted or not. A name that is also a column or an alias
     * only builds that table's indexes earlier than needed.
     *
     * @return list<string>
     */
    private function queuedTablesIn(string $sql): array
    {
        $tables = array_unique(array_column($this->queue, 'table'));

        return array_values(array_filter(
            $tables,
            fn (string $table) => preg_match('/(?<![\w$])"?'.preg_quote($table, '/').'"?(?![\w$])/i', $sql) === 1,
        ));
    }
}
