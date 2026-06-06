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
        if (!preg_match('@^https?://(?:www\.)?imgur\.com/gallery/([A-Za-z0-9-]+)$@i', $url, $m)) {
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

    /**
     * @return array{ext: string, id: string}|null
     */
    public static function isDirectUrl(string $url): ?array
    {
        if (!preg_match('@^https?://i\.imgur\.com/([A-Za-z0-9]+)\.(jpg|jpeg|png|gif|gifv|mp4|webm)$@i', $url, $m)) {
            return null;
        }
        return ['id' => $m[1], 'ext' => $m[2]];
    }

    private const IGNORED_PATHS = [
        'tos', 'privacy', 'settings', 'account', 'signup', 'login', 'register',
        'upgrade', 'credits', 'redeem', 'submit', 'upload', 'memegen',
        'trending', 'search', 'random', 'new', 'best', 'user', 'u',
    ];

    public static function isSingleImageUrl(string $url): ?string
    {
        if (!preg_match('@^https?://(?:www\.)?imgur\.com/([A-Za-z0-9]+)$@i', $url, $m)) {
            return null;
        }
        if (in_array(strtolower($m[1]), self::IGNORED_PATHS, true)) {
            return null;
        }
        return $m[1];
    }

    /**
     * @return array<mixed, mixed>|null
     */
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
        $raw = preg_replace("/\\'/", "'", $raw) ?? $raw;
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

    public static function extractImageIdsFromEmbed(string $html): ?array
    {
        preg_match_all('@i\.imgur\.com/([A-Za-z0-9]+)\.\w+@i', $html, $matches);
        if (empty($matches[1])) {
            return null;
        }
        return array_values(array_unique($matches[1]));
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
                    $this->handleAlbum($event, $albumId);
                } elseif ($singleId !== null) {
                    $this->handleSingleImage($event, $singleId);
                } elseif ($direct !== null) {
                    $this->handleDirect($event, $direct['id'], $direct['ext']);
                }
            } catch (\Exception $e) {
                echo "imgur handler exception: {$e->getMessage()}\n";
            }
        }));
    }

    private function getUserAgent(): string
    {
        $ua = $this->config['linktitles_useragent'] ?? null;
        if (is_string($ua) && $ua !== '') {
            return $ua;
        }
        return "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.108 Safari/537.36";
    }

    private function fetchHtml(string $url): string
    {
        $client = HttpClientBuilder::buildDefault();
        $req = new Request($url);
        $req->setHeader('User-Agent', $this->getUserAgent());
        $req->setHeader('Accept', 'text/html, application/xhtml+xml, application/xml;q=0.9, */*;q=0.8');
        $req->setTransferTimeout(4000);
        $req->setBodySizeLimit(1024 * 1024 * 2);
        $response = $client->request($req);
        if ($response->getStatus() !== 200) {
            throw new \RuntimeException("HTTP {$response->getStatus()} fetching $url");
        }
        return $response->getBody()->buffer();
    }

    /**
     * @return array{body: string, contentLength: string|null, contentType: string|null}
     */
    private function fetchDirectMedia(string $imageId, string $ext = 'jpg'): array
    {
        $client = HttpClientBuilder::buildDefault();
        $url = "https://i.imgur.com/{$imageId}.{$ext}";
        $req = new Request($url);
        $req->setHeader('User-Agent', $this->getUserAgent());
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

        $title = is_string($data['title'] ?? null) ? $data['title'] : '';
        $imageCount = is_int($data['image_count'] ?? null) ? $data['image_count'] : 0;
        $views = self::formatNumber(is_int($data['view_count'] ?? null) ? $data['view_count'] : 0);
        $points = is_int($data['point_count'] ?? null) ? $data['point_count'] : 0;
        $account = is_array($data['account'] ?? null) ? (is_string($data['account']['username'] ?? null) ? $data['account']['username'] : '') : (is_string($data['account'] ?? null) ? $data['account'] : '');
        $isAlbum = (bool)($data['is_album'] ?? false);

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
        $out = str_replace(["\r", "\n"], "  ", $out);
        $event->reply($out);
    }

    private function handleAlbum(UrlEvent $event, string $id): void
    {
        $embedUrl = "{$event->url}/embed";
        $html = $this->fetchHtml($embedUrl);
        $imageIds = self::extractImageIdsFromEmbed($html);
        if ($imageIds === null || count($imageIds) === 0) {
            return;
        }
        if (count($imageIds) > 1) {
            $out = "[Imgur] Album — " . count($imageIds) . " images";
            $event->reply($out);
            return;
        }
        $this->fetchAndFormatMedia($event, $imageIds[0]);
    }

    private function handleSingleImage(UrlEvent $event, string $id): void
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
