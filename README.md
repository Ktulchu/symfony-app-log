# Symfony App Log

Opt-in, transactional PostgreSQL and MySQL 8.0+ audit history for Doctrine ORM entities. No Yii dependency, extra connection, queue, nested flush or Composer install hook.

Requires PHP 8.3+, Symfony 7.4/8, Doctrine ORM 3.5+ and DBAL 4.3+. The originating application uses Yii and PHP 8.1; this is an independent Symfony implementation rather than a framework port. PHP 8.1 is not a supported package target.

## What's new in v0.2.0

**Added MySQL 8.0+ support** alongside PostgreSQL, with native MySQL `JSON` snapshots, database-specific migrations and indexed entity history lookup. The audit preserves SQL NULL versus JSON null, transactional INSERT/UPDATE/DELETE history and rollback behavior. On MySQL, audited tables and `app_log` must use **InnoDB**. PostgreSQL `json`/`jsonb` behavior remains unchanged.

## Project background

The audit requirements and architectural approach grew out of years of work on private commercial applications. This Symfony/Doctrine package is a new, independent implementation of those established ideas, not a copy of the original Yii application. The earlier development history remains in a private commercial GitLab repository; the public GitHub history starts with this standalone implementation and its own tests and releases.

## Install

The package is not registered on Packagist. Add the GitHub VCS repository:

```sh
composer config repositories.app-log vcs https://github.com/Ktulchu/symfony-app-log
composer require ktulchu/symfony-app-log:^0.2
composer require doctrine/doctrine-migrations-bundle
```

Enable FrameworkBundle, DoctrineBundle and DoctrineMigrationsBundle as usual. There is no additional package bundle to enable.

## Register the listener

```yaml
# config/services.yaml
services:
    Ktulchu\AppLog\AuditSubscriber:
        autoconfigure: false
        tags:
            - { name: doctrine.event_listener, event: onFlush, connection: default, priority: -1024 }
            - { name: doctrine.event_listener, event: postFlush, connection: default, priority: -1024 }
            - { name: doctrine.event_listener, event: onClear, connection: default }
```

Register once for the intended entity manager/connection. Do not also register it as a subscriber. The listener takes its DBAL connection from that entity manager.

For PHP 8.4+ with Symfony 8 and DoctrineBundle 2, enable `doctrine.orm.enable_native_lazy_objects: true`. DoctrineBundle 3 uses native lazy objects automatically and removes the old proxy options. Symfony 8 no longer provides the older lazy ghost implementation. The fixture handles both bundle versions.

## Migrate

```yaml
# config/packages/doctrine_migrations.yaml
doctrine_migrations:
    migrations_paths:
        'Ktulchu\AppLog\Migrations': '%kernel.project_dir%/vendor/ktulchu/symfony-app-log/migrations'
```

```sh
php bin/console doctrine:migrations:migrate --no-interaction
```

Review SQL before applying it to your database. Installing Composer dependencies never runs migrations automatically. On PostgreSQL, the original migration creates `app_log` with a JSONB identifier map, timestamptz and lossless JSON old/new documents. On MySQL 8.0+, the separate migration creates native JSON identifier and history columns plus an indexed SHA-256 `entity_key` for the deterministic serialized single-field identifier. The migration for the other supported database is a no-op. Neither migration changes application tables. Rolling back the appropriate migration **deletes all audit history**.

## Select entities and exclude fields

```php
use Doctrine\ORM\Mapping as ORM;
use Ktulchu\AppLog\Audited;

#[ORM\Entity]
#[Audited(exclude: ['secret'])]
class Example
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $secret = '';

    #[ORM\Column(type: 'app_log_json', nullable: true)]
    public ?string $document = '{}';

    #[ORM\Column(type: 'app_log_jsonb', nullable: true)]
    public ?string $payload = '{}';
}
```

Exclusions use Doctrine property names. Unknown names fail before writes. Unmarked entities are ignored. Scalar mapped fields are included by default; associations and collections are outside v0.1 scope. The audit table must never be mapped as audited.

## JSON and JSONB

The audit always reads database snapshots, including values normalized by immediate database triggers. Physical column types are resolved from the database: a text column containing JSON remains ordinary text.

**PostgreSQL** retains the original behavior:

* `json` tracks stored text, including key order, whitespace, escapes and duplicate keys. This is an explicit representation contract, not structural equality.
* `jsonb` uses PostgreSQL `IS DISTINCT FROM` equality. Reordered object keys and numerically equal numbers do not create history. Array order and primitive types matter.
* SQL NULL, JSON null, `{}`, `[]`, absent keys, booleans, strings and arbitrary precision numbers remain distinguishable in storage.

**MySQL 8.0+** supports native `JSON` (not `JSONB`). MySQL normalizes stored JSON, including object-key order, whitespace and duplicate keys. The audit compares MySQL's normalized stored JSON representation (not the original input text), avoiding precision loss from recasting large JSON numbers. MySQL may retain distinctions such as numeric representations even when their numeric values compare equal. The audit cannot recover original whitespace, key order or duplicate keys. SQL NULL and the JSON literal null remain distinct in `data.sql_null`; both scalar values and `data.raw` come from SQL without PHP JSON decoding. Limits of MySQL numeric JSON storage still apply.

Doctrine's normal `json` mapping can itself collapse `{}`/`[]`, SQL NULL/JSON null and large numbers during hydration. The audit cannot undo a lossy business write or detect a change Doctrine never schedules. Use these opt-in raw types when these distinctions matter:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        types:
            app_log_json: Ktulchu\AppLog\RawJsonType
            app_log_jsonb: Ktulchu\AppLog\RawJsonbType
```

Entity values are valid JSON **strings** (`'{}'`, `'[]'`, `'null'`, `'1'`, `'"1"'`) or PHP `null` for SQL NULL. The `app_log_json` type works with both supported databases; `app_log_jsonb` is PostgreSQL-only and explicitly rejected for MySQL schema generation. Use `#[ORM\\Column(type: 'app_log_json')]` on MySQL. The audit envelopes preserve database-typed values without PHP JSON decoding, subject to the source database's own JSON normalization and precision rules. Built-in Doctrine `json` mappings work within their hydration limitations.

## Transaction contract

Every flush involving audited entities must run inside an outer transaction:

On MySQL, both the audited business tables and `app_log` must use **InnoDB**.
The listener checks their engines before business SQL and rejects missing or
non-transactional tables. A transaction wrapper cannot make MyISAM writes atomic.

```php
$entityManager->wrapInTransaction(function ($em) use ($example): void {
    $em->persist($example);
}); // wrapInTransaction performs flush and commit

$entityManager->wrapInTransaction(function () use ($example): void {
    $example->document = '{"active":true}';
});

$entityManager->wrapInTransaction(function ($em) use ($example): void {
    $em->remove($example);
});
```

`onFlush` runs before Doctrine's internal transaction. It requires the outer transaction, inspects scheduled changes, validates mappings, locks existing rows with `FOR UPDATE` and reads before snapshots. `postFlush` runs after the inner flush transaction but inside the required outer transaction. It reads the final stored values and inserts history through the same DBAL connection. No model is re-saved, no audit entity is persisted and no recursive flush occurs. PostgreSQL and MySQL build typed JSON snapshots inside their respective databases, avoiding PHP numeric conversion.

An unwrapped audited flush fails before business SQL. `wrapInTransaction` rolls back business writes and audit on every exception, including audit insertion failure. Explicit transaction users must roll back on **any** flush exception, discard/clear the entity manager as appropriate, and never commit after catching an audit error. An outer rollback removes both business and audit rows. Sequence gaps after rollback are normal. Retry the entire transaction using a fresh entity manager; there is no promise of deduplication across independently committed requests.

There is one row per persisted entity operation per flush. Update documents include only changed, non-excluded fields; insert/delete documents contain full non-excluded scalar snapshots. An absent before/after entity is `{}` at the document root; a SQL NULL field is an envelope with `sql_null: true`.

## Read history

```php
$rows = $connection->fetchAllAssociative(
    'SELECT operation, occurred_at, old_values, new_values FROM app_log
     WHERE entity_type = ? AND entity_id = CAST(? AS jsonb) ORDER BY id',
    [Example::class, json_encode(['id' => $id], JSON_THROW_ON_ERROR)]
);
```

Example JSON field envelope:

```json
{"document":{"db_type":"json","data":{"sql_null":false,"value":{"active":true},"raw":"{\"active\":true}"}}}
```

`value` has its database JSON type. `raw` is the authoritative original PostgreSQL text and preserves precision/representation for clients whose JSON parser loses numbers. Use a lossless decoder when consuming arbitrary precision values. `old_values` and `new_values` are JSON rather than JSONB so JSON source documents and duplicate keys are retained. All SQL identifiers are quoted; field values are bound parameters.

## Reproduce the checks

```sh
composer install
composer validate --strict
composer audit
PGHOST=127.0.0.1 composer test
composer analyse
```

Tests require a **disposable** PostgreSQL database `audit`, user/password `audit`; the suite recreates its fixture and audit tables. Never point it at an application database. See `.github/workflows/ci.yml` for PostgreSQL setup and the PHP/Symfony matrix.

The independent application in `fixtures/symfony` installs its own Composer dependencies and the package through a path repository. Against a second disposable database:

```sh
cd fixtures/symfony
composer install
export DATABASE_URL='postgresql://audit:audit@127.0.0.1:5432/fixture?serverVersion=17'
php bin/console doctrine:migrations:migrate --no-interaction
php smoke.php
```

The smoke test boots Symfony's container, uses tagged listeners, applies the packaged migration through DoctrineMigrationsBundle, creates an entity table, then checks insert/update/delete history in PostgreSQL. It requires an empty fixture database.

## MySQL entity history lookup

MySQL does not index `entity_id` directly because it is native JSON. For the supported single-column identifier, generate exactly the same JSON identifier map used by the audit writer and hash it before lookup:

```php
$idJson = json_encode(['id' => $id], JSON_THROW_ON_ERROR); // Replace 'id' with the mapped identifier field.
$rows = $connection->fetchAllAssociative(
    'SELECT operation, occurred_at, old_values, new_values FROM app_log
     WHERE entity_type = ? AND entity_key = ? ORDER BY id',
    [Example::class, hash('sha256', $idJson)]
);
```

For PostgreSQL, the JSONB lookup above remains unchanged. MySQL's `entity_key` is an index aid; `entity_id` still stores the original identifier map as JSON.

## MySQL integration tests

For the disposable MySQL 8.0 database named `audit`, with username/password `audit` and the environment variable `MYSQL_HOST` set:

```sh
MYSQL_HOST=127.0.0.1 composer test
```

The CI matrix includes independent PostgreSQL and MySQL 8.0 jobs. MySQL tests recreate their own fixture and audit tables; **never** run them against the CRM or any non-disposable database. The Symfony consumer fixture also exercises the MySQL migration and listener.

## Scope and limitations

* PostgreSQL and MySQL 8.0+ support single-table entities with one scalar identifier; inheritance and composite/association identifiers fail explicitly. Other platforms, including MariaDB, are not supported.
* Direct SQL, DQL bulk writes, database cascades, association-only changes and unmarked entities are not audited. No field-level recursive diff is generated.
* All entity changes must be scheduled before this listener's `onFlush`; do not mutate audited entities or issue untracked writes in later flush listeners. Deferred triggers executed only at outer commit are outside the snapshot boundary.
* Excluding fields is a security decision: choose exclusions before using the package. There is no automatic actor lookup, retention, encryption, soft-delete interpretation or domain identifier translation.
* A status-field update is an ordinary update. Domain-specific soft deletes and display callbacks from a legacy application must be implemented by that application; they are not silently imported.
* Each update requires a locked before snapshot, a final read, JSONB equality queries and an audit insert. This intentionally favors correctness over bulk-write throughput. Normal application concurrency/deadlock retry policy still applies.
* This is a new generic table, with no automatic import or modification of a legacy audit table. Existing history stays readable through its existing application. No proprietary application code or data is included.
