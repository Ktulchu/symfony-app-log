<?php
declare(strict_types=1);
namespace Ktulchu\AppLog;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/** Opt-in lossless JSON mapping: entity property is ?string containing valid JSON. */
class RawJsonType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string { return 'JSON'; }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value !== null && !is_string($value)) {
            throw new \InvalidArgumentException('app_log_json expects a JSON string or SQL NULL.');
        }
        return $value;
    }
}
