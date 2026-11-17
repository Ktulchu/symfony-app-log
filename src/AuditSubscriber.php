<?php
declare(strict_types=1);

namespace Ktulchu\AppLog;

use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Connection;
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
                if (!$db->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                    throw new \LogicException('AppLog requires PostgreSQL.');
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
            $db->executeStatement(
                'INSERT INTO app_log (entity_type, entity_id, operation, old_values, new_values) VALUES (?, CAST(? AS jsonb), ?, CAST(? AS json), CAST(? AS json))',
                [$meta->name, json_encode($id, JSON_THROW_ON_ERROR), $operation, $this->document($old), $this->document($new)]
            );
        }
    }

    /** @param array{type: string, raw: ?string, json: string} $old
     *  @param array{type: string, raw: ?string, json: string} $new */
    private function different(Connection $db, array $old, array $new): bool
    {
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
        $quote = $db->getDatabasePlatform()->quoteSingleIdentifier(...);
        $select = [];
        foreach ($fields as $i => $field) {
            $column = $quote($meta->getColumnName($field));
            $select[] = "pg_typeof($column)::text AS t$i";
            $select[] = "$column::text AS r$i";
            $select[] = "json_build_object('sql_null', $column IS NULL, 'value', $column, 'raw', $column::text)::text AS j$i";
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
