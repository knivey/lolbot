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
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected int $id;

    #[ORM\Column(length: 512, unique: true)]
    protected string $name;

    #[ORM\Column]
    protected \DateTime $created;

    #[ORM\ManyToOne(targetEntity: Network::class, inversedBy: "bots")]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id')]
    private Network $network;

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getId() {
        return $this->id;
    }

    public function setNetwork(Network $network)
    {
        $this->network = $network;
    }
    public function getNetwork(): Network
    {
        return $this->network;
    }

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->ignores = new ArrayCollection();
    }

    public function __toString(): string
    {
        return "id: $this->id name: $this->name created: ".$this->created->format('r');
    }
}
