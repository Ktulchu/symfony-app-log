<?php
declare(strict_types=1);

namespace Ktulchu\AppLog\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\{MariaDBPlatform, MySQLPlatform, MySQL80Platform, PostgreSQLPlatform};
use Ktulchu\AppLog\MySqlSupport;
use PHPUnit\Framework\TestCase;

final class MySqlSupportTest extends TestCase
{
    public function testMinimumVersionAndPlatformBoundaries(): void
    {
        foreach ([
            [new MySQLPlatform(), '5.7.12', false],
            [new MySQLPlatform(), '5.7.13', true],
            [new MySQL80Platform(), '8.0.46', true],
            [new MariaDBPlatform(), '10.11.0-MariaDB', false],
            [new PostgreSQLPlatform(), '17.0', false],
        ] as [$platform, $version, $expected]) {
            $db = $this->createStub(Connection::class);
            $db->method('getDatabasePlatform')->willReturn($platform);
            $db->method('getServerVersion')->willReturn($version);
            self::assertSame($expected, MySqlSupport::supports($db), $version);
        }
    }
}
