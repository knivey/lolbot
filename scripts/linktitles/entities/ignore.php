<?php
namespace scripts\linktitles\entities;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\Bot;
use lolbot\entities\Network;

#[ORM\Entity]
#[ORM\Table("linktitles_ignores")]
class ignore
{
    //Cant be readonly due to doctrine bug on remove
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public string $regex;

    #[ORM\Column]
    public ignore_type $type;

    #[ORM\Column(updatable: false)]
    public \DateTimeImmutable $created;

    #[ORM\ManyToOne(targetEntity: Network::class)]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id')]
    public ?Network $network = null;

    #[ORM\ManyToOne(targetEntity: Bot::class)]
    #[ORM\JoinColumn(name: 'bot_id', referencedColumnName: 'id')]
    public ?Bot $bot = null;

    //TODO after bot channels are added to database we can associate with them

    public function __construct(ignore_type $type)
    {
        $this->type = $type;
        $this->created = new \DateTimeImmutable();
    }

    public function __toString():string {
        return "id: {$this->id} regex: {$this->regex} type: {$this->type->toString()} created: ".$this->created->format('r');
    }
}
