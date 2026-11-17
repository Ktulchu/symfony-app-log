<?php
declare(strict_types=1);
namespace Ktulchu\AppLog\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Ktulchu\AppLog\AuditSubscriber;
use Ktulchu\AppLog\RawJsonType;
use Ktulchu\AppLog\RawJsonbType;
use Ktulchu\AppLog\Migrations\Version20261117000000;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditIntegrationTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        if (getenv('MYSQL_HOST') && !getenv('PGHOST')) { self::markTestSkipped('PostgreSQL suite runs in the PostgreSQL CI job.'); }
        foreach (['audit_json' => RawJsonType::class, 'audit_jsonb' => RawJsonbType::class] as $name => $class) {
            if (!Type::hasType($name)) { Type::addType($name, $class); }
        }
        $db = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => getenv('PGHOST') ?: '127.0.0.1', 'user' => 'audit', 'password' => 'audit', 'dbname' => 'audit']);
        $db->executeStatement('DROP TABLE IF EXISTS app_log, audit_fixture, doctrine_migration_versions CASCADE');
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__], true);
        if (PHP_VERSION_ID >= 80400) { $config->enableNativeLazyObjects(true); }
        $this->em = new EntityManager($db, $config);
        $this->em->getEventManager()->addEventSubscriber(new AuditSubscriber());
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(Record::class)]);
        $factory = DependencyFactory::fromConnection(new ConfigurationArray(['migrations_paths' => ['Ktulchu\\AppLog\\Migrations' => dirname(__DIR__) . '/migrations']]), new ExistingConnection($db));
        $factory->getMetadataStorage()->ensureInitialized();
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion(new \Doctrine\Migrations\Version\Version(Version20261117000000::class));
        $factory->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    protected function tearDown(): void { $this->em->getConnection()->close(); }

    private function create(): Record
    {
        $record = new Record();
        $this->em->wrapInTransaction(function () use ($record): void { $this->em->persist($record); });
        return $record;
    }

    private function rows(): array { return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM app_log ORDER BY id'); }

    public function testInsertUpdateDeleteAndNoRecursiveOrRepeatedRows(): void
    {
        $r = $this->create();
        self::assertCount(1, $this->rows());
        $created = json_decode($this->rows()[0]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('secret', $created);
        self::assertSame('initial', $created['title']['data']['value']);
        $this->em->wrapInTransaction(function () use ($r): void { $r->title = 'updated'; });
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        self::assertCount(2, $this->rows());
        $r->secret = 'changed';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        self::assertCount(2, $this->rows());
        $this->em->wrapInTransaction(function () use ($r): void { $this->em->remove($r); });
        self::assertSame(['insert', 'update', 'delete'], array_column($this->rows(), 'operation'));
        self::assertSame('{}', $this->rows()[2]['new_values']);
        self::assertSame('0', (string)$this->em->getConnection()->fetchOne('SELECT count(*) FROM audit_fixture'));
        self::assertSame('updated', json_decode($this->rows()[2]['old_values'], true)['title']['data']['value']);
    }

    public static function jsonCases(): iterable
    {
        yield 'object key order' => ['{"a":1,"b":2}', '{"b":2,"a":1}', false];
        yield 'spacing' => ['{"a":1}', '{ "a" : 1 }', false];
        yield 'numeric normalization' => ['1', '1.0', false];
        yield 'unicode escaping' => ['"\\u0410"', '"А"', false];
        yield 'duplicate keys' => ['{"a":0,"a":1}', '{"a":1}', false];
        yield 'number versus string' => ['1', '"1"', true];
        yield 'boolean versus number' => ['false', '0', true];
        yield 'boolean versus string' => ['true', '"true"', true];
        yield 'empty structures' => ['{}', '[]', true];
        yield 'null versus absent key' => ['{"a":null}', '{}', true];
        yield 'nested object' => ['{"x":{"a":1}}', '{"x":{"a":2}}', true];
        yield 'nested order' => ['{"x":{"a":1,"b":2}}', '{"x":{"b":2,"a":1}}', false];
        yield 'array order' => ['[1,2]', '[2,1]', true];
        yield 'add key' => ['{}', '{"x":false}', true];
        yield 'remove key' => ['{"x":0}', '{}', true];
        yield 'SQL null versus JSON null' => [null, 'null', true];
        yield 'JSON null versus SQL null' => ['null', null, true];
        yield 'empty string versus null' => ['""', 'null', true];
        yield 'large integer precision' => ['123456789012345678901234567890', '123456789012345678901234567891', true];
    }

    #[DataProvider('jsonCases')]
    public function testJsonAndJsonbUseDifferentContracts(?string $before, ?string $after, bool $binaryChanged): void
    {
        $r = new Record(); $r->document = $before; $r->binary = $before;
        $this->em->wrapInTransaction(function () use ($r): void { $this->em->persist($r); });
        $r->document = $after; $r->binary = $after;
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        $rows = $this->rows();
        self::assertCount(2, $rows);
        $old = json_decode($rows[1]['old_values'], true, flags: JSON_THROW_ON_ERROR);
        $new = json_decode($rows[1]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($before, $old['document']['data']['raw']);
        self::assertSame($after, $new['document']['data']['raw']);
        self::assertSame($before === null, $old['document']['data']['sql_null']);
        self::assertSame($after === null, $new['document']['data']['sql_null']);
        self::assertSame($binaryChanged, array_key_exists('binary', $new));
        // PostgreSQL examines the stored JSON number without a PHP float round trip.
        if ($before === '123456789012345678901234567890') {
            self::assertSame('number', $this->em->getConnection()->fetchOne("SELECT json_typeof(new_values->'document'->'data'->'value') FROM app_log WHERE operation='update'"));
            self::assertStringContainsString($after, $rows[1]['new_values']);
        }
    }

    public function testJsonbOnlySemanticNoopHasNoAudit(): void
    {
        $r = $this->create(); $r->binary = '{"b":2,"a":1.0}';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        self::assertCount(1, $this->rows());
    }

    public function testRollbackRemovesBusinessAndAuditChanges(): void
    {
        $r = $this->create(); $db = $this->em->getConnection();
        $db->beginTransaction(); $r->title = 'rolled back'; $this->em->flush();
        self::assertCount(2, $this->rows());
        $db->rollBack(); $this->em->clear();
        self::assertCount(1, $this->rows());
        self::assertSame('initial', $db->fetchOne('SELECT title FROM audit_fixture'));
    }

    public function testAuditFailureRollsBackBusinessWrite(): void
    {
        $r = $this->create(); $db = $this->em->getConnection();
        $db->executeStatement("ALTER TABLE app_log ADD CONSTRAINT reject_update CHECK (operation <> 'update')");
        try {
            $this->em->wrapInTransaction(function () use ($r): void { $r->title = 'must roll back'; });
            self::fail('Expected audit constraint failure');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            self::assertSame('initial', $db->fetchOne('SELECT title FROM audit_fixture'));
            self::assertCount(1, $this->rows());
        }
    }

    public function testFlushWithoutOuterTransactionFailsBeforeBusinessWrite(): void
    {
        $r = new Record(); $this->em->persist($r);
        try { $this->em->flush(); self::fail('Expected explicit transaction requirement'); }
        catch (\LogicException $e) { self::assertStringContainsString('outer transaction', $e->getMessage()); }
        self::assertSame('0', (string)$this->em->getConnection()->fetchOne('SELECT count(*) FROM audit_fixture'));
        self::assertCount(0, $this->rows());
    }

    public function testDatabaseTriggerResultIsAudited(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement("CREATE OR REPLACE FUNCTION audit_fixture_upper() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN NEW.title := upper(NEW.title); RETURN NEW; END'");
        $db->executeStatement('CREATE TRIGGER normalize_title BEFORE INSERT OR UPDATE ON audit_fixture FOR EACH ROW EXECUTE FUNCTION audit_fixture_upper()');
        $this->create();
        self::assertSame('INITIAL', json_decode($this->rows()[0]['new_values'], true)['title']['data']['value']);
    }

    public function testJsonLookingTextRemainsAnOrdinaryString(): void
    {
        $r = $this->create();
        $this->em->wrapInTransaction(function () use ($r): void { $r->title = '{"a":1}'; });
        $this->em->wrapInTransaction(function () use ($r): void { $r->title = '{ "a" : 1 }'; });
        self::assertCount(3, $this->rows());
        $new = json_decode($this->rows()[2]['new_values'], true);
        self::assertSame('{ "a" : 1 }', $new['title']['data']['value']);
        self::assertSame('character varying', $new['title']['db_type']);
    }

    public function testInsertAndDeleteRollback(): void
    {
        $db = $this->em->getConnection();
        $db->beginTransaction();
        $r = new Record(); $this->em->persist($r); $this->em->flush();
        self::assertCount(1, $this->rows());
        $db->rollBack(); $this->em->clear();
        self::assertCount(0, $this->rows());
        $r = $this->create();
        $db->beginTransaction(); $this->em->remove($r); $this->em->flush();
        self::assertCount(2, $this->rows());
        $db->rollBack(); $this->em->clear();
        self::assertCount(1, $this->rows());
        self::assertSame('1', (string)$db->fetchOne('SELECT count(*) FROM audit_fixture'));
    }
}
