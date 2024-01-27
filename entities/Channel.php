<?php

namespace lolbot\entities;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table("Channels")]
class Channel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public string $name;

    #[ORM\ManyToOne(targetEntity: Bot::class, inversedBy: "channels")]
    #[ORM\JoinColumn(name: 'bot_id', referencedColumnName: 'id')]
    public Bot $bot;
}