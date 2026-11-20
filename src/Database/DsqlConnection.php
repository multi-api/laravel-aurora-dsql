<?php

namespace Multiapi\LaravelAuroraDsql\Database;

use Closure;
use Illuminate\Database\PostgresConnection;
use Throwable;

/**
 * Aurora DSQL connection: a PostgresConnection with the DSQL schema grammar.
 * The PDO is created by DsqlServiceProvider using the official connector
 * (IAM token + SSL).
 */
class DsqlConnection extends PostgresConnection
{
    /** DROP INDEX [IF EXISTS] "name" (dropIndex() and dropUnique() both compile to it). */
    private const DROP_INDEX = '/^\s*drop\s+index\s+(if\s+exists\s+)?"?([^"\s]+)"?\s*$/i';

    private const OCC_BASE_DELAY_MS = 50;

    private const OCC_MAX_DELAY_MS = 1000;

    /** A CREATE INDEX ASYNC that fails with OC001 applied nothing: it is retried up to this many attempts. */
    private const INDEX_BUILD_MAX_ATTEMPTS = 10;

    /** The index builds this connection holds back during a `migrate` run. */
    private ?DeferredIndexBuilds $indexBuilds = null;

    /** @var (callable(int): void)|null Replaces usleep; for tests only. */
    private static $sleeper = null;

    /**
     * Swap the backoff sleeper (receives milliseconds). Pass null to restore
     * the real sleep. Intended for tests.
     *
     * @param  (callable(int): void)|null  $sleeper
     */
    public static function sleepUsing(?callable $sleeper): void
    {
        self::$sleeper = $sleeper;
    }

    /**
     * Run a transaction, retrying the whole closure on OCC conflicts
     * (SQLSTATE 40001, OC000/OC001) with exponential backoff and jitter.
     * With $attempts = null, 'occ_max_retries' (default 3) + 1 attempts are made.
     * The closure must be idempotent: it can run several times.
     */
    public function transaction(Closure $callback, $attempts = null)
    {
        // Nested transaction: a conflict must bubble to the outermost loop.
        if ($this->transactionLevel() > 0) {
            return parent::transaction($callback, 1);
        }

        $attempts ??= $this->occMaxAttempts();

        for ($attempt = 1; ; $attempt++) {
            try {
                return parent::transaction($callback, 1);
            } catch (Throwable $e) {
                // A failed COMMIT never reaches the transactions manager in
                // Laravel, so the attempt's afterCommit callbacks would stay
                // registered and fire on a later commit. Discard them here
                // (level is 0: outer transactions cannot exist).
                $this->transactionsManager?->rollback($this->getName(), $this->transactionLevel());

                if ($attempt >= $attempts || ! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }

                $this->occBackoff($attempt);
            }
        }
    }

    /**
     * Autocommit writes (INSERT/UPDATE/DELETE outside a transaction) are
     * retried on OCC conflicts. Inside a transaction the conflict bubbles up
     * to the transaction() loop instead.
     */
    protected function run($query, $bindings, Closure $callback)
    {
        if ($this->indexBuilds?->active() && ! $this->pretending()) {
            $this->indexBuilds->beforeQuery($query);
        }

        if ($this->transactionLevel() > 0 || ! preg_match('/^\s*(insert|update|delete)\b/i', $query)) {
            return parent::run($query, $bindings, $callback);
        }

        $attempts = $this->occMaxAttempts();

        for ($attempt = 1; ; $attempt++) {
            try {
                return parent::run($query, $bindings, $callback);
            } catch (Throwable $e) {
                if ($attempt >= $attempts || ! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }

                $this->occBackoff($attempt);
            }
        }
    }

    /**
     * DSQL does not mix DDL and DML in one transaction, so the indexes a
     * `migrate` run holds back cannot be built once it is open.
     */
    public function beginTransaction()
    {
        if ($this->transactions === 0 && $this->indexBuilds?->active() && ! $this->pretending()) {
            $this->indexBuilds->beforeTransaction();
        }

        parent::beginTransaction();
    }

    private function occMaxAttempts(): int
    {
        return max(0, (int) ($this->getConfig('occ_max_retries') ?? 3)) + 1;
    }

    /** Exponential backoff (base 50ms, cap 1000ms) with jitter floored at half the cap. */
    private function occBackoff(int $attempt): void
    {
        $cap = min(self::OCC_MAX_DELAY_MS, self::OCC_BASE_DELAY_MS * 2 ** ($attempt - 1));
        $ms = random_int(intdiv($cap, 2), $cap);

        if (self::$sleeper !== null) {
            (self::$sleeper)($ms);
        } else {
            usleep($ms * 1000);
        }
    }

    /**
     * DSQL connections always use emulated prepares (see
     * DsqlServiceProvider::pdoAttributes()), so values reach the server as SQL
     * literals. A boolean must be the literal 'true'/'false': an integer 1/0
     * fails against a boolean column ("is of type boolean but expression is of
     * type integer"). Newer Laravel versions do this when 'options' enables
     * emulated prepares; this keeps it independent of the Laravel version.
     */
    public function prepareBindings(array $bindings)
    {
        foreach ($bindings as $key => $value) {
            if (is_bool($value)) {
                $bindings[$key] = $value ? 'true' : 'false';
            }
        }

        return parent::prepareBindings($bindings);
    }

    public function getDriverName()
    {
        // Behaves as Postgres internally (query grammar, processor).
        return 'pgsql';
    }

    protected function getDefaultQueryGrammar()
    {
        return new DsqlQueryGrammar($this);
    }

    protected function getDefaultSchemaGrammar()
    {
        return new DsqlSchemaGrammar($this);
    }

    /**
     * CREATE [UNIQUE] INDEX ASYNC returns a job_id and the index is not valid
     * until the job finishes. By default we wait for it (CALL sys.wait_for_job)
     * so later migrations can rely on the index; disable it with
     * 'wait_for_indexes' => false in the connection config.
     *
     * During a `migrate` run the indexes the schema builder creates are
     * queued instead and built together: see DeferredIndexBuilds.
     */
    public function statement($query, $bindings = [])
    {
        if ($this->pretending()) {
            return parent::statement($query, $bindings);
        }

        $deferred = $this->indexBuilds?->active() ? $this->indexBuilds : null;

        if (preg_match(self::DROP_INDEX, $query, $m)) {
            if ($deferred?->forget($m[2])) {
                return true;
            }

            return parent::statement($this->dropIndexOrUniqueConstraint($query, $m[2]), $bindings);
        }

        if ($deferred?->queue($query)) {
            return true;
        }

        if (! preg_match('/^\s*create\s+(unique\s+)?index\s+async\b/i', $query)) {
            return parent::statement($query, $bindings);
        }

        $jobId = $this->selectOne($query, $bindings, false)?->job_id;

        if ($jobId && $this->getConfig('wait_for_indexes') !== false) {
            parent::statement('call sys.wait_for_job(?)', [$jobId]);
        }

        return true;
    }

    /** The index builds this connection holds back during a `migrate` run (DeferredIndexBuilds). */
    public function indexBuilds(): DeferredIndexBuilds
    {
        return $this->indexBuilds ??= new DeferredIndexBuilds($this);
    }

    /**
     * Whether `migrate` builds this connection's indexes together. Off with
     * 'index_builds' => 'per-index' (wait after each index, in migration
     * order) and with 'wait_for_indexes' => false (nothing waits at all).
     */
    public function defersIndexBuilds(): bool
    {
        return ($this->getConfig('index_builds') ?? 'deferred') !== 'per-index'
            && $this->getConfig('wait_for_indexes') !== false;
    }

    /**
     * Runs a CREATE [UNIQUE] INDEX ASYNC without waiting for its job, and
     * returns the job id. A build job that finishes meanwhile fails the
     * statement with OC001; nothing was applied, so it is retried.
     *
     * @internal
     */
    public function startIndexBuild(string $sql): ?string
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $jobId = $this->selectOne($sql, [], false)?->job_id ?? null;

                return $jobId === null ? null : (string) $jobId;
            } catch (Throwable $e) {
                if ($attempt >= self::INDEX_BUILD_MAX_ATTEMPTS || ! $this->causedByConcurrencyError($e)) {
                    throw $e;
                }

                $this->occBackoff($attempt);
            }
        }
    }

    /**
     * A unique declared in Schema::create() is a table constraint (inline in
     * CREATE TABLE), and its index cannot be dropped with DROP INDEX ("cannot
     * drop index ... because constraint ... requires it"); one added later is
     * a plain CREATE UNIQUE INDEX ASYNC index, which has no constraint to
     * drop. So a DROP INDEX naming a unique constraint of the current schema
     * becomes ALTER TABLE ... DROP CONSTRAINT; anything else runs as written.
     */
    protected function dropIndexOrUniqueConstraint(string $query, string $name): string
    {
        $table = $this->selectOne(
            "select c.conrelid::regclass::text as tbl from pg_constraint c join pg_namespace n on n.oid = c.connamespace "
            ."where c.conname = ? and c.contype = 'u' and n.nspname = current_schema()",
            [$name],
            false
        )?->tbl;

        return $table ? sprintf('alter table %s drop constraint "%s"', $table, str_replace('"', '""', $name)) : $query;
    }
}
