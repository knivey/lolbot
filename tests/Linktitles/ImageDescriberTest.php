<?php

namespace Tests\Linktitles;

use lolbot\config\LinktitlesDefaults;
use lolbot\config\LinktitlesResolved;
use lolbot\entities\AiServiceConfig;
use PHPUnit\Framework\TestCase;
use scripts\linktitles\DescribeResult;
use scripts\linktitles\ImageDescriber;

require_once __DIR__ . '/../../vendor/autoload.php';

class ImageDescriberTest extends TestCase
{
    private function settings(): LinktitlesResolved
    {
        return new LinktitlesResolved(
            enabled: true,
            urlLogChan: null,
            aiVisionModel: LinktitlesDefaults::MODEL,
            aiVisionPrompt: LinktitlesDefaults::PROMPT,
            aiVisionReasoningEffort: null,
            aiVisionReasoning: null,
            aiVisionDisabled: false,
            sources: [],
        );
    }

    private function describer(): ImageDescriber
    {
        return new ImageDescriber($this->createStub(\Psr\Log\LoggerInterface::class));
    }

    public function test_ping_guard_skips_bomb_without_decoding(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/bomb_header_60000x60000.png');
        assert(is_string($img));
        $ai = new AiServiceConfig();
        $ai->apiKey = 'test-key';
        $result = $this->describer()->describe($img, $ai, $this->settings());
        $this->assertNull($result->description);
        $this->assertSame(DescribeResult::TOO_LARGE, $result->error);
        $this->assertSame('60000x60000x1f', $result->errorDetail);
    }

    public function test_ping_guard_skips_animated_bomb(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/animated_bomb_10f_1600x1600.gif');
        assert(is_string($img));
        $ai = new AiServiceConfig();
        $ai->apiKey = 'test-key';
        $result = $this->describer()->describe($img, $ai, $this->settings());
        $this->assertNull($result->description);
        $this->assertSame(DescribeResult::TOO_LARGE, $result->error);
        $this->assertSame('1600x1600x10f', $result->errorDetail);
    }

    public function test_garbage_bytes_are_undecodable(): void
    {
        $ai = new AiServiceConfig();
        $ai->apiKey = 'test-key';
        $result = $this->describer()->describe('not an image at all', $ai, $this->settings());
        $this->assertNull($result->description);
        $this->assertSame(DescribeResult::UNDECODABLE, $result->error);
    }
}
