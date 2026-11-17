<?php
declare(strict_types=1);

namespace Ktulchu\AppLog\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Create independent PostgreSQL entity audit history'; }

    public function up(Schema $schema): void
    {
        // The MySQL-specific migration runs separately. Keep PostgreSQL's schema unchanged.
        if ($this->connection->getDatabasePlatform() instanceof MySQL80Platform) { return; }
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform, 'PostgreSQL or MySQL 8.0 required');
        $this->addSql("CREATE TABLE app_log (id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY, entity_type TEXT NOT NULL, entity_id JSONB NOT NULL, operation TEXT NOT NULL CHECK (operation IN ('insert', 'update', 'delete')), occurred_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(), old_values JSON NOT NULL, new_values JSON NOT NULL)");
        $this->addSql('CREATE INDEX app_log_entity_idx ON app_log (entity_type, entity_id, id)');
        $this->addSql('CREATE INDEX app_log_occurred_idx ON app_log (occurred_at)');
    }

    public function down(Schema $schema): void
    {
        if ($this->connection->getDatabasePlatform() instanceof MySQL80Platform) { return; }
        $this->addSql('DROP TABLE app_log');
    }
}
