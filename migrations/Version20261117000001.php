<?php
declare(strict_types=1);

namespace Ktulchu\AppLog\Migrations;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117000001 extends AbstractMigration
{
    public function getDescription(): string { return 'Create independent MySQL 8.0 entity audit history'; }

    // MySQL DDL implicitly commits; Doctrine must not wrap this migration in a transaction.
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) { return; }
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof MySQL80Platform, 'MySQL 8.0 or PostgreSQL required');
        // Native JSON is not directly indexable in MySQL. The SHA-256 key hashes the
        // deterministic JSON-encoded single-field identifier map, not the business row.
        $this->addSql("CREATE TABLE app_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
            entity_id JSON NOT NULL,
            entity_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            operation VARCHAR(16) NOT NULL,
            occurred_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            old_values JSON NOT NULL,
            new_values JSON NOT NULL,
            INDEX app_log_entity_idx (entity_type, entity_key, id),
            INDEX app_log_occurred_idx (occurred_at),
            CONSTRAINT app_log_operation_check CHECK (operation IN ('insert', 'update', 'delete'))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) { return; }
        $this->addSql('DROP TABLE app_log');
    }
}
