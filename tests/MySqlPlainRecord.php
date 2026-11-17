<?php
declare(strict_types=1);
namespace Ktulchu\AppLog\Tests;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'mysql_plain_fixture')]
class MySqlPlainRecord
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $title = 'plain';
}
