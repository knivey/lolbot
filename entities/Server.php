<?php

namespace lolbot\entities;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

#[ORM\Entity]
#[ORM\Table("Servers")]
class Server
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public string $address;

    #[ORM\Column]
    public int $port = 6667;

    #[ORM\Column]
    public bool $ssl = false;

    #[ORM\Column]
    public bool $throttle = true;

    #[ORM\ManyToOne(targetEntity: Network::class, inversedBy: "servers")]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id')]
    public Network $network;

    public function setNetwork(Network $network) {
        $this->network = $network;
        $network->addServer($this);
    }
}