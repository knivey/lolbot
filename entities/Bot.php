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

    #[ORM\Column(nullable: true)]
    public ?string $trigger = null;

    #[ORM\Column(nullable: true)]
    public ?string $trigger_re = null;

    #[ORM\Column]
    public string $onConnect = "";

    #[ORM\Column(nullable: true)]
    public ?string $sasl_user = null;

    #[ORM\Column(nullable: true)]
    public ?string $sasl_pass = null;

    #[ORM\Column]
    public string $bindIp = "0";

    #[ORM\Column]
    public bool $disabled = false;

    #[ORM\Column(updatable: false)]
    public \DateTimeImmutable $created;

    #[ORM\ManyToOne(targetEntity: Network::class, inversedBy: "bots")]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id')]
    public Network $network;

    /**
     * @var Collection<int, Channel>
     */
    #[ORM\OneToMany(targetEntity: Channel::class, mappedBy: "bot")]
    protected Collection $channels;

    public function __construct()
    {
        $this->created = new \DateTimeImmutable();
        $this->channels = new ArrayCollection();
    }

    public function addChannel(Channel $channel): void {
        $channel->bot = $this;
        $this->channels[] = $channel;
    }

    /**
     * @return Collection<int, Channel>
     */
    public function getChannels(): Collection {
        return $this->channels;
    }

    /**
     * Effective disabled state: a bot is stopped when it or its network is disabled.
     */
    public function isDisabled(): bool
    {
        return $this->disabled || (isset($this->network) && $this->network->disabled);
    }

    public function __toString(): string
    {
        $s = "id: $this->id name: $this->name created: ".$this->created->format('r');
        if ($this->disabled) {
            $s .= " [disabled]";
        } elseif (isset($this->network) && $this->network->disabled) {
            $s .= " [disabled (network)]";
        }
        return $s;
    }
}
