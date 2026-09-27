<?php
namespace lolbot\entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[ORM\Entity]
#[ORM\Table("channel_flags")]
#[ORM\UniqueConstraint(name: "channel_flags_channel_user_uniq", columns: ["channel_id", "user_id"])]
class ChannelFlag
{
    //Cant be readonly due to doctrine bug on remove
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public int $channel_id;

    #[ORM\Column]
    public int $user_id;

    /** @var list<string> */
    #[ORM\Column(type: "json")]
    public array $flags = [];

    #[ORM\Column(name: "added_by")]
    public string $addedBy = "";

    #[ORM\Column(updatable: false)]
    public \DateTimeImmutable $created;

    public function __construct()
    {
        $this->created = new \DateTimeImmutable();
    }
}
