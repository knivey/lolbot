<?php

namespace scripts\alias\entities;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\Network;

/**
 * Version history for aliases. Each row is one event in an alias's timeline:
 * 'save' (new value stored), 'removed' (alias deleted) or 'reverted' (marker
 * recording in `note` which version id was restored). Marker events carry a
 * null `value`.
 */
#[ORM\Entity]
#[ORM\Table("alias_history")]
class alias_history
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Network::class)]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id')]
    public Network $network;

    #[ORM\Column]
    public string $chan;

    #[ORM\Column]
    public string $chanLowered;

    #[ORM\Column]
    public string $name;

    #[ORM\Column]
    public string $nameLowered;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $value = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    public ?bool $act = null;

    #[ORM\Column(nullable: true)]
    public ?string $cmd = null;

    #[ORM\Column]
    public string $fullhost;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $created;

    #[ORM\Column]
    public string $event;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $note = null;
}
