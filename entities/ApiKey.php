<?php
namespace lolbot\entities;

use Doctrine\ORM\Mapping as ORM;
use lolbot\entities\ApiKeyRepository;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
#[ORM\Table("api_keys")]
#[ORM\UniqueConstraint(name: "api_keys_key_uniq", columns: ["key"])]
class ApiKey
{
    //Known grantable scopes. 'aidesc' gates POST /aidesc; notifier migrates onto these keys later.
    public const SCOPES = ['aidesc'];

    //Cant be readonly due to doctrine bug on remove
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column(length: 64)]
    public string $key;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $label = null;

    /** @var list<string> */
    #[ORM\Column(type: "json")]
    public array $scopes = [];

    #[ORM\Column(updatable: false)]
    public \DateTimeImmutable $created;

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function __construct()
    {
        $this->created = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return "id: $this->id key: $this->key label: $this->label scopes: " . implode(',', $this->scopes)
            . " created: " . $this->created->format('r');
    }
}
