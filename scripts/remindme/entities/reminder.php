<?php
namespace scripts\remindme\entities;

use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\Network;
#[ORM\Entity]
#[ORM\Table("remindme_reminders")]
class reminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public string $nick;

    #[ORM\Column]
    public string $chan;

    #[ORM\Column]
    public int $at;

    #[ORM\Column]
    public bool $sent = false;

    #[ORM\Column]
    public string $msg;

    #[ORM\ManyToOne(targetEntity: Network::class)]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id')]
    public ?Network $network = null;
}