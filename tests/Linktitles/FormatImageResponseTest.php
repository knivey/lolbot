<?php

namespace Tests\Linktitles;

use PHPUnit\Framework\TestCase;
use scripts\linktitles\linktitles;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../library/Nicks.php';
require_once __DIR__ . '/../../library/Channels.php';

class FormatImageResponseTest extends TestCase
{
    private linktitles $lt;
    /** @var list<string> */
    private array $infoLogs = [];

    protected function setUp(): void
    {
        global $entityManager;
        $repo = $this->createStub(\Doctrine\Persistence\ObjectRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $entityManager = $this->createStub(\Doctrine\ORM\EntityManager::class);
        $entityManager->method('getRepository')->willReturn($repo);

        $network = $this->createStub(\lolbot\entities\Network::class);
        $bot = $this->createStub(\lolbot\entities\Bot::class);
        $bot->method('getChannels')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());
        $server = $this->createStub(\lolbot\entities\Server::class);
        $client = $this->createStub(\Irc\Client::class);
        $logger = $this->createStub(\Psr\Log\LoggerInterface::class);
        $this->infoLogs = [];
        $logger->method('info')->willReturnCallback(function (string $message): void {
            $this->infoLogs[] = $message;
        });
        $nicks = $this->createStub(\Nicks::class);
        $chans = $this->createStub(\Channels::class);
        $router = $this->createStub(\knivey\cmdr\Cmdr::class);
        $this->lt = new linktitles($network, $bot, $server, [], $client, $logger, $nicks, $chans, $router);
    }

    public function test_jpeg_with_dimensions(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/100x50_red.jpg');
        assert(is_string($img));
        $result = $this->lt->formatImageResponse($img, 'image/jpeg', '1234', '#test');
        $this->assertStringContainsString('jpeg image', $result);
        $this->assertStringContainsString('100x50', $result);
    }

    public function test_png_with_dimensions(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/200x100_blue.png');
        assert(is_string($img));
        $result = $this->lt->formatImageResponse($img, 'image/png', '5678', '#test');
        $this->assertStringContainsString('png image', $result);
        $this->assertStringContainsString('200x100', $result);
    }

    public function test_unknown_size_shows_question_mark(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/100x50_red.jpg');
        assert(is_string($img));
        $result = $this->lt->formatImageResponse($img, 'image/jpeg', null, '#test');
        $this->assertStringContainsString('?b', $result);
    }

    public function test_returns_without_brackets(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/100x50_red.jpg');
        assert(is_string($img));
        $result = $this->lt->formatImageResponse($img, 'image/jpeg', '1234', '#test');
        $this->assertDoesNotMatchRegularExpression('/^\[.*\]$/', $result);
    }

    public function test_oversize_header_dimensions_skip_ai_vision(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/bomb_header_60000x60000.png');
        assert(is_string($img));
        $result = $this->lt->formatImageResponse($img, 'image/png', '326', '#test');
        $this->assertStringContainsString('png image', $result);
        $this->assertStringContainsString('60000x60000', $result);
        $this->assertStringContainsString('ai_skipped=image_too_large 60000x60000', implode("\n", $this->infoLogs));
    }

    public function test_normal_image_does_not_skip_ai(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/200x100_blue.png');
        assert(is_string($img));
        $result = $this->lt->formatImageResponse($img, 'image/png', '5678', '#test');
        $this->assertStringContainsString('200x100', $result);
        $this->assertStringNotContainsString('ai_skipped', implode("\n", $this->infoLogs));
    }

    public function test_getAiDescription_ping_guard_skips_bomb_without_decoding(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/bomb_header_60000x60000.png');
        assert(is_string($img));
        [$result, $profile] = $this->invokeGetAiDescription($img, 'https://example.test/bomb.png');
        $this->assertNull($result);
        $this->assertStringContainsString('ai_skipped=image_too_large 60000x60000x1f', $profile);
        $this->assertStringNotContainsString('ai_error', $profile);
    }

    public function test_getAiDescription_ping_guard_skips_animated_bomb(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/animated_bomb_10f_1600x1600.gif');
        assert(is_string($img));
        [$result, $profile] = $this->invokeGetAiDescription($img, 'https://example.test/bomb.gif');
        $this->assertNull($result);
        $this->assertStringContainsString('ai_skipped=image_too_large 1600x1600x10f', $profile);
        $this->assertStringNotContainsString('ai_error', $profile);
    }

    /**
     * @return array{?string, string}
     */
    private function invokeGetAiDescription(string $img, string $url): array
    {
        global $entityManager;
        $ai = new \lolbot\entities\AiServiceConfig();
        $ai->apiKey = 'test-key';
        $repo = $this->createStub(\Doctrine\Persistence\ObjectRepository::class);
        $repo->method('findAll')->willReturn([$ai]);
        $entityManager = $this->createStub(\Doctrine\ORM\EntityManager::class);
        $entityManager->method('getRepository')->willReturn($repo);

        $getter = \Closure::bind(function (string $body, string $url, string $chan, string &$profile): ?string {
            return $this->getAiDescription($body, $url, $chan, $profile);
        }, $this->lt, linktitles::class);
        $profile = '';
        $result = $getter($img, $url, '#test', $profile);
        return [$result, $profile];
    }
}
