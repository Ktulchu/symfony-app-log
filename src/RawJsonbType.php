<?php
declare(strict_types=1);
namespace Ktulchu\AppLog;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

final class RawJsonbType extends RawJsonType
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        if (!$platform instanceof PostgreSQLPlatform) {
            throw new \LogicException('app_log_jsonb is PostgreSQL-only; use app_log_json on MySQL.');
        }
        return 'JSONB';
    }
}
