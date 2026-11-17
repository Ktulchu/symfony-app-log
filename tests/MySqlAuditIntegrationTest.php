<?php
declare(strict_types=1);

namespace Ktulchu\AppLog\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
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
use Ktulchu\AppLog\MySqlMigrations\Version20261117000001;
use PHPUnit\Framework\TestCase;

final class MySqlAuditIntegrationTest extends TestCase
{
    private EntityManager $em;

    protected function setUp(): void
    {
        if (!Type::hasType('audit_json')) { Type::addType('audit_json', RawJsonType::class); }
        $db = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
            'user' => 'audit',
            'password' => getenv('MYSQL_PASSWORD') ?: 'audit',
            'dbname' => 'audit',
            'charset' => 'utf8mb4',
        ]);
        // Only run this suite with an isolated, disposable database named "audit".
        $db->executeStatement('DROP TABLE IF EXISTS app_log, mysql_audit_fixture, doctrine_migration_versions');
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__], true);
        if (PHP_VERSION_ID >= 80400) { $config->enableNativeLazyObjects(true); }
        $this->em = new EntityManager($db, $config);
        $this->em->getEventManager()->addEventSubscriber(new AuditSubscriber());
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(MySqlRecord::class)]);
        $factory = DependencyFactory::fromConnection(new ConfigurationArray([
            'migrations_paths' => ['Ktulchu\\AppLog\\MySqlMigrations' => dirname(__DIR__) . '/src/MySqlMigrations'],
        ]), new ExistingConnection($db));
        $factory->getMetadataStorage()->ensureInitialized();
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion(
            new \Doctrine\Migrations\Version\Version(Version20261117000001::class)
        );
        $factory->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    protected function tearDown(): void { $this->em->getConnection()->close(); }

    private function create(): MySqlRecord
    {
        $record = new MySqlRecord();
        $this->em->wrapInTransaction(function () use ($record): void { $this->em->persist($record); });
        return $record;
    }

    private function rows(): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM app_log ORDER BY id');
    }

    public function testInsertUpdateDeleteAndExcludedFields(): void
    {
        $r = $this->create();
        $insert = json_decode($this->rows()[0]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('secret', $insert);
        self::assertSame('initial', $insert['title']['data']['value']);
        self::assertSame('json', $insert['document']['db_type']);

        $r->title = 'updated';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        $r->secret = 'secret changed';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        self::assertCount(2, $this->rows());

        $this->em->wrapInTransaction(function () use ($r): void { $this->em->remove($r); });
        self::assertSame(['insert', 'update', 'delete'], array_column($this->rows(), 'operation'));
        self::assertSame('updated', json_decode($this->rows()[2]['old_values'], true)['title']['data']['value']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mysql_audit_fixture'));
    }

    public function testMySqlJsonNormalizationAndSemanticNoop(): void
    {
        $r = $this->create();
        $r->document = '{"b":2,"a":1}';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        self::assertCount(1, $this->rows());

        $r->document = '{"a":2,"b":2}';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        self::assertCount(2, $this->rows());
        $row = json_decode($this->rows()[1]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $row['document']['data']['value']['a']);
        self::assertSame(false, $row['document']['data']['sql_null']);
    }

    public function testSqlNullVsJsonNullAndEmptyStructures(): void
    {
        $r = new MySqlRecord();
        $r->document = null;
        $this->em->wrapInTransaction(function () use ($r): void { $this->em->persist($r); });
        $row = json_decode($this->rows()[0]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($row['document']['data']['sql_null']);
        self::assertNull($row['document']['data']['value']);

        $r->document = 'null';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        $row = json_decode($this->rows()[1]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($row['document']['data']['sql_null']);
        self::assertSame('null', $row['document']['data']['raw']);

        foreach (['{}', '[]', '"string"', 'true', 'false', '0', '"0"'] as $value) {
            $r->document = $value;
            $this->em->wrapInTransaction(fn () => $this->em->flush());
        }
        self::assertCount(9, $this->rows());
        $last = json_decode($this->rows()[8]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('0', $last['document']['data']['value']);
    }

    public function testLargeNumbersAndArrayTypes(): void
    {
        $r = $this->create();
        $r->document = '{"n":123456789012345678901234567890,"values":[1,"1",false,null]}';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        $row = json_decode($this->rows()[1]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([1, '1', false, null], $row['document']['data']['value']['values']);
        $raw = (string) $this->em->getConnection()->fetchOne(
            'SELECT CAST(document AS CHAR) FROM mysql_audit_fixture WHERE id = ?', [$r->id]
        );
        self::assertSame($raw, $row['document']['data']['raw']);
        self::assertStringContainsString('123456789012345678901234567890', $raw);
    }

    public function testJsonLookingTextIsNotJson(): void
    {
        $r = $this->create();
        $r->title = '{"a":1}';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        $row = json_decode($this->rows()[1]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('varchar', $row['title']['db_type']);
        self::assertSame('{"a":1}', $row['title']['data']['value']);
    }

    public function testIdentifierHashUsesIndexedColumn(): void
    {
        $r = $this->create();
        $encoded = json_encode(['id' => $r->id], JSON_THROW_ON_ERROR);
        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM app_log WHERE entity_type = ? AND entity_id_hash = UNHEX(SHA2(?, 256)) AND entity_id = CAST(? AS JSON)',
            [MySqlRecord::class, $encoded, $encoded]
        );
        self::assertSame(1, (int) $count);
    }

    public function testRollbackRemovesBusinessAndAuditTogether(): void
    {
        $r = $this->create(); $db = $this->em->getConnection();
        $db->beginTransaction(); $r->title = 'rollback'; $this->em->flush();
        self::assertCount(2, $this->rows());
        $db->rollBack(); $this->em->clear();
        self::assertCount(1, $this->rows());
        self::assertSame('initial', $db->fetchOne('SELECT title FROM mysql_audit_fixture'));
    }

    public function testAuditFailureRollsBackBusinessWrite(): void
    {
        $r = $this->create(); $db = $this->em->getConnection();
        $db->executeStatement("ALTER TABLE app_log ADD CONSTRAINT reject_updates CHECK (operation <> 'update')");
        try {
            $this->em->wrapInTransaction(function () use ($r): void { $r->title = 'must roll back'; });
            self::fail('Expected audit constraint failure');
        } catch (Exception) {
            self::assertSame('initial', $db->fetchOne('SELECT title FROM mysql_audit_fixture'));
            self::assertCount(1, $this->rows());
        }
    }

    public function testFlushWithoutOuterTransactionRejectsBeforeBusinessWrite(): void
    {
        $record = new MySqlRecord(); $this->em->persist($record);
        try { $this->em->flush(); self::fail('Expected outer transaction exception'); }
        catch (\LogicException $e) { self::assertStringContainsString('outer transaction', $e->getMessage()); }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM mysql_audit_fixture'));
    }

    public function testDatabaseTriggerResultIsAudited(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement('CREATE TRIGGER upper_fixture BEFORE INSERT ON mysql_audit_fixture FOR EACH ROW SET NEW.title = UPPER(NEW.title)');
        $this->create();
        $row = json_decode($this->rows()[0]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('INITIAL', $row['title']['data']['value']);
    }

    public function testConcurrentConnectionRespectsRowLock(): void
    {
        $r = $this->create();
        $second = DriverManager::getConnection([
            'driver' => 'pdo_mysql', 'host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
            'user' => 'audit', 'password' => getenv('MYSQL_PASSWORD') ?: 'audit',
            'dbname' => 'audit', 'charset' => 'utf8mb4',
        ]);
        $db = $this->em->getConnection();
        $second->executeStatement('SET innodb_lock_wait_timeout = 1');
        $db->beginTransaction();
        try {
            $db->fetchOne('SELECT id FROM mysql_audit_fixture WHERE id = ? FOR UPDATE', [$r->id]);
            try {
                $second->fetchOne('SELECT id FROM mysql_audit_fixture WHERE id = ? FOR UPDATE', [$r->id]);
                self::fail('Competing row lock must time out');
            } catch (Exception) {
                // InnoDB prevents a conflicting lock while the first transaction is active.
            }
        } finally {
            $db->rollBack();
            $second->close();
        }
    }
}
