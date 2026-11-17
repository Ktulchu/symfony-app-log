<?php
declare(strict_types=1);
namespace Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ktulchu\AppLog\Audited;

#[ORM\Entity]
#[ORM\Table(name: 'mysql_example')]
#[Audited(exclude: ['secret'])]
class ExampleMySql
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $title = 'initial';

    #[ORM\Column(type: 'string')]
    public string $secret = 'do not audit';

    #[ORM\Column(type: 'app_log_json', nullable: true)]
    public ?string $document = '{}';
}
