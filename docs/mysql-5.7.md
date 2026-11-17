# MySQL 5.7.13 compatibility (unreleased)

v0.2.0 rejects MySQL 5.7 in its listener and both migration paths. The development
change admits MySQLPlatform only when the actual server version is at least
5.7.13; MariaDB and older MySQL remain rejected. PostgreSQL behavior is unchanged.

## Verified environment and SQL

The exact official `mysql:5.7.13` image was run in an isolated container and
`SELECT VERSION()` returned `5.7.13`. Modern Docker could not unpack its legacy
manifest directly. Skopeo converted the archive format without changing the
server binaries:

```sh
skopeo copy --format v2s2 docker://docker.io/library/mysql:5.7.13 docker-archive:/tmp/mysql5713.tar:mysql5713-ci
docker load -i /tmp/mysql5713.tar
docker run -d --name mysql5713 -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=audit -e MYSQL_USER=audit -e MYSQL_PASSWORD=audit mysql5713-ci --log-bin-trust-function-creators=1
docker exec mysql5713 mysql -uroot -proot -Nse 'SELECT VERSION()'
```

Those credentials and databases are disposable test values only. Never point
the destructive test suite at an application database. CI independently verifies
the exact version before running PHPUnit and a separate Symfony consumer.

The existing JSON_OBJECT, CAST AS JSON/CHAR, INFORMATION_SCHEMA, SHA-256 lookup
key, TIMESTAMP(6), AUTO_INCREMENT, FOR UPDATE and savepoint transaction flow work
on 5.7.13. No JSON aggregate, JSON_TABLE, window function or MySQL 8-only collation
is used. Native JSON columns require no PHP JSON decoding for snapshots.

## Requirements and differences

- Both audited business tables and app_log must be InnoDB. Engine checks still
  run before business writes, and every audited flush requires an outer
  transaction. Failures must roll back the entire business operation.
- The history index includes utf8mb4 entity_type plus an ASCII hash and id.
  The migration explicitly requests ROW_FORMAT=DYNAMIC. On 5.7.13, Barracuda,
  innodb_large_prefix=ON and a suitable InnoDB page size/index limit are required.
  Default 16 KiB pages allow the 1092-byte maximum key. COMPACT/REDUNDANT's
  767-byte limit cannot hold it. Inspect actual settings before migration;
  do not silently shorten the index or use a nontransactional engine.
- MySQL 5.7 parses but **does not enforce** CHECK, including app_log's operation
  check. The listener emits only insert/update/delete. The check is not a
  guarantee against arbitrary direct SQL on 5.7 (nor early MySQL 8.0). Such writes
  are outside the audit API. Database enforcement must not be assumed.
- MySQL 5.7 keeps the first duplicate JSON object key; MySQL 8.0 keeps the last.
  Tests assert the actual stored result and suppress a subsequent rewrite of
  the same normalized value. Whitespace, key ordering and duplicate source keys
  cannot be recovered. Numeric precision is limited by each server's native
  JSON storage; 64-bit values are tested without PHP decoding/re-encoding.
- SQL NULL and JSON null remain distinct through data.sql_null and data.raw.
  Doctrine's built-in json type may collapse values during business hydration;
  opt into app_log_json (?string) when preserving those distinctions matters.
- DBAL 4 retains MySQL 5.7 support, with a deprecation notice. DBAL 5 must not be
  substituted. Configure the exact server version (5.7.13), not 8.0 to defeat
  platform selection. Test the application's locked DBAL/ORM versions as well.

## Existing installations

Changing this migration only affects fresh schemas. Already executed v0.2.0
migrations are not replayed, and existing audit history is not rebuilt/deleted.
The schema/lookup contract is unchanged. Review SQL before applying migrations;
MySQL DDL implicitly commits and cannot be undone by a business rollback.

For an application rollout, take and restore-test a consistent backup before
converting any MyISAM business tables. Code rollback does not reverse DDL.
Preserve app_log and InnoDB tables on rollback; do not execute the audit down
migration, which deletes history. An application-specific maintenance/cutover
and data-validation plan is required before production use.
