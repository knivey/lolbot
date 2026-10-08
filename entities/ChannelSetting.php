<?php
namespace lolbot\entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[ORM\Entity]
#[ORM\Table("channel_settings")]
#[ORM\UniqueConstraint(name: "channel_settings_scope_uniq", columns: ["network_id", "channel_id", "setting_key"])]
class ChannelSetting
{
    //Cant be readonly due to doctrine bug on remove
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public int $network_id;

    #[ORM\Column(nullable: true)]
    public ?int $channel_id = null;

    #[ORM\Column(name: "setting_key")]
    public string $settingKey;

    /** @var bool|int|string|null */
    #[ORM\Column(type: "json")]
    public mixed $value;

    #[ORM\Column(type: "datetime_immutable", nullable: true)]
    public ?\DateTimeImmutable $updated = null;
}
