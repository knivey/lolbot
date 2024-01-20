<?php
namespace lolbot\entities;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use knivey\tools;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table("Bots")]
class Bot
{
    //Cant be readonly due to doctrine bug on remove
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column(length: 512, unique: true)]
    public string $name;

    #[ORM\Column]
    public readonly \DateTimeImmutable $created;

    #[ORM\ManyToOne(targetEntity: Network::class, inversedBy: "bots")]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id')]
    public Network $network;

    public function __construct()
    {
        $this->created = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return "id: $this->id name: $this->name created: ".$this->created->format('r');
    }
}
