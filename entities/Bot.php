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
    public ?string $trigger = null;

    #[ORM\Column]
    public ?string $trigger_re = null;

    #[ORM\Column]
    public string $onConnect = "";

    #[ORM\Column]
    public ?string $sasl_user;

    #[ORM\Column]
    public ?string $sasl_pass;

    #[ORM\Column]
    public string $bindIp = "0";

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

    public function addChannel(Channel $channel) {
        $channel->bot = $this;
        $this->channels[] = $channel;
    }

    public function getChannels() {
        return $this->channels;
    }

    public function __toString(): string
    {
        return "id: $this->id name: $this->name created: ".$this->created->format('r');
    }
}
