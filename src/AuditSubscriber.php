<?php
declare(strict_types=1);

namespace Ktulchu\AppLog;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;

/** Database snapshots deliberately bypass lossy PHP JSON hydration. */
final class AuditSubscriber implements EventSubscriber
{
    /** @var list<array{entity: object, meta: ClassMetadata<object>, fields: list<string>, id: array<string, mixed>, old: array<string, array{type: string, raw: ?string, json: string}>, operation: string}> */
    private array $pending = [];

    /** @var array<string, array<string, string>> */
    private array $mysqlColumnTypes = [];

    public function getSubscribedEvents(): array
    {
        return [Events::onFlush, Events::postFlush, Events::onClear];
    }

    public function onClear(): void { $this->pending = []; }

    public function onFlush(OnFlushEventArgs $event): void
    {
        $this->pending = [];
        $em = $event->getObjectManager();
        $uow = $em->getUnitOfWork();
        foreach (['insert' => $uow->getScheduledEntityInsertions(), 'update' => $uow->getScheduledEntityUpdates(), 'delete' => $uow->getScheduledEntityDeletions()] as $operation => $entities) {
            foreach ($entities as $entity) {
                $meta = $em->getClassMetadata($entity::class);
                $attributes = $meta->getReflectionClass()->getAttributes(Audited::class);
                if ($attributes === []) { continue; }
                $db = $em->getConnection();
                if (!$db->getDatabasePlatform() instanceof PostgreSQLPlatform && !$db->getDatabasePlatform() instanceof MySQL80Platform) {
                    throw new \LogicException('AppLog requires PostgreSQL or MySQL 8.0.');
                }
                if (!$db->isTransactionActive()) {
                    throw new \LogicException('Audited flush requires an outer transaction; use EntityManager::wrapInTransaction().');
                }
                if ($meta->inheritanceType !== ClassMetadata::INHERITANCE_TYPE_NONE || $meta->isIdentifierComposite || $meta->containsForeignIdentifier) {
                    throw new \LogicException('AppLog v0.1 supports single-table entities with one scalar identifier.');
                }
                if ($meta->getTableName() === 'app_log') {
                    throw new \LogicException('The audit table cannot audit itself.');
                }
                $config = $attributes[0]->newInstance();
                foreach ($config->exclude as $field) {
                    if (!$meta->hasField($field)) { throw new \LogicException('Unknown excluded field: ' . $field); }
                }
                $fields = array_values(array_diff($meta->getFieldNames(), $config->exclude));
                if ($fields === []) { continue; }
                $id = $meta->getIdentifierValues($entity);
                $old = $operation === 'insert' ? [] : $this->snapshot($em, $meta, $fields, $id, true);
                $this->pending[] = compact('entity', 'meta', 'fields', 'id', 'old', 'operation');
            }
        }
    }

    public function postFlush(PostFlushEventArgs $event): void
    {
        $pending = $this->pending;
        $this->pending = [];
        $em = $event->getObjectManager();
        $db = $em->getConnection();
        foreach ($pending as $item) {
            if (!$db->isTransactionActive()) { throw new \LogicException('The outer audit transaction ended during flush.'); }
            ['entity' => $entity, 'meta' => $meta, 'fields' => $fields, 'old' => $old, 'operation' => $operation] = $item;
            $id = $operation === 'delete' ? $item['id'] : $meta->getIdentifierValues($entity);
            $new = $operation === 'delete' ? [] : $this->snapshot($em, $meta, $fields, $id, false);
            if ($operation === 'update') {
                foreach ($fields as $field) {
                    if (!$this->different($db, $old[$field], $new[$field])) { unset($old[$field], $new[$field]); }
                }
                if ($old === []) { continue; }
            }
            $idJson = json_encode($id, JSON_THROW_ON_ERROR);
            if ($db->getDatabasePlatform() instanceof MySQL80Platform) {
                $db->executeStatement(
                    'INSERT INTO app_log (entity_type, entity_id, entity_key, operation, old_values, new_values) VALUES (?, ?, ?, ?, ?, ?)',
                    [$meta->name, $idJson, hash('sha256', $idJson), $operation, $this->document($old), $this->document($new)]
                );
            } else {
                $db->executeStatement(
                    'INSERT INTO app_log (entity_type, entity_id, operation, old_values, new_values) VALUES (?, CAST(? AS jsonb), ?, CAST(? AS json), CAST(? AS json))',
                    [$meta->name, $idJson, $operation, $this->document($old), $this->document($new)]
                );
            }
        }
    }

    /** @param array{type: string, raw: ?string, json: string} $old
     *  @param array{type: string, raw: ?string, json: string} $new */
    private function different(Connection $db, array $old, array $new): bool
    {
        if ($db->getDatabasePlatform() instanceof MySQL80Platform && $old['type'] === 'json') {
            // MySQL JSON values are normalized; compare them as JSON, not serialized text.
            return !(bool) $db->fetchOne('SELECT CAST(? AS JSON) <=> CAST(? AS JSON)', [$old['raw'], $new['raw']]);
        }
        if ($old['type'] === 'jsonb') {
            return (bool) $db->fetchOne('SELECT CAST(? AS jsonb) IS DISTINCT FROM CAST(? AS jsonb)', [$old['raw'], $new['raw']]);
        }
        // JSON deliberately tracks source representation. Other columns use their DB text representation.
        return $old['raw'] !== $new['raw'];
    }

    /** @param ClassMetadata<object> $meta
     *  @param list<string> $fields
     *  @param array<string, mixed> $id
     *  @return array<string, array{type: string, raw: ?string, json: string}> */
    private function snapshot(EntityManagerInterface $em, ClassMetadata $meta, array $fields, array $id, bool $lock): array
    {
        $db = $em->getConnection();
        if ($db->getDatabasePlatform() instanceof MySQL80Platform) {
            return $this->mysqlSnapshot($em, $meta, $fields, $id, $lock);
        }
        $quote = $db->getDatabasePlatform()->quoteSingleIdentifier(...);
        $select = [];
        foreach ($fields as $i => $field) {
            $column = $quote($meta->getColumnName($field));
            $select[] = "pg_typeof($column)::text AS t$i";
            $select[] = "$column::text AS r$i";
            $select[] = "json_build_object('sql_null', CAST(IF($column IS NULL, 'true', 'false') AS JSON), 'value', $column, 'raw', $column::text)::text AS j$i";
        }
        $identifier = $meta->getSingleIdentifierFieldName();
        if (!array_key_exists($identifier, $id)) { throw new \LogicException('Missing entity identifier.'); }
        $table = $meta->getSchemaName() === null ? $quote($meta->getTableName()) : $quote($meta->getSchemaName()) . '.' . $quote($meta->getTableName());
        $row = $db->fetchAssociative('SELECT ' . implode(', ', $select) . ' FROM ' . $table . ' WHERE ' . $quote($meta->getColumnName($identifier)) . ' = ?' . ($lock ? ' FOR UPDATE' : ''), [$id[$identifier]], [$meta->getTypeOfField($identifier) ?? 'string']);
        if ($row === false) { throw new \RuntimeException('Audited row disappeared; flush aborted.'); }
        $result = [];
        foreach ($fields as $i => $field) {
            $result[$field] = ['type' => (string) $row['t' . $i], 'raw' => $row['r' . $i] === null ? null : (string) $row['r' . $i], 'json' => (string) $row['j' . $i]];
        }
        return $result;
    }

    /**
     * MySQL JSON columns return normalized documents. JSON_OBJECT keeps typed JSON values
     * and numeric precision on the server rather than decoding them in PHP.
     *
     * @param ClassMetadata<object> $meta
     * @param list<string> $fields
     * @param array<string, mixed> $id
     * @return array<string, array{type: string, raw: ?string, json: string}>
     */
    private function mysqlSnapshot(EntityManagerInterface $em, ClassMetadata $meta, array $fields, array $id, bool $lock): array
    {
        $db = $em->getConnection();
        $quote = $db->getDatabasePlatform()->quoteSingleIdentifier(...);
        $tableName = $meta->getTableName();
        $schemaName = $meta->getSchemaName();
        $key = ($schemaName ?? '') . '.' . $tableName;
        if (!isset($this->mysqlColumnTypes[$key])) {
            // Read the real SQL types: a VARCHAR containing JSON is not a JSON column.
            $this->mysqlColumnTypes[$key] = $db->fetchAllKeyValue(
                'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = COALESCE(?, DATABASE()) AND TABLE_NAME = ?',
                [$schemaName, $tableName]
            );
        }
        $types = $this->mysqlColumnTypes[$key];
        $select = [];
        foreach ($fields as $i => $field) {
            $columnName = $meta->getColumnName($field);
            if (!isset($types[$columnName])) {
                throw new \LogicException('Cannot resolve MySQL type for column: ' . $columnName);
            }
            $column = $quote($columnName);
            $raw = "CAST($column AS CHAR CHARACTER SET utf8mb4)";
            $select[] = "$raw AS r$i";
            $select[] = "CAST(JSON_OBJECT('sql_null', $column IS NULL, 'value', $column, 'raw', $raw) AS CHAR CHARACTER SET utf8mb4) AS j$i";
        }
        $identifier = $meta->getSingleIdentifierFieldName();
        if (!array_key_exists($identifier, $id)) { throw new \LogicException('Missing entity identifier.'); }
        $table = $schemaName === null ? $quote($tableName) : $quote($schemaName) . '.' . $quote($tableName);
        $row = $db->fetchAssociative(
            'SELECT ' . implode(', ', $select) . ' FROM ' . $table
            . ' WHERE ' . $quote($meta->getColumnName($identifier)) . ' = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$id[$identifier]],
            [$meta->getTypeOfField($identifier) ?? 'string']
        );
        if ($row === false) { throw new \RuntimeException('Audited row disappeared; flush aborted.'); }
        $result = [];
        foreach ($fields as $i => $field) {
            $result[$field] = [
                'type' => $types[$meta->getColumnName($field)],
                'raw' => $row['r' . $i] === null ? null : (string) $row['r' . $i],
                'json' => (string) $row['j' . $i],
            ];
        }
        return $result;
    }

    /** @param array<string, array{type: string, raw: ?string, json: string}> $snapshot */
    private function document(array $snapshot): string
    {
        $fields = [];
        foreach ($snapshot as $field => $value) {
            $fields[] = json_encode($field, JSON_THROW_ON_ERROR) . ':{"db_type":' . json_encode($value['type'], JSON_THROW_ON_ERROR) . ',"data":' . $value['json'] . '}';
        }
        return '{' . implode(',', $fields) . '}';
    }
}
