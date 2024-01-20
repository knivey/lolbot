<?php

namespace scripts\weather\entities;
use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\Network;

#[ORM\Entity]
#[ORM\Table("weather_locations")]
class location
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public bool $si = false;

    #[ORM\Column]
    public string $name;

    #[ORM\Column]
    public string $lat;

    #[ORM\Column]
    public string $long;

    #[ORM\ManyToOne(targetEntity: Network::class)]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id')]
    public ?Network $network = null;

    #[ORM\Column]
    public string $nick;
}