<?php
namespace lolbot\entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[ORM\Entity]
#[ORM\Table("user_hostmasks")]
#[ORM\UniqueConstraint(name: "user_hostmasks_user_mask_uniq", columns: ["user_id", "mask"])]
class UserHostmask
{
    //Cant be readonly due to doctrine bug on remove
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public int $user_id;

    #[ORM\Column]
    public string $mask;

    #[ORM\Column(name: "added_by")]
    public string $addedBy;

    #[ORM\Column(updatable: false)]
    public \DateTimeImmutable $created;

    public function __construct()
    {
        $this->created = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return "id: $this->id user: $this->user_id mask: $this->mask added_by: $this->addedBy"
            . " created: " . $this->created->format('r');
    }
}
