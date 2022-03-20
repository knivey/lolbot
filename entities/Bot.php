<?php
namespace lolbot\entities;

use Doctrine\Common\Collections\Collection;
use knivey\tools;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Bot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected int $id;

    #[ORM\Column(length: 512)]
    protected string $name;

    #[ORM\Column]
    protected \DateTime $created;

    #[ORM\ManyToMany(targetEntity: Ignore::class, mappedBy: 'bots')]
    #[ORM\JoinTable(name: "ignore_bot")]
    private Collection $ignores;

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function __toString(): string
    {
        return "id: $this->id name: $this->name created: ".$this->created->format('r');
    }
}
