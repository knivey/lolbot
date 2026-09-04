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
}
