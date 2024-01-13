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
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected int $id;

    #[ORM\Column]
    protected string $hostmask;

    #[ORM\Column(nullable: true)]
    protected ?string $reason = null;

    #[ORM\Column]
    protected \DateTime $created;

    #[ORM\ManyToMany(targetEntity: Network::class, inversedBy: 'ignores')]
    #[ORM\JoinTable(name: "Ignore_Network")]
    private Collection $networks;

    public function getId() {
        return $this->id;
    }

    public function matches(string $nickHost): bool {
        return (bool)preg_match(tools\globToRegex($this->hostmask) . 'i', $nickHost);
    }

    public function setReason(?string $reason): void {
        $this->reason = $reason;
    }

    /**
     * @param string $hostmask
     */
    public function setHostmask(string $hostmask): void
    {
        $this->hostmask = $hostmask;
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
        $this->created = new \DateTime();
        $this->networks = new ArrayCollection();
    }

    public function __toString(): string
    {
        return "id: $this->id hostmask: $this->hostmask reason: $this->reason created: ".$this->created->format('r');
    }
}
