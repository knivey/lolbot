<?php
namespace lolbot\entities;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use knivey\tools;
use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\IgnoreRepository;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[ORM\Entity(repositoryClass: IgnoreRepository::class)]
#[ORM\Table("Ignores")]
class Ignore
{
    //Cant be readonly due to doctrine bug on remove
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public string $hostmask;

    #[ORM\Column(nullable: true)]
    public ?string $reason = null;

    #[ORM\Column]
    public readonly \DateTimeImmutable $created;

    #[ORM\ManyToMany(targetEntity: Network::class, inversedBy: 'ignores')]
    #[ORM\JoinTable(name: "Ignore_Network")]
    protected Collection $networks;

    public function matches(string $nickHost): bool {
        return (bool)preg_match(tools\globToRegex($this->hostmask) . 'i', $nickHost);
    }

    public function assignedToNetwork(Network $network): bool {
        return $this->networks->contains($network);
    }

    public function addToNetwork(Network $network) {
        $network->addIgnore($this);
        $this->networks[] = $network;
    }

    public function getNetworks() {
        return $this->networks;
    }

    public function __construct()
    {
        $this->created = new \DateTimeImmutable();
        $this->networks = new ArrayCollection();
    }

    public function __toString(): string
    {
        return "id: $this->id hostmask: $this->hostmask reason: $this->reason created: ".$this->created->format('r');
    }
}
