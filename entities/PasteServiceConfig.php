<?php
namespace lolbot\entities;

use Doctrine\ORM\Mapping as ORM;

// Single-row global table.
#[ORM\Entity]
#[ORM\Table("paste_service_config")]
class PasteServiceConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column(nullable: true)]
    public ?string $host = null;

    #[ORM\Column(nullable: true)]
    public ?string $key = null;

    public function __toString(): string
    {
        return "paste service config: host=" . ($this->host ?? '(unset)') . " key=" . ($this->key !== null ? '(set)' : '(unset)');
    }
}
