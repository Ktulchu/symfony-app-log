<?php
declare(strict_types=1);

namespace Ktulchu\AppLog\MySqlMigrations;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117000001 extends AbstractMigration
{
    public function getDescription(): string { return 'Create independent MySQL 8.0 audit history'; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof MySQLPlatform, 'MySQL 8.0+ required');
        // Preserve entity_id as JSON; index a SHA-256 of its canonical input encoding.
        $this->addSql("CREATE TABLE app_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(255) NOT NULL,
            entity_id JSON NOT NULL,
            entity_id_hash BINARY(32) NOT NULL,
            operation VARCHAR(10) NOT NULL,
            occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            old_values JSON NOT NULL,
            new_values JSON NOT NULL,
            CONSTRAINT app_log_operation_chk CHECK (operation IN ('insert', 'update', 'delete')),
            INDEX app_log_entity_idx (entity_type, entity_id_hash, id),
            INDEX app_log_occurred_idx (occurred_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin");
    }

    public function down(Schema $schema): void { $this->addSql('DROP TABLE app_log'); }
}
