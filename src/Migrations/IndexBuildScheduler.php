<?php

namespace Multiapi\LaravelAuroraDsql\Migrations;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migrator;
use Multiapi\LaravelAuroraDsql\Database\DeferredIndexBuilds;
use Multiapi\LaravelAuroraDsql\Database\DsqlConnection;
use Throwable;

/**
 * Makes `migrate` build the indexes of each DSQL connection together (Database\DeferredIndexBuilds), from the
 * Migrator's events:
 *
 *  - a `migrate up` run starts (not --pretend): if no migration had run before, indexes are built when the run ends;
 *    otherwise when each migration ends, before Laravel records it as run;
 *  - a migration starts: its connection starts deferring if it is a DSQL connection that allows it
 *    (DsqlConnection::defersIndexBuilds()); other connections are left alone;
 *  - a migration fails: what is queued is built anyway, because earlier migrations of the run are already recorded.
 */
class IndexBuildScheduler
{
    /** DeferredIndexBuilds::AT_RUN_END or AT_MIGRATION_END during a deferring run. */
    private ?string $mode = null;

    /** @var array<int, DeferredIndexBuilds> the connections deferring in this run */
    private array $builds = [];

    public function __construct(private Container $app) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(MigrationsStarted::class, $this->runStarted(...));
        $events->listen(MigrationStarted::class, $this->migrationStarted(...));
        $events->listen(MigrationEnded::class, $this->migrationEnded(...));
        $events->listen(MigrationsEnded::class, $this->runEnded(...));
        // Since Laravel 13.43. Older versions: DeferredIndexBuilds::buildAbandoned() at shutdown.
        $events->listen('Illuminate\Database\Events\MigrationFailed', $this->abandon(...));
    }

    public function runStarted(MigrationsStarted $event): void
    {
        // A previous run in this process that never ended.
        $this->abandon();

        // --pretend only prints the SQL: every statement, in order.
        if ($event->method !== 'up' || ($event->options['pretend'] ?? false)) {
            return;
        }

        $repository = $this->migrator()->getRepository();

        $this->mode = ! $repository->repositoryExists() || $repository->getRan() === []
            ? DeferredIndexBuilds::AT_RUN_END
            : DeferredIndexBuilds::AT_MIGRATION_END;
    }

    public function migrationStarted(MigrationStarted $event): void
    {
        if ($this->mode === null || $event->method !== 'up') {
            return;
        }

        $connection = $this->migrator()->resolveConnection(
            method_exists($event->migration, 'getConnection') ? $event->migration->getConnection() : null
        );

        if (! $connection instanceof DsqlConnection || ! $connection->defersIndexBuilds()) {
            return;
        }

        $builds = $connection->indexBuilds();

        if (! isset($this->builds[spl_object_id($builds)])) {
            $builds->begin($this->mode);
            $this->builds[spl_object_id($builds)] = $builds;
        }
    }

    public function migrationEnded(): void
    {
        if ($this->mode !== DeferredIndexBuilds::AT_MIGRATION_END) {
            return;
        }

        foreach ($this->builds as $builds) {
            $builds->buildQueued();
        }
    }

    public function runEnded(): void
    {
        try {
            foreach ($this->builds as $builds) {
                $builds->buildQueued();
            }
        } finally {
            $this->stop();
        }
    }

    /** The run stopped without ending: build what is queued, without hiding the failure that stopped it. */
    public function abandon(): void
    {
        foreach ($this->builds as $builds) {
            try {
                $builds->buildQueued();
            } catch (Throwable $e) {
                if ($this->app->bound(ExceptionHandler::class)) {
                    $this->app->make(ExceptionHandler::class)->report($e);
                }
            }
        }

        $this->stop();
    }

    private function stop(): void
    {
        foreach ($this->builds as $builds) {
            $builds->end();
        }

        $this->builds = [];
        $this->mode = null;
    }

    private function migrator(): Migrator
    {
        return $this->app->make('migrator');
    }
}
