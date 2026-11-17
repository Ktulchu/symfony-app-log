<?php
declare(strict_types=1);
namespace Ktulchu\AppLog\Tests;

use Doctrine\ORM\Mapping as ORM;
use Ktulchu\AppLog\Audited;

#[ORM\Entity]
#[ORM\Table(name: 'mysql_audit_fixture')]
#[Audited(exclude: ['secret'])]
class MySqlRecord
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $title = 'initial';

    #[ORM\Column(type: 'string')]
    public string $secret = 'excluded';

    // Do not use app_log_jsonb on MySQL; that mapping is PostgreSQL-only.
    #[ORM\Column(type: 'audit_json', nullable: true)]
    public ?string $document = '{"a":1,"b":2}';
}
