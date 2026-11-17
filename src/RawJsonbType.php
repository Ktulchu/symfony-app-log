<?php
declare(strict_types=1);
namespace Ktulchu\AppLog;

use Doctrine\DBAL\Platforms\AbstractPlatform;

final class RawJsonbType extends RawJsonType
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string { return 'JSONB'; }
}
