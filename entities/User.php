<?php
namespace lolbot\entities;

use Doctrine\ORM\Mapping as ORM;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[ORM\Entity]
#[ORM\Table("users")]
#[ORM\UniqueConstraint(name: "users_network_name_uniq", columns: ["network_id", "nameLowered"])]
class User
{
    //Cant be readonly due to doctrine bug on remove
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column]
    public int $network_id;

    #[ORM\Column]
    public string $name;

    #[ORM\Column]
    public string $nameLowered;

    #[ORM\Column(name: "pass_hash", nullable: true)]
    public ?string $passHash = null;

    /** @var list<string> */
    #[ORM\Column(type: "json")]
    public array $flags = [];

    #[ORM\Column]
    public bool $paranoid = false;

    #[ORM\Column(updatable: false)]
    public \DateTimeImmutable $created;

    public function __construct()
    {
        $this->created = new \DateTimeImmutable();
    }

    /**
     * Pure flag-op helper shared by the CLI and future auth paths.
     * '+' adds the flag (dedupe, keep first-seen order), '-' removes it
     * (every occurrence); anything else is rejected.
     *
     * @param list<string> $flags
     * @return list<string>
     */
    public static function applyFlag(array $flags, string $op, string $flag): array
    {
        if ($flag === "") {
            throw new \InvalidArgumentException("flag name must not be empty");
        }
        if ($op === "+") {
            $flags[] = $flag;
            return array_values(array_unique($flags));
        }
        if ($op === "-") {
            return array_values(array_filter($flags, static fn (string $f): bool => $f !== $flag));
        }
        throw new \InvalidArgumentException("unknown flag op '$op' (expected '+' or '-')");
    }

    public function __toString(): string
    {
        return "id: $this->id network: $this->network_id name: $this->name flags: " . implode(',', $this->flags)
            . " created: " . $this->created->format('r');
    }
}
