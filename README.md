# Symfony App Log

Opt-in, transactional PostgreSQL audit history for Doctrine ORM entities. No Yii dependency, extra connection, queue, nested flush or Composer install hook.

Requires PHP 8.3+, Symfony 7.4/8, Doctrine ORM 3.5+ and DBAL 4.3+. The originating application uses Yii and PHP 8.1; this is an independent Symfony implementation rather than a framework port. PHP 8.1 is not a supported package target.

## Install

The package is not registered on Packagist. Add the GitHub VCS repository:

```sh
composer config repositories.app-log vcs https://github.com/Ktulchu/symfony-app-log
composer require ktulchu/symfony-app-log:^0.1
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

For PHP 8.4+ with Symfony 8, enable `doctrine.orm.enable_native_lazy_objects: true` (Symfony 8 no longer provides the older lazy ghost implementation). The integration fixture selects this automatically by PHP version.

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

Review SQL before applying it to your database. Installing Composer dependencies never runs this migration automatically. The migration creates `app_log`, a generated bigint identifier, entity class, JSONB scalar identifier map, operation, timestamp with timezone and JSON old/new documents. Indexes cover entity history and time. The down migration deletes audit history.

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

The audit always reads database snapshots, including values normalized by immediate database triggers. PostgreSQL determines the actual column type; a text column containing JSON remains ordinary text.

* `json` tracks stored text, including key order, whitespace, escapes and duplicate keys. This is an explicit representation contract, not structural equality.
* `jsonb` uses PostgreSQL `IS DISTINCT FROM` equality. Reordered object keys and numerically equal numbers do not create history. Array order and primitive types matter.
* SQL NULL, JSON null, `{}`, `[]`, absent keys, booleans, strings and arbitrary precision numbers remain distinguishable in storage.

Doctrine's normal `json` mapping can itself collapse `{}`/`[]`, SQL NULL/JSON null and large numbers during hydration. The audit cannot undo a lossy business write or detect a change Doctrine never schedules. Use these opt-in raw types when these distinctions matter:

```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        types:
            app_log_json: Ktulchu\AppLog\RawJsonType
            app_log_jsonb: Ktulchu\AppLog\RawJsonbType
```

Entity values are valid JSON **strings** (`'{}'`, `'[]'`, `'null'`, `'1'`, `'"1"'`) or PHP `null` for SQL NULL. These mappings preserve the source document; the audit values themselves remain typed PostgreSQL JSON, not stringified substitutes. PostgreSQL validates input. Built-in Doctrine `json` mappings also work within their hydration limitations. JSONB inherently loses original formatting and duplicate keys before audit.

## Transaction contract

Every flush involving audited entities must run inside an outer transaction:

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

`onFlush` runs before Doctrine's internal transaction. It requires the outer transaction, inspects scheduled changes, validates mappings, locks existing rows with `FOR UPDATE` and reads before snapshots. `postFlush` runs after the inner flush transaction but inside the required outer transaction. It reads the final stored values and inserts history through the same DBAL connection. No model is re-saved, no audit entity is persisted and no recursive flush occurs. PostgreSQL performs JSON serialization without a PHP numeric conversion.

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

## Scope and limitations

* v0.1 supports PostgreSQL and single-table entities with one scalar identifier; inheritance and composite/association identifiers fail explicitly.
* Direct SQL, DQL bulk writes, database cascades, association-only changes and unmarked entities are not audited. No field-level recursive diff is generated.
* All entity changes must be scheduled before this listener's `onFlush`; do not mutate audited entities or issue untracked writes in later flush listeners. Deferred triggers executed only at outer commit are outside the snapshot boundary.
* Excluding fields is a security decision: choose exclusions before using the package. There is no automatic actor lookup, retention, encryption, soft-delete interpretation or domain identifier translation.
* A status-field update is an ordinary update. Domain-specific soft deletes and display callbacks from a legacy application must be implemented by that application; they are not silently imported.
* Each update requires a locked before snapshot, a final read, JSONB equality queries and an audit insert. This intentionally favors correctness over bulk-write throughput. Normal application concurrency/deadlock retry policy still applies.
* This is a new generic table, with no automatic import or modification of a legacy audit table. Existing history stays readable through its existing application. No proprietary application code or data is included.
