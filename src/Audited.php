<?php
declare(strict_types=1);

namespace Ktulchu\AppLog;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Audited
{
    /** @param list<string> $exclude Doctrine field names, not SQL column names. */
    public function __construct(public readonly array $exclude = []) {}
}
