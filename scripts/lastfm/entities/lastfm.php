<?php

namespace scripts\lastfm\entities;

use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\Bot;
use lolbot\entities\Network;

#[ORM\Entity]
#[ORM\Table("lastfm_users")]
class lastfm
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public string $lastfmUser;

    #[ORM\Column]
    public string $nick;

    #[ORM\ManyToOne(targetEntity: Network::class)]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id')]
    public Network $network;
}