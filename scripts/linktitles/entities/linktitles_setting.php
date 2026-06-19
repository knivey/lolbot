<?php
namespace scripts\linktitles\entities;

use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\Channel;
use lolbot\entities\Network;

#[ORM\Entity]
#[ORM\Table("linktitles_settings")]
#[ORM\UniqueConstraint(name: "scope_unique", columns: ["network_id", "channel_id"])]
class linktitles_setting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Network::class)]
    #[ORM\JoinColumn(name: 'network_id', referencedColumnName: 'id', nullable: true)]
    public ?Network $network = null;

    #[ORM\ManyToOne(targetEntity: Channel::class)]
    #[ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', nullable: true)]
    public ?Channel $channel = null;

    #[ORM\Column]
    public bool $ai_vision_disabled = false;

    #[ORM\Column]
    public bool $enabled = false;

    #[ORM\Column(nullable: true)]
    public ?string $url_log_chan = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $ai_vision_model = null;

    #[ORM\Column(type: "text", nullable: true)]
    public ?string $ai_vision_prompt = null;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $ai_vision_reasoning_effort = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: "json", nullable: true)]
    public ?array $ai_vision_reasoning = null;

    public function __toString(): string
    {
        $scope = $this->channel ? "channel:{$this->channel->name}" : "network:{$this->network?->name}";
        return "id: {$this->id} scope: $scope ai_vision_disabled: " . ($this->ai_vision_disabled ? 'true' : 'false');
    }
}
