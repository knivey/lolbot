<?php

namespace scripts\linktitles;

use Amp\Http\Client\HttpClientBuilder;
use Amp\TimeoutCancellation;
use Knivey\OpenAi\HttpClient as OpenAiHttpClient;
use Knivey\OpenAi\OpenAiClient;
use Knivey\OpenAi\Request\ChatRequest;
use Knivey\OpenAi\Request\Message;
use Knivey\OpenAi\Request\Reasoning;
use Knivey\OpenAi\Request\Content\TextPart;
use Knivey\OpenAi\Request\Content\ImagePart;
use lolbot\config\LinktitlesResolved;
use lolbot\entities\AiServiceConfig;

/**
 * Shared AI image-description pipeline used by the in-channel linktitles
 * flow and the POST /aidesc REST endpoint: bomb-guard, resize/JPEG
 * re-encode, OpenAI-compatible vision call, control-char strip. Callers map
 * the typed DescribeResult to their own surfaces (profile tags / HTTP).
 */
class ImageDescriber
{
    /**
     * Shared in-process description cache, keyed by caller-chosen keys
     * (URL for the IRC path, sha256:... for the /aidesc endpoint). Static so
     * all bot instances and the endpoint share entries, like the previous
     * static on the linktitles class.
     * @var array<string, string>
     */
    public static array $descCache = [];

    public function __construct(private \Psr\Log\LoggerInterface $logger) {}

    public function describe(string $body, AiServiceConfig $ai, LinktitlesResolved $settings): DescribeResult
    {
        $profile = '';
        try {
            $maxDim = $ai->maxDim;
            $quality = $ai->jpgQuality;

            $resizeStart = hrtime(true);
            $img = new \Imagick();
            try {
                //pingImageBlob reads headers only, catches decompression bombs in formats
                //getimagesizefromstring can't parse before the full decode happens
                $ping = new \Imagick();
                try {
                    $ping->pingImageBlob($body);
                    $pingW = $ping->getImageWidth();
                    $pingH = $ping->getImageHeight();
                    //multi-frame images decode every frame, so frame count multiplies the cost
                    $pingFrames = max(1, $ping->getNumberImages());
                } finally {
                    $ping->clear();
                }
                if ($pingW * $pingH * $pingFrames > linktitles::maxAiPixels) {
                    return new DescribeResult(null, DescribeResult::TOO_LARGE, "{$pingW}x{$pingH}x{$pingFrames}f", $profile);
                }
                $img->readImageBlob($body);
                $origW = $img->getImageWidth();
                $origH = $img->getImageHeight();
                if ($origW * $origH * max(1, $img->getNumberImages()) > linktitles::maxAiPixels) {
                    return new DescribeResult(null, DescribeResult::TOO_LARGE, "{$origW}x{$origH}", $profile);
                }
                if ($origW > $maxDim || $origH > $maxDim) {
                    $img->thumbnailImage($maxDim, $maxDim, true);
                }
                $img->setImageFormat('jpeg');
                $img->setImageCompressionQuality($quality);
                $newW = $img->getImageWidth();
                $newH = $img->getImageHeight();
                $base64 = base64_encode($img->getImageBlob());
            } catch (\Exception $e) {
                return new DescribeResult(null, DescribeResult::UNDECODABLE, $e->getMessage(), $profile);
            } finally {
                $img->clear();
            }
            $resizeMs = (hrtime(true) - $resizeStart) / 1e6;
            $profile .= " resize=" . linktitles::formatDuration($resizeMs) . " {$origW}x{$origH}->{$newW}x{$newH} " . \knivey\tools\convert(strlen($body)) . "->" . \knivey\tools\convert((int)(strlen($base64) * 3 / 4));

            try {
                $aiStart = hrtime(true);
                $ampClient = HttpClientBuilder::buildDefault();
                $timeout = $ai->timeout;
                $openAiHttp = new OpenAiHttpClient($ai->apiKey ?? '', $ampClient, new TimeoutCancellation($timeout));
                $aiClient = new OpenAiClient(
                    apiKey: $ai->apiKey ?? '',
                    baseUrl: $ai->baseUrl ?? 'https://api.openai.com/v1',
                    httpClient: $openAiHttp,
                );

                $prompt = $settings->aiVisionPrompt;
                $model = $settings->aiVisionModel;
                $reasoningConfig = $settings->aiVisionReasoning;
                $reasoningEffort = $settings->aiVisionReasoningEffort;

                $reasoning = null;
                if ($reasoningConfig !== null) {
                    $effortVal = $reasoningConfig['effort'] ?? null;
                    $maxTokensVal = $reasoningConfig['max_tokens'] ?? null;
                    $reasoning = new Reasoning(
                        effort: is_string($effortVal) ? $effortVal : null,
                        maxTokens: is_int($maxTokensVal) ? $maxTokensVal : null,
                        exclude: isset($reasoningConfig['exclude']) ? (bool)$reasoningConfig['exclude'] : null,
                        enabled: isset($reasoningConfig['enabled']) ? (bool)$reasoningConfig['enabled'] : null,
                    );
                } elseif ($reasoningEffort !== null) {
                    $reasoning = Reasoning::effort($reasoningEffort);
                }

                $response = $aiClient->chatCompletion(new ChatRequest(
                    model: $model,
                    messages: [
                        Message::system($prompt),
                        Message::user([
                            new TextPart('describe this image'),
                            ImagePart::base64($base64, 'image/jpeg'),
                        ]),
                    ],
                    reasoning: $reasoning,
                ));
                $aiMs = (hrtime(true) - $aiStart) / 1e6;
                $profile .= " ai($model)=" . linktitles::formatDuration($aiMs);

                $description = $response->choices[0]->message->content ?? null;
                if ($description === null || trim($description) === '') {
                    return new DescribeResult(null, DescribeResult::EMPTY, '', $profile, $resizeMs + $aiMs);
                }
                $description = trim($description);
                $description = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F]/', '', $description);
                if ($description === null) {
                    return new DescribeResult(null, DescribeResult::EMPTY, '', $profile, $resizeMs + $aiMs);
                }
                return DescribeResult::success($description, $profile, $resizeMs + $aiMs);
            } catch (\Amp\TimeoutException $e) {
                return new DescribeResult(null, DescribeResult::TIMEOUT, $e->getMessage(), $profile);
            } catch (\Exception $e) {
                return new DescribeResult(null, DescribeResult::UPSTREAM, $e->getMessage(), $profile);
            }
        } catch (\Exception $e) {
            // Beyond the classified zones above (should be unreachable).
            $this->logger->debug('ImageDescriber unclassified failure: ' . $e->getMessage());
            return new DescribeResult(null, DescribeResult::UPSTREAM, $e->getMessage(), $profile);
        }
    }
}
