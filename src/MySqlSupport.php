<?php
declare(strict_types=1);

namespace Ktulchu\AppLog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;

/** DBAL 4 retains the MySQL 5.7 platform; MariaDB is deliberately excluded. */
final class MySqlSupport
{
    public static function supports(Connection $db): bool
    {
        return $db->getDatabasePlatform() instanceof MySQLPlatform
            && version_compare($db->getServerVersion(), '5.7.13', '>=');
    }
}
