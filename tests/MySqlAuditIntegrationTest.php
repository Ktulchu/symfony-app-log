<?php
declare(strict_types=1);
namespace Ktulchu\AppLog\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Ktulchu\AppLog\AuditSubscriber;
use Ktulchu\AppLog\Migrations\Version20261118000000;
use Ktulchu\AppLog\Migrations\Version20261117000001;
use Ktulchu\AppLog\RawJsonType;
use PHPUnit\Framework\TestCase;

final class MySqlAuditIntegrationTest extends TestCase
{
    private EntityManager $em;
    private AuditSubscriber $audit;

    private static function dbParams(): array
    {
        return [
            'driver' => 'pdo_mysql',
            'host' => getenv('MYSQL_HOST') ?: '127.0.0.1',
            'user' => 'audit',
            'password' => 'audit',
            'dbname' => 'audit',
            'charset' => 'utf8mb4',
        ];
    }

    protected function setUp(): void
    {
        if (!getenv('MYSQL_HOST')) {
            self::markTestSkipped('Set MYSQL_HOST for disposable MySQL 8.0 integration tests.');
        }
        if (!Type::hasType('audit_json')) {
            Type::addType('audit_json', RawJsonType::class);
        }
        $db = DriverManager::getConnection(self::dbParams());
        $db->executeStatement('DROP TABLE IF EXISTS app_log, mysql_audit_fixture, mysql_plain_fixture, doctrine_migration_versions');
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__], true);
        if (PHP_VERSION_ID >= 80400) { $config->enableNativeLazyObjects(true); }
        $this->em = new EntityManager($db, $config);
        $this->audit = new AuditSubscriber();
        $this->em->getEventManager()->addEventSubscriber($this->audit);
        (new SchemaTool($this->em))->createSchema([
            $this->em->getClassMetadata(MySqlRecord::class),
            $this->em->getClassMetadata(MySqlPlainRecord::class),
        ]);
        $factory = DependencyFactory::fromConnection(
            new ConfigurationArray(['migrations_paths' => ['Ktulchu\\AppLog\\Migrations' => dirname(__DIR__) . '/migrations']]),
            new ExistingConnection($db)
        );
        $factory->getMetadataStorage()->ensureInitialized();
        $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion(
            new \Doctrine\Migrations\Version\Version(Version20261118000000::class)
        );
        $factory->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) { $this->em->getConnection()->close(); }
    }

    private function create(?string $json = '{"a":1,"b":2}'): MySqlRecord
    {
        $r = new MySqlRecord();
        $r->document = $json;
        $this->em->wrapInTransaction(fn () => $this->em->persist($r));
        return $r;
    }

    private function rows(): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM app_log ORDER BY id');
    }


    public function testScopedJobSourceRestoredOnMysql(): void
    {
        $r = $this->create();
        self::assertNull($this->rows()[0]['source_type']);
        $this->audit->withSourceType('queue:ImportJob', function () use ($r): void {
            $r->title = 'imported';
            $this->em->wrapInTransaction(fn () => $this->em->flush());
        });
        $r->title = 'manual';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        self::assertSame([null, 'queue:ImportJob', null], array_column($this->rows(), 'source_type'));
    }

    public function testMysqlMigrationAndIndexedEntityLookup(): void
    {
        $db = $this->em->getConnection();
        self::assertSame('json', strtolower((string)$db->fetchOne(
            "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_log' AND COLUMN_NAME = 'entity_id'"
        )));
        self::assertSame('3', (string)$db->fetchOne(
            "SELECT count(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_log' AND INDEX_NAME = 'app_log_entity_idx'"
        ));
        $r = $this->create();
        $idJson = json_encode(['id' => $r->id], JSON_THROW_ON_ERROR);
        self::assertSame(1, (int)$db->fetchOne(
            'SELECT count(*) FROM app_log WHERE entity_type = ? AND entity_key = ?',
            [MySqlRecord::class, hash('sha256', $idJson)]
        ));
        self::assertSame(['id' => $r->id], json_decode($this->rows()[0]['entity_id'], true));
    }

    public function testInsertUpdateDeleteAndExclusions(): void
    {
        $r = $this->create();
        self::assertCount(1, $this->rows());
        $new = json_decode($this->rows()[0]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('secret', $new);
        self::assertSame('initial', $new['title']['data']['value']);
        self::assertSame('json', $new['document']['db_type']);
        $this->em->wrapInTransaction(function () use ($r): void { $r->title = 'updated'; });
        $r->secret = 'ignored';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        self::assertCount(2, $this->rows());
        $this->em->wrapInTransaction(fn () => $this->em->remove($r));
        self::assertSame(['insert', 'update', 'delete'], array_column($this->rows(), 'operation'));
        self::assertSame('{}', $this->rows()[2]['new_values']);
    }

    public function testMysqlJsonNormalizationAndSemanticNoop(): void
    {
        $r = $this->create('{"a":1,"b":2}');
        $r->document = '{ "b" : 2, "a" : 1 }';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        self::assertCount(1, $this->rows(), 'Object ordering and spacing are not MySQL JSON changes');
        $r->document = '{"a":1,"b":[2,3]}';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        self::assertCount(2, $this->rows());
        $new = json_decode($this->rows()[1]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([2, 3], $new['document']['data']['value']['b']);
    }

    public function testSqlNullAndJsonNullAreDistinctAndTyped(): void
    {
        $r = $this->create(null);
        $first = json_decode($this->rows()[0]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(true, $first['document']['data']['sql_null']);
        self::assertNull($first['document']['data']['raw']);
        $r->document = 'null';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        $new = json_decode($this->rows()[1]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(false, $new['document']['data']['sql_null']);
        self::assertSame('null', $new['document']['data']['raw']);
        self::assertNull($new['document']['data']['value']);
        $r->document = '[]';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        $r->document = '{}';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        self::assertCount(4, $this->rows());
        $r->document = null;
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        self::assertCount(5, $this->rows());
    }

    public function testExact64BitJsonIntegersAreNotRoundedByPhp(): void
    {
        // MySQL documents exact comparison for adjacent signed 64-bit integers.
        $a = '9223372036854775805';
        $b = '9223372036854775806';
        $r = $this->create('{"n":' . $a . '}');
        $r->document = '{"n":' . $b . '}';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        self::assertCount(2, $this->rows());
        self::assertStringContainsString($a, $this->rows()[1]['old_values']);
        self::assertStringContainsString($b, $this->rows()[1]['new_values']);
    }

    public function testDuplicateJsonKeysReflectTheActualServerVersion(): void
    {
        $r = $this->create('{"a":1,"a":2}');
        $db = $this->em->getConnection();
        $stored = (string)$db->fetchOne('SELECT CAST(document AS CHAR) FROM mysql_audit_fixture WHERE id = ?', [$r->id]);
        $data = json_decode($this->rows()[0]['new_values'], true, flags: JSON_THROW_ON_ERROR)['document']['data'];
        self::assertSame($stored, $data['raw']);
        // MySQL 5.7 keeps the FIRST duplicate key; MySQL 8.0 keeps the LAST.
        $expected = version_compare($db->getServerVersion(), '8.0.0', '<') ? 1 : 2;
        self::assertSame($expected, $data['value']['a']);
        $r->document = '{"a":' . $expected . '}';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        self::assertCount(1, $this->rows(), 'Rewriting the same stored JSON must be a no-op');
    }

    public function testAuditIndexUsesDynamicRowFormatAndIndexedLookup(): void
    {
        $r = $this->create();
        $db = $this->em->getConnection();
        self::assertSame('Dynamic', $db->fetchOne("SELECT ROW_FORMAT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_log'"));
        $plan = $db->fetchAssociative('EXPLAIN SELECT id FROM app_log FORCE INDEX (app_log_entity_idx) WHERE entity_type = ? AND entity_key = ? ORDER BY id',
            [MySqlRecord::class, hash('sha256', json_encode(['id' => $r->id], JSON_THROW_ON_ERROR))]);
        self::assertSame('app_log_entity_idx', $plan['key']);
        self::assertSame('ref', $plan['type']);
    }

    public function testOutOfRangeJsonNumbersReflectStoredMysqlValue(): void
    {
        // Beyond MySQL JSON's numeric precision, two input literals may be
        // normalized to the same stored value; the audit cannot reconstruct them.
        $r = $this->create('{"n":123456789012345678901234567890}');
        $db = $this->em->getConnection();
        $before = $db->fetchOne('SELECT CAST(document AS CHAR) FROM mysql_audit_fixture WHERE id = ?', [$r->id]);
        $r->document = '{"n":123456789012345678901234567891}';
        $this->em->wrapInTransaction(fn () => $this->em->flush());
        $after = $db->fetchOne('SELECT CAST(document AS CHAR) FROM mysql_audit_fixture WHERE id = ?', [$r->id]);
        self::assertCount($before === $after ? 1 : 2, $this->rows());
    }

    public function testRollbackAndUnauditedEntities(): void
    {
        $plain = new MySqlPlainRecord();
        $this->em->persist($plain);
        $this->em->flush(); // Not audited: no outer transaction required.
        self::assertCount(0, $this->rows());
        $r = $this->create();
        $db = $this->em->getConnection();
        $db->beginTransaction();
        $r->title = 'rolled back';
        $this->em->flush();
        self::assertCount(2, $this->rows());
        $db->rollBack();
        $this->em->clear();
        self::assertCount(1, $this->rows());
        self::assertSame('initial', $db->fetchOne('SELECT title FROM mysql_audit_fixture'));
    }

    public function testInsertAndDeleteRollbacks(): void
    {
        $db = $this->em->getConnection();
        $db->beginTransaction();
        $r = new MySqlRecord();
        $this->em->persist($r);
        $this->em->flush();
        self::assertCount(1, $this->rows());
        $db->rollBack();
        $this->em->clear();
        self::assertCount(0, $this->rows());
        $r = $this->create();
        $db->beginTransaction();
        $this->em->remove($r);
        $this->em->flush();
        self::assertCount(2, $this->rows());
        $db->rollBack();
        $this->em->clear();
        self::assertCount(1, $this->rows());
        self::assertSame(1, (int)$db->fetchOne('SELECT count(*) FROM mysql_audit_fixture'));
    }

    public function testUnwrappedFlushFailsBeforeBusinessWrite(): void
    {
        $this->em->persist(new MySqlRecord());
        try {
            $this->em->flush();
            self::fail('Expected explicit outer transaction requirement');
        } catch (\LogicException $e) {
            self::assertStringContainsString('outer transaction', $e->getMessage());
        }
        self::assertSame(0, (int)$this->em->getConnection()->fetchOne('SELECT count(*) FROM mysql_audit_fixture'));
        self::assertCount(0, $this->rows());
    }

    public function testNonTransactionalBusinessTableIsRejectedBeforeInsert(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement('ALTER TABLE mysql_audit_fixture ENGINE=MyISAM');
        try {
            $this->create();
            self::fail('Expected InnoDB requirement');
        } catch (\LogicException $e) {
            self::assertStringContainsString('InnoDB', $e->getMessage());
        }
        self::assertSame(0, (int)$db->fetchOne('SELECT count(*) FROM mysql_audit_fixture'));
        self::assertCount(0, $this->rows());
    }

    public function testNonTransactionalAuditTableIsRejectedBeforeBusinessWrite(): void
    {
        $db = $this->em->getConnection();
        // MyISAM does not support the JSON columns in app_log on all MySQL versions.
        $db->executeStatement('DROP TABLE app_log');
        $db->executeStatement('CREATE TABLE app_log (id INT) ENGINE=MyISAM');
        try {
            $this->create();
            self::fail('Expected InnoDB requirement');
        } catch (\LogicException $e) {
            self::assertStringContainsString('InnoDB', $e->getMessage());
        }
        self::assertSame(0, (int)$db->fetchOne('SELECT count(*) FROM mysql_audit_fixture'));
    }

    public function testMysqlDatabaseTriggerNormalizationIsAudited(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement('CREATE TRIGGER mysql_audit_upper BEFORE UPDATE ON mysql_audit_fixture FOR EACH ROW SET NEW.title = UPPER(NEW.title)');
        $r = $this->create();
        $this->em->wrapInTransaction(function () use ($r): void { $r->title = 'updated'; });
        $new = json_decode($this->rows()[1]['new_values'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('UPDATED', $new['title']['data']['value']);
    }

    public function testAuditInsertFailureRollsBackBusinessInsert(): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement("CREATE TRIGGER reject_audit BEFORE INSERT ON app_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected audit failure'");
        try {
            $this->create();
            self::fail('Expected audit insertion failure');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('injected audit failure', $e->getMessage());
        }
        self::assertSame(0, (int)$db->fetchOne('SELECT count(*) FROM mysql_audit_fixture'));
        self::assertCount(0, $this->rows());
        self::assertFalse($this->em->isOpen());
    }

    public function testCompetingTransactionsRespectRowLock(): void
    {
        $r = $this->create();
        $first = $this->em->getConnection();
        $second = DriverManager::getConnection(self::dbParams());
        $first->beginTransaction();
        try {
            $first->fetchOne('SELECT id FROM mysql_audit_fixture WHERE id = ? FOR UPDATE', [$r->id]);
            $second->executeStatement('SET innodb_lock_wait_timeout = 1');
            $second->beginTransaction();
            try {
                $second->fetchOne('SELECT id FROM mysql_audit_fixture WHERE id = ? FOR UPDATE', [$r->id]);
                self::fail('Expected second transaction to respect first row lock');
            } catch (\Doctrine\DBAL\Exception\DriverException $e) {
                self::assertNotSame('', $e->getMessage());
            } finally {
                if ($second->isTransactionActive()) { $second->rollBack(); }
            }
        } finally {
            if ($first->isTransactionActive()) { $first->rollBack(); }
            $second->close();
        }
    }
}
