<?php

namespace scripts\alias\entities;
use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\Bot;
use lolbot\entities\Network;

#[ORM\Entity]
#[ORM\Table("alias_aliases")]
class alias
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public string $name;

    #[ORM\Column]
    public string $nameLowered;

    #[ORM\Column]
    public string $value;

    #[ORM\Column]
    public string $chan;

    #[ORM\Column]
    public string $chanLowered;

    #[ORM\Column]
    public string $fullhost;

    #[ORM\Column]
    public bool $act = false;

    #[ORM\Column]
    public ?string $cmd = null;

    #[ORM\ManyToOne(targetEntity: Network::class)]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id')]
    public ?Network $network = null;
}