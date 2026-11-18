<?php
declare(strict_types=1);

namespace Ktulchu\AppLog\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Ktulchu\AppLog\MySqlSupport;

final class Version20261118000000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add nullable source_type to PostgreSQL and MySQL audit history'; }

    // MySQL DDL implicitly commits; this migration must not be transactional.
    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
                && !MySqlSupport::supports($this->connection),
            'PostgreSQL or MySQL 5.7.13+ required'
        );
        $this->addSql('ALTER TABLE app_log ADD COLUMN source_type VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform
                && !MySqlSupport::supports($this->connection),
            'PostgreSQL or MySQL 5.7.13+ required'
        );
        $this->addSql('ALTER TABLE app_log DROP COLUMN source_type');
    }
}
