<?php
namespace scripts\tell\entities;

use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\Network;
#[ORM\Entity]
#[ORM\Table("tell_tells")]
class tell
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public \DateTime $created;

    #[ORM\Column]
    public string $sender;

    #[ORM\Column]
    public string $msg;

    #[ORM\Column]
    public string $target;

    #[ORM\Column]
    public bool $sent = false;

    #[ORM\Column]
    public string $chan;

    #[ORM\ManyToOne(targetEntity: Network::class)]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id')]
    public ?Network $network = null;

    #[ORM\Column]
    public bool $global = false;

    public function __construct()
    {
        $this->created = new \DateTime();
    }
}