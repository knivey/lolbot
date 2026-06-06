# Imgur URL Handler Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an imgur URL event handler that extracts rich metadata from imgur gallery pages and proper image/video info from direct links, fixing the broken output currently produced by linktitles.

**Architecture:** New `scripts/imgur/imgur.php` handler class hooks into the existing URL event system. Shared image/video formatting logic is extracted from `linktitles` into public methods. The imgur handler receives the `linktitles` instance via `setEventProvider` and delegates media formatting to it.

**Tech Stack:** PHP 8.1+, Amp HTTP client, existing `script_base` / `UrlEvent` / `OrderedListenerProvider` infrastructure.

---

### Task 1: Extract formatImageResponse from linktitles

**Files:**
- Modify: `scripts/linktitles/linktitles.php`
- Create: `tests/Linktitles/FormatImageResponseTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Linktitles;

use PHPUnit\Framework\TestCase;
use scripts\linktitles\linktitles;

require_once __DIR__ . '/../../vendor/autoload.php';

class FormatImageResponseTest extends TestCase
{
    private linktitles $lt;

    protected function setUp(): void
    {
        $network = $this->createMock(\lolbot\entities\Network::class);
        $bot = $this->createMock(\lolbot\entities\Bot::class);
        $bot->method('getChannels')->willReturn([]);
        $server = $this->createMock(\lolbot\entities\Server::class);
        $client = $this->createMock(\Irc\Client::class);
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $nicks = $this->createMock(\Nicks::class);
        $chans = $this->createMock(\Channels::class);
        $router = $this->createMock(\knivey\cmdr\Cmdr::class);
        $this->lt = new linktitles($network, $bot, $server, [], $client, $logger, $nicks, $chans, $router);
    }

    public function test_jpeg_with_dimensions(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/100x50_red.jpg');
        $result = $this->lt->formatImageResponse($img, 'image/jpeg', '1234', '#test');
        $this->assertStringContainsString('jpeg image', $result);
        $this->assertStringContainsString('100x50', $result);
    }

    public function test_png_with_dimensions(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/200x100_blue.png');
        $result = $this->lt->formatImageResponse($img, 'image/png', '5678', '#test');
        $this->assertStringContainsString('png image', $result);
        $this->assertStringContainsString('200x100', $result);
    }

    public function test_unknown_size_shows_question_mark(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/100x50_red.jpg');
        $result = $this->lt->formatImageResponse($img, 'image/jpeg', null, '#test');
        $this->assertStringContainsString('?b', $result);
    }

    public function test_returns_without_brackets(): void
    {
        $img = file_get_contents(__DIR__ . '/../fixtures/100x50_red.jpg');
        $result = $this->lt->formatImageResponse($img, 'image/jpeg', '1234', '#test');
        $this->assertDoesNotMatchRegularExpression('/^\[.*\]$/', $result);
    }
}
```

- [ ] **Step 2: Create test fixtures**

Create small test images for the tests:

```bash
mkdir -p tests/fixtures
convert -size 100x50 xc:red tests/fixtures/100x50_red.jpg
convert -size 200x100 xc:blue tests/fixtures/200x100_blue.png
```

- [ ] **Step 3: Run test to verify it fails**

Run: `composer test -- tests/Linktitles/FormatImageResponseTest.php`
Expected: FAIL — `formatImageResponse` method does not exist yet

- [ ] **Step 4: Extract formatImageResponse method**

Add this public method to the `linktitles` class (after the `getAiDescription` method, around line 365):

```php
public function formatImageResponse(string $body, string $contentType, ?string $contentLength, string $chan, string $url = ''): string
{
    preg_match("@^image/(.*)$@i", $contentType, $m);
    $size = $contentLength;
    if ($size !== null && is_numeric($size))
        $size = \knivey\tools\convert((int)$size);
    else
        $size = "?b";
    $d = getimagesizefromstring($body);
    if (!$d) {
        $out = "$m[1] image $size";
    } else {
        $out = "$m[1] image $size $d[0]x$d[1]";
    }
    $cacheKey = $url ?: $chan;
    $aiDesc = $this->isAiVisionDisabled($chan) ? null : (self::$ai_desc_cache[$cacheKey] ?? $this->getAiDescription($body, $cacheKey));
    if ($aiDesc !== null) {
        $out = "$m[1] image $size" . ($d ? " $d[0]x$d[1]" : "") . " — $aiDesc";
    }
    return $out;
}
```

- [ ] **Step 5: Refactor the inline image block in linktitles() to use the new method**

Replace lines 153-172 (the `if (preg_match("@^image/(.*)$@i", ...))` block) with:

```php
if (preg_match("@^image/(.*)$@i", $response->getHeader("content-type"), $m)) {
    $out = "[ " . $this->formatImageResponse($body, $response->getHeader("content-type"), $response->getHeader("content-length"), $chan, $word) . " ]";
    $bot->pm($chan, "  $out");
    $this->logUrl($bot, $nick, $chan, $text, $out);
    self::$httpCache->set($cacheKey, $out, (int)($config['linktitles_cache_ttl'] ?? 900));
    continue;
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `composer test -- tests/Linktitles/FormatImageResponseTest.php`
Expected: PASS

- [ ] **Step 7: Run static analysis**

Run: `composer phpstan`
Expected: No errors

- [ ] **Step 8: Commit**

```bash
git add scripts/linktitles/linktitles.php tests/Linktitles/FormatImageResponseTest.php tests/fixtures/
git commit -m "refactor: extract formatImageResponse from linktitles"
```

---

### Task 2: Extract formatVideoResponse from linktitles

**Files:**
- Modify: `scripts/linktitles/linktitles.php`

- [ ] **Step 1: Extract formatVideoResponse method**

Add this public method to the `linktitles` class (after `formatImageResponse`):

```php
public function formatVideoResponse(string $body, string $ext, ?string $contentLength): string
{
    $size = $contentLength;
    if ($size !== null && is_numeric($size))
        $size = \knivey\tools\convert((int)$size);
    else
        $size = "?b";

    if (!shell_exec('which mediainfo')) {
        return "$ext video $size";
    }
    $fn = "tmp_" . bin2hex(random_bytes(8)) . ".$ext";
    file_put_contents($fn, $body);
    $mi = simplexml_load_string(shell_exec("mediainfo $fn --Output=XML"));
    unlink($fn);

    if (!isset($mi->media) || !isset($mi->media->track)) {
        echo "linktitles video error\n";
        var_dump($mi);
    }
    $vt = null;
    $at = null;
    foreach ($mi->media->track as $track) {
        if ($track['type'] == 'Video')
            $vt = $track;
        if ($track['type'] == 'Audio')
            $at = $track;
    }
    $videoFormat = $vt->Format;
    if (isset($vt->FrameRate))
        $frameRate = round((float)$vt->FrameRate) . 'fps';
    else
        $frameRate = $vt->FrameRate_Mode ?? '?';

    $resX = $vt->Width ?? '?';
    $resY = $vt->Height ?? '?';

    if (isset($vt->Duration)) {
        $dur = \Duration_toString((int)round((float)$vt->Duration)) . ' long';
    } else {
        $dur = 'unknown duration';
    }

    if ($at == null) {
        $audio = "No audio track";
    } else {
        $audio = "{$at->Format} audio";
    }

    return "$dur $ext video ({$videoFormat}) $size {$resX}x{$resY} @ {$frameRate}, $audio";
}
```

- [ ] **Step 2: Refactor the inline video block in linktitles() to use the new method**

Replace lines 174-233 (the `if (preg_match("@^video/(.*)$@i", ...))` block) with:

```php
if (preg_match("@^video/(.*)$@i", $response->getHeader("content-type"), $m)) {
    $out = "[ " . $this->formatVideoResponse($body, $m[1], $response->getHeader("content-length")) . " ]";
    $bot->pm($chan, "  $out");
    $this->logUrl($bot, $nick, $chan, $text, $out);
    self::$httpCache->set($cacheKey, $out, (int)($config['linktitles_cache_ttl'] ?? 900));
    continue;
}
```

- [ ] **Step 3: Run static analysis**

Run: `composer phpstan`
Expected: No errors

- [ ] **Step 4: Run full test suite**

Run: `composer test`
Expected: All existing tests pass, no regressions

- [ ] **Step 5: Commit**

```bash
git add scripts/linktitles/linktitles.php
git commit -m "refactor: extract formatVideoResponse from linktitles"
```

---

### Task 3: Create imgur handler with URL matching and postDataJSON parsing

**Files:**
- Create: `scripts/imgur/imgur.php`
- Create: `tests/Imgur/ParsePostDataJsonTest.php`
- Create: `tests/Imgur/ExtractImageIdTest.php`
- Create: `tests/Imgur/UrlMatchTest.php`

- [ ] **Step 1: Write URL matching tests**

```php
<?php

namespace Tests\Imgur;

use PHPUnit\Framework\TestCase;

class UrlMatchTest extends TestCase
{
    public static function galleryUrls(): array
    {
        return [
            'gallery with slug' => ['https://imgur.com/gallery/froggy-friday-AqeT58Y'],
            'gallery with id only' => ['https://imgur.com/gallery/AqeT58Y'],
            'gallery http' => ['http://imgur.com/gallery/slug-AqeT58Y'],
            'gallery www' => ['https://www.imgur.com/gallery/slug-AqeT58Y'],
        ];
    }

    public static function albumUrls(): array
    {
        return [
            'album' => ['https://imgur.com/a/FpuLRBp'],
            'album http' => ['http://imgur.com/a/FpuLRBp'],
            'album www' => ['https://www.imgur.com/a/FpuLRBp'],
        ];
    }

    public static function directUrls(): array
    {
        return [
            'jpeg' => ['https://i.imgur.com/vOFL64u.jpeg'],
            'jpg' => ['https://i.imgur.com/vOFL64u.jpg'],
            'png' => ['https://i.imgur.com/vOFL64u.png'],
            'gif' => ['https://i.imgur.com/vOFL64u.gif'],
            'gifv' => ['https://i.imgur.com/vOFL64u.gifv'],
            'mp4' => ['https://i.imgur.com/vOFL64u.mp4'],
            'http' => ['http://i.imgur.com/vOFL64u.jpeg'],
        ];
    }

    public static function singleImageUrls(): array
    {
        return [
            'single image' => ['https://imgur.com/vOFL64u'],
            'single image http' => ['http://imgur.com/vOFL64u'],
            'single image www' => ['https://www.imgur.com/vOFL64u'],
        ];
    }

    public static function nonImgurUrls(): array
    {
        return [
            'google' => ['https://google.com/foo'],
            'reddit' => ['https://reddit.com/r/test'],
            'imgur subpage' => ['https://imgur.com/tos'],
            'imgur settings' => ['https://imgur.com/settings'],
        ];
    }

    /**
     * @dataProvider galleryUrls
     */
    public function testGalleryMatch(string $url): void
    {
        $this->assertTrue(\scripts\imgur\imgur::isGalleryUrl($url));
    }

    /**
     * @dataProvider albumUrls
     */
    public function testAlbumMatch(string $url): void
    {
        $this->assertTrue(\scripts\imgur\imgur::isAlbumUrl($url));
    }

    /**
     * @dataProvider directUrls
     */
    public function testDirectMatch(string $url): void
    {
        $this->assertTrue(\scripts\imgur\imgur::isDirectUrl($url));
    }

    /**
     * @dataProvider singleImageUrls
     */
    public function testSingleImageMatch(string $url): void
    {
        $this->assertTrue(\scripts\imgur\imgur::isSingleImageUrl($url));
    }

    /**
     * @dataProvider nonImgurUrls
     */
    public function testNonImgurNoMatch(string $url): void
    {
        $this->assertNull(\scripts\imgur\imgur::isGalleryUrl($url));
        $this->assertNull(\scripts\imgur\imgur::isAlbumUrl($url));
        $this->assertNull(\scripts\imgur\imgur::isDirectUrl($url));
        $this->assertNull(\scripts\imgur\imgur::isSingleImageUrl($url));
    }
}
```

- [ ] **Step 2: Write postDataJSON parsing tests**

```php
<?php

namespace Tests\Imgur;

use PHPUnit\Framework\TestCase;

class ParsePostDataJsonTest extends TestCase
{
    public function testParsesValidJson(): void
    {
        $data = ['id' => 'AqeT58Y', 'title' => 'Froggy Friday', 'image_count' => 30, 'view_count' => 7570, 'point_count' => 261, 'is_album' => true, 'account' => ['username' => 'TestUser']];
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        $encoded = htmlspecialchars($json, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = '<html><body><div postDataJSON="' . $encoded . '"></div></body></html>';

        $result = \scripts\imgur\imgur::parsePostDataJson($html);
        $this->assertNotNull($result);
        $this->assertSame('AqeT58Y', $result['id']);
        $this->assertSame('Froggy Friday', $result['title']);
        $this->assertSame(30, $result['image_count']);
        $this->assertSame(7570, $result['view_count']);
        $this->assertSame(261, $result['point_count']);
    }

    public function testReturnsNullWhenNotFound(): void
    {
        $html = '<html><body>no data here</body></html>';
        $result = \scripts\imgur\imgur::parsePostDataJson($html);
        $this->assertNull($result);
    }

    public function testHandlesSingleQuoteEscapes(): void
    {
        $data = ['id' => 'abc', 'title' => "it's a test"];
        $json = json_encode($data);
        $encoded = str_replace("\\'", "'", htmlspecialchars($json, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $html = '<html><body><div postDataJSON="' . $encoded . '"></div></body></html>';

        $result = \scripts\imgur\imgur::parsePostDataJson($html);
        $this->assertNotNull($result);
        $this->assertSame("it's a test", $result['title']);
    }
}
```

- [ ] **Step 3: Write og:image extraction tests**

```php
<?php

namespace Tests\Imgur;

use PHPUnit\Framework\TestCase;

class ExtractImageIdTest extends TestCase
{
    public function testExtractsFromOgImage(): void
    {
        $html = '<meta property="og:image" content="https://i.imgur.com/vOFL64u.jpeg?fb">';
        $result = \scripts\imgur\imgur::extractImageIdFromHtml($html);
        $this->assertSame('vOFL64u', $result);
    }

    public function testExtractsFromOgImageWithoutFb(): void
    {
        $html = '<meta property="og:image" content="https://i.imgur.com/abc123.png">';
        $result = \scripts\imgur\imgur::extractImageIdFromHtml($html);
        $this->assertSame('abc123', $result);
    }

    public function testReturnsNullWhenNotFound(): void
    {
        $html = '<html><body>no image here</body></html>';
        $result = \scripts\imgur\imgur::extractImageIdFromHtml($html);
        $this->assertNull($result);
    }
}
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `composer test -- tests/Imgur/`
Expected: FAIL — class `scripts\imgur\imgur` does not exist

- [ ] **Step 5: Create the imgur handler class**

Create `scripts/imgur/imgur.php`:

```php
<?php

namespace scripts\imgur;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Crell\Tukio\OrderedProviderInterface;
use scripts\linktitles\linktitles;
use scripts\linktitles\UrlEvent;
use scripts\script_base;

class imgur extends script_base
{
    private ?linktitles $linktitles = null;

    public function setEventProvider(OrderedProviderInterface $eventProvider, ?linktitles $linktitles = null): void
    {
        $this->linktitles = $linktitles;
        $eventProvider->addListener($this->handleEvents(...));
    }

    public static function isGalleryUrl(string $url): ?string
    {
        if (!preg_match('@^https?://(?:www\.)?imgur\.com/gallery/([A-Za-z0-9]+)$@i', $url, $m)) {
            return null;
        }
        return $m[1];
    }

    public static function isAlbumUrl(string $url): ?string
    {
        if (!preg_match('@^https?://(?:www\.)?imgur\.com/a/([A-Za-z0-9]+)$@i', $url, $m)) {
            return null;
        }
        return $m[1];
    }

    public static function isDirectUrl(string $url): ?array
    {
        if (!preg_match('@^https?://i\.imgur\.com/([A-Za-z0-9]+)\.(jpg|jpeg|png|gif|gifv|mp4|webm)$@i', $url, $m)) {
            return null;
        }
        return ['id' => $m[1], 'ext' => $m[2]];
    }

    public static function isSingleImageUrl(string $url): ?string
    {
        if (!preg_match('@^https?://(?:www\.)?imgur\.com/([A-Za-z0-9]+)$@i', $url, $m)) {
            return null;
        }
        return $m[1];
    }

    public static function parsePostDataJson(string $html): ?array
    {
        $idx = strpos($html, 'postDataJSON="');
        if ($idx === false) {
            return null;
        }
        $start = $idx + strlen('postDataJSON="');
        $i = $start;
        while ($i < strlen($html)) {
            if ($html[$i] === '\\' && $i + 1 < strlen($html)) {
                $i += 2;
                continue;
            }
            if ($html[$i] === '"') {
                break;
            }
            $i++;
        }
        if ($i >= strlen($html)) {
            return null;
        }
        $raw = substr($html, $start, $i - $start);
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = str_replace('\"', '"', $raw);
        $raw = preg_replace("/\\'/", "'", $raw);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        return $data;
    }

    public static function extractImageIdFromHtml(string $html): ?string
    {
        if (!preg_match('@content="https://i\.imgur\.com/([A-Za-z0-9]+)\.\w+@i', $html, $m)) {
            return null;
        }
        return $m[1];
    }

    public function handleEvents(UrlEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $galleryId = self::isGalleryUrl($event->url);
        $albumId = self::isAlbumUrl($event->url);
        $direct = self::isDirectUrl($event->url);
        $singleId = self::isSingleImageUrl($event->url);

        if ($galleryId === null && $albumId === null && $direct === null && $singleId === null) {
            return;
        }

        $event->addFuture(\Amp\async(function () use ($event, $galleryId, $albumId, $direct, $singleId): void {
            try {
                if ($galleryId !== null) {
                    $this->handleGallery($event, $galleryId);
                } elseif ($albumId !== null) {
                    $this->handleAlbumOrSingle($event, $albumId);
                } elseif ($singleId !== null) {
                    $this->handleAlbumOrSingle($event, $singleId);
                } elseif ($direct !== null) {
                    $this->handleDirect($event, $direct['id'], $direct['ext']);
                }
            } catch (\Exception $e) {
                echo "imgur handler exception: {$e->getMessage()}\n";
            }
        }));
    }

    private function fetchHtml(string $url): string
    {
        $client = HttpClientBuilder::buildDefault();
        $req = new Request($url);
        $userAgent = $this->config['linktitles_useragent'] ?? "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.108 Safari/537.36";
        $req->setHeader('User-Agent', $userAgent);
        $req->setHeader('Accept', 'text/html, application/xhtml+xml, application/xml;q=0.9, */*;q=0.8');
        $req->setTransferTimeout(4000);
        $req->setBodySizeLimit(1024 * 1024 * 2);
        $response = $client->request($req);
        if ($response->getStatus() !== 200) {
            throw new \RuntimeException("HTTP {$response->getStatus()} fetching $url");
        }
        return $response->getBody()->buffer();
    }

    private function fetchDirectMedia(string $imageId, string $ext = 'jpg'): array
    {
        $client = HttpClientBuilder::buildDefault();
        $url = "https://i.imgur.com/{$imageId}.{$ext}";
        $req = new Request($url);
        $userAgent = $this->config['linktitles_useragent'] ?? "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.108 Safari/537.36";
        $req->setHeader('User-Agent', $userAgent);
        $req->setHeader('Accept', '*/*');
        $req->setTransferTimeout(4000);
        $req->setBodySizeLimit(1024 * 1024 * 16);
        $response = $client->request($req);
        if ($response->getStatus() === 302) {
            throw new \RuntimeException("Got redirect for $url, image may not exist");
        }
        if ($response->getStatus() !== 200) {
            throw new \RuntimeException("HTTP {$response->getStatus()} fetching $url");
        }
        return [
            'body' => $response->getBody()->buffer(),
            'contentType' => $response->getHeader('content-type'),
            'contentLength' => $response->getHeader('content-length'),
        ];
    }

    private function handleGallery(UrlEvent $event, string $galleryId): void
    {
        $html = $this->fetchHtml($event->url);
        $data = self::parsePostDataJson($html);
        if ($data === null) {
            return;
        }

        $title = $data['title'] ?? '';
        $imageCount = $data['image_count'] ?? 0;
        $views = self::formatNumber($data['view_count'] ?? 0);
        $points = $data['point_count'] ?? 0;
        $account = is_array($data['account'] ?? null) ? ($data['account']['username'] ?? '') : ($data['account'] ?? '');
        $isAlbum = ($data['is_album'] ?? false);

        $parts = [];
        if ($isAlbum && $imageCount > 1) {
            $parts[] = "{$imageCount} images";
        }
        $parts[] = "{$views} views";
        $parts[] = "{$points} pts";
        if ($account) {
            $parts[] = "by {$account}";
        }

        $out = "[Imgur] {$title} — " . implode(', ', $parts);
        $event->reply($out);
    }

    private function handleAlbumOrSingle(UrlEvent $event, string $id): void
    {
        $html = $this->fetchHtml($event->url);
        $imageId = self::extractImageIdFromHtml($html);
        if ($imageId === null) {
            return;
        }
        $this->fetchAndFormatMedia($event, $imageId);
    }

    private function handleDirect(UrlEvent $event, string $imageId, string $ext): void
    {
        $this->fetchAndFormatMedia($event, $imageId, $ext);
    }

    private function fetchAndFormatMedia(UrlEvent $event, string $imageId, string $ext = 'jpg'): void
    {
        $media = $this->fetchDirectMedia($imageId, $ext);
        if ($this->linktitles === null) {
            return;
        }

        $contentType = $media['contentType'] ?? '';
        $body = $media['body'];
        $contentLength = $media['contentLength'];

        if (preg_match('@^image/(.*)$@i', $contentType)) {
            $out = "[Imgur] " . $this->linktitles->formatImageResponse($body, $contentType, $contentLength, $event->chan, $event->url);
            $event->reply($out);
        } elseif (preg_match('@^video/(.*)$@i', $contentType, $m)) {
            $out = "[Imgur] " . $this->linktitles->formatVideoResponse($body, $m[1], $contentLength);
            $event->reply($out);
        }
    }

    public static function formatNumber(int $n): string
    {
        if ($n >= 1000000000) {
            return round($n / 1000000000, 1) . 'B';
        }
        if ($n >= 1000000) {
            return round($n / 1000000, 1) . 'M';
        }
        if ($n >= 1000) {
            return round($n / 1000, 1) . 'K';
        }
        return (string)$n;
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `composer test -- tests/Imgur/`
Expected: All tests PASS

- [ ] **Step 7: Run static analysis**

Run: `composer phpstan`
Expected: No errors (there may be issues with the PSR-4 autoloading — if so, the next step fixes it)

- [ ] **Step 8: Commit**

```bash
git add scripts/imgur/imgur.php tests/Imgur/
git commit -m "feat: add imgur URL handler with postDataJSON parsing and og:image extraction"
```

---

### Task 4: Register imgur handler in lolbot.php

**Files:**
- Modify: `lolbot.php`

- [ ] **Step 1: Add the use statement and registration**

In `lolbot.php`, add the import near the other handler imports (around line 30 where youtube, twitter, etc. are imported):

```php
use scripts\imgur\imgur;
```

Add the registration block after the tiktok registration (after line 219):

```php
$imgur = new imgur($network, $dbBot, $server, $config, $client, new Logger("{$dbBot->name}:imgur", [$logHandler]), $nicks, $chans, $router);
$imgur->setEventProvider($eventProvider, $linktitles);
$router->loadMethods($imgur);
```

- [ ] **Step 2: Run static analysis**

Run: `composer phpstan`
Expected: No errors

- [ ] **Step 3: Run full test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add lolbot.php
git commit -m "feat: register imgur URL handler in lolbot"
```

---

### Task 5: Review and final verification

- [ ] **Step 1: Run full static analysis**

Run: `composer phpstan`
Expected: No errors

- [ ] **Step 2: Run full test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 3: Run formatting check**

Run: `vendor/bin/php-cs-fixer fix --dry-run --diff scripts/imgur/ scripts/linktitles/`
Expected: No formatting issues (or fix and re-run without `--dry-run`)

- [ ] **Step 4: Verify spec compliance**

Re-read `docs/superpowers/specs/2026-06-06-imgur-url-handler-design.md` and confirm:
- All 4 URL patterns handled (gallery, album, single image, direct)
- Output formats match spec
- postDataJSON parsing implemented
- og:image extraction for album/single pages
- Direct fetch uses `Accept: */*` header
- `setEventProvider` accepts optional linktitles instance
- `formatImageResponse` and `formatVideoResponse` extracted and used
