<?php
declare(strict_types=1);
namespace Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ktulchu\AppLog\Audited;

#[ORM\Entity]
#[ORM\Table(name: 'example')]
#[Audited(exclude: ['secret'])]
class Example
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    public ?int $id = null;
    #[ORM\Column(type: 'string')]
    public string $secret = 'do not audit';
    #[ORM\Column(type: 'app_log_json')]
    public string $document = '{}';
    #[ORM\Column(type: 'app_log_jsonb')]
    public string $payload = '{}';
}
