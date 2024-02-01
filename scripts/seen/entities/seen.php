<?php
namespace scripts\seen\entities;
use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\Network;
#[ORM\Entity]
#[ORM\Table("seen_seens")]
class seen
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public string $nick;

    #[ORM\Column]
    public string $orig_nick;

    #[ORM\Column]
    public string $chan;

    #[ORM\Column]
    public string $text;

    #[ORM\Column]
    public string $action;

    #[ORM\Column]
    public \DateTime $time;

    #[ORM\ManyToOne(targetEntity: Network::class)]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id')]
    public Network $network;

    public function __construct()
    {
        $this->time = new \DateTime();
    }
}