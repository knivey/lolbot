<?php
namespace lolbot\entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[ORM\Entity]
#[ORM\Table("user_settings")]
#[ORM\UniqueConstraint(name: "user_settings_scope_uniq", columns: ["user_id", "setting_key"])]
class UserSetting
{
    //Cant be readonly due to doctrine bug on remove
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public int $user_id;

    #[ORM\Column(name: "setting_key")]
    public string $settingKey;

    /** @var bool|int|string|null */
    #[ORM\Column(type: "json")]
    public mixed $value;

    #[ORM\Column(type: "datetime_immutable", nullable: true)]
    public ?\DateTimeImmutable $updated = null;
}
