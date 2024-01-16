<?php
namespace lolbot\entities;

use knivey\tools;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity]
#[ORM\Table("Networks")]
class Network
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected int $id;

    #[ORM\Column]
    protected string $name;

    #[ORM\Column]
    protected \DateTime $created;

    /**
     * @var Collection<int, Bot>
     */
    #[ORM\OneToMany(targetEntity: Bot::class, mappedBy: "network")]
    private Collection $bots;

    /**
     * @var Collection<int, Bot>
     */
    #[ORM\ManyToMany(targetEntity: Ignore::class, mappedBy: 'networks')]
    #[ORM\JoinTable(name: "Ignore_Network")]
    private Collection $ignores;

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->bots = new ArrayCollection();
        $this->ignores = new ArrayCollection();
    }

    public function getId() {
        return $this->id;
    }
    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }
    public function getName() {
        return $this->name;
    }

    public function addBot(Bot $bot) {
        $this->bots[] = $bot;
    }

    public function getBots() {
        return $this->bots;
    }

    public function getIgnores() {
        return $this->ignores;
    }

    public function addIgnore(Ignore $ignore) {
        $this->ignores[] = $ignore;
    }

    public function __toString():string {
        return "id: {$this->id} name: {$this->name} created: ".$this->created->format('r');
    }
}
