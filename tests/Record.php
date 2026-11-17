<?php
declare(strict_types=1);
namespace Ktulchu\AppLog\Tests;

use Doctrine\ORM\Mapping as ORM;
use Ktulchu\AppLog\Audited;

#[ORM\Entity]
#[ORM\Table(name: 'audit_fixture')]
#[Audited(exclude: ['secret'])]
class Record
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    public ?int $id = null;
    #[ORM\Column(type: 'string')]
    public string $title = 'initial';
    #[ORM\Column(type: 'string')]
    public string $secret = 'excluded';
    // Raw JSON types preserve distinctions which Doctrine's built-in json hydration loses.
    #[ORM\Column(type: 'audit_json', nullable: true)]
    public ?string $document = '{"a":1,"b":2}';
    #[ORM\Column(name: 'payload_binary', type: 'audit_jsonb', nullable: true)]
    public ?string $binary = '{"a":1,"b":2}';
}
