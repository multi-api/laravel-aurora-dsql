# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed
- `migrate` builds the indexes of a `dsql` connection together instead of waiting for each one in turn. On a first
  install (no migration recorded) every index is built when the run ends; on a deploy each migration's indexes are
  built when it ends, before it is recorded. A statement that could notice a missing index builds that table's
  indexes first, and a `dropIndex()` of an index not built yet cancels it. See "Index builds during `migrate`" in the
  README. Measured in Arco Cloud, where this ran first: a from-scratch `migrate` went from about 20 minutes to under
  one. Each connection has its own queue; connections of other drivers and the application's `Blueprint` are not
  touched. A `CREATE INDEX ASYNC` written by hand is still awaited on the spot.
- After these builds the driver reads each job's status in `sys.jobs` and checks `pg_index`: a job that failed or
  was cancelled, or an index left invalid, fails the run with the job's details.

### Added
- `index_builds` connection setting: `'deferred'` (default) or `'per-index'` for the previous behaviour, the wait
  after each index in migration order. `'wait_for_indexes' => false` also keeps the previous behaviour.

## [0.4.2] - 2026-11-15

### Added
- `port` connection setting (default `5432`), passed to the AWS connector. DSQL always listens on 5432; this is for a
  local emulator on another port, such as [Floci](https://github.com/floci-io/floci)'s emulated DSQL. The integration
  tests read `DSQL_PORT` and `DSQL_SSLROOTCERT` too, and pass against Floci 2.5.0.

## [0.4.1] - 2026-11-14

### Changed
- No code changes. Re-release of 0.4.0: Packagist's Composer metadata did not pick up 0.4.0.

## [0.4.0] - 2026-11-14

### Changed
- A unique declared in `Schema::create()` is now an inline `UNIQUE` table constraint in `CREATE TABLE` instead of a
  separate `CREATE UNIQUE INDEX ASYNC`. DSQL builds it with the empty table: it is valid immediately and there is no
  build job to wait for (verified on a live cluster: duplicate rows rejected, `ON CONFLICT` and leading-column
  lookups use it, `NULLS NOT DISTINCT` honoured). A unique added to an existing table, or with an index method, is
  still `CREATE UNIQUE INDEX ASYNC`.
- `dropUnique()` now compiles to `DROP INDEX`, like `dropIndex()`, and the connection turns a `DROP INDEX` naming a
  unique constraint into `ALTER TABLE ... DROP CONSTRAINT`. Either method drops either kind of unique, including
  ones created by 0.3 (indexes) and by this version (constraints).

### Fixed
- The integration test for multi-statement transactions expected a boolean `select ? as b` to come back as `true`;
  with emulated prepares it is the text `'true'`.

## [0.3.1] - 2026-11-04

### Fixed
- A transaction can run more than one statement. pdo_pgsql built against libpq 17+ closes each server-side
  prepared statement with a protocol Close message that Aurora DSQL rejects, which aborted the transaction: the
  second statement failed with SQLSTATE `25P02` ("current transaction is aborted"). Every `dsql` connection now
  uses emulated prepares (`PDO::ATTR_EMULATE_PREPARES`), and the attribute is recorded in the connection's
  `options`.
- With emulated prepares, booleans are bound as `'true'`/`'false'` instead of `1`/`0`, which DSQL would reject for
  a boolean column.

### Documentation
- Under FrankenPHP workers the `putenv('PGSSLROOTCERT=…')` fallback does not reach libpq: the README says to set
  `PGSSLROOTCERT` as a real environment variable there.

## [0.3.0] - 2026-11-02

### Changed
- The package moved to the Multiapi organization: Composer name `multiapi/laravel-aurora-dsql`,
  repository `github.com/multi-api/laravel-aurora-dsql`. It `replace`s `jgamboa/laravel-aurora-dsql`, which is
  abandoned on Packagist with this package as its replacement (its versions up to v0.2.2 stay installable).
- The PHP namespace is now `Multiapi\LaravelAuroraDsql` (service provider
  `Multiapi\LaravelAuroraDsql\DsqlServiceProvider`, auto-discovered). The configuration is unchanged.

### Deprecated
- The `JGamboa\LaravelAuroraDsql` class names. They remain as aliases of the new classes (including for type
  hints and `instanceof`) and are removed in 1.0.

## [0.2.2] - 2026-11-02

### Fixed
- The `dsql` driver applies the connection's `search_path` (or `schema`) on connect, like Laravel's `pgsql`
  driver. Leaving both unset keeps the role's default `search_path`.

## [0.2.1] - 2026-10-21

### Fixed
- Aurora DSQL has no `SAVEPOINT`: the connection reports `supportsSavepoints() === false` and nested
  transactions run inside the outer one.

## [0.2.0] - 2026-10-21

### Added
- Retry of OCC conflicts (SQLSTATE `40001`) for transactions and autocommit writes, with backoff.

### Fixed
- `afterCommit` callbacks of failed OCC attempts are discarded.

## [0.1.1] - 2026-10-08

### Changed
- README, docs, comments, error messages and tests translated to English.

## [0.1.0] - 2026-10-08

### Added
- Initial release: IAM token auth, DSQL-compatible schema builder (inline PK/FK, `ASYNC` indexes, identity)
  and migration helpers.

[0.4.1]: https://github.com/multi-api/laravel-aurora-dsql/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/multi-api/laravel-aurora-dsql/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/multi-api/laravel-aurora-dsql/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/multi-api/laravel-aurora-dsql/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/multi-api/laravel-aurora-dsql/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/multi-api/laravel-aurora-dsql/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/multi-api/laravel-aurora-dsql/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/multi-api/laravel-aurora-dsql/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/multi-api/laravel-aurora-dsql/releases/tag/v0.1.0
