<?php
namespace lolbot\entities;

use Doctrine\Common\Collections\Collection;
use knivey\tools;
use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\IgnoreRepository;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[ORM\Entity(repositoryClass: IgnoreRepository::class)]
class Ignore
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected int $id;

    #[ORM\Column(length: 512)]
    protected string $hostmask;

    #[ORM\Column(length: 512, nullable: true)]
    protected ?string $reason;

    #[ORM\Column]
    protected \DateTime $created;

    #[ORM\Column]
    private bool $allBots = false;

    #[ORM\Column]
    private bool $allNetworks = false;

    #[ORM\ManyToMany(targetEntity: Bot::class, inversedBy: 'ignores')]
    private Collection $onlyBots;

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

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function __toString(): string
    {
        return "id: $this->id hostmask: $this->hostmask reason: $this->reason created: ".$this->created->format('r');
    }
}
