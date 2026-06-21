<?php
namespace lolbot\config;

/** Resolved linktitles config for a scope (channel → network → global → defaults applied).
 *  Each field holds its effective value; $sources says which tier each came from. */
final class LinktitlesResolved
{
    /**
     * @param array<string, string> $sources field => 'channel'|'network'|'global'|'default'
     * @param array<string, mixed>|null $aiVisionReasoning
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly ?string $urlLogChan,
        public readonly string $aiVisionModel,
        public readonly string $aiVisionPrompt,
        public readonly ?string $aiVisionReasoningEffort,
        public readonly ?array $aiVisionReasoning,
        public readonly bool $aiVisionDisabled,
        public readonly array $sources,
    ) {}
}
