<?php
namespace lolbot\entities;

use Doctrine\ORM\Mapping as ORM;

// Single-row global table. ServiceLocator/ConfigService treat the first row as the singleton.
#[ORM\Entity]
#[ORM\Table("ai_service_config")]
class AiServiceConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(updatable: false)]
    public int $id;

    #[ORM\Column(name: 'api_key', length: 512, nullable: true)]
    public ?string $apiKey = null;

    #[ORM\Column(name: 'base_url', nullable: true)]
    public ?string $baseUrl = null;

    #[ORM\Column(name: 'max_dim')]
    public int $maxDim = 1024;

    #[ORM\Column(name: 'jpg_quality')]
    public int $jpgQuality = 85;

    #[ORM\Column]
    public int $timeout = 10;

    #[ORM\Column(name: 'reasoning_effort', length: 32, nullable: true)]
    public ?string $reasoningEffort = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: "json", nullable: true)]
    public ?array $reasoning = null;

    public function __toString(): string
    {
        return "ai service config: apiKey=" . ($this->apiKey !== null ? '(set)' : '(unset)')
            . " baseUrl=" . ($this->baseUrl ?? '(default)')
            . " maxDim={$this->maxDim} jpgQuality={$this->jpgQuality} timeout={$this->timeout}";
    }
}
