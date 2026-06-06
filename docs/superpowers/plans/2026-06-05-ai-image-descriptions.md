# AI Image Descriptions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Augment image URL info in IRC with AI-generated descriptions using `knivey/amphp-openai`.

**Architecture:** Inline in `linktitles.php`'s image handler. When an image URL is detected and `ai_vision_key` is configured, resize the image with Imagick, send as base64 to an OpenAI-compatible API, and append the description to the existing format/size/dimensions output. Graceful fallback to basic output if no API key or on API errors.

**Tech Stack:** PHP 8.1+, `knivey/amphp-openai` ^1.0, `ext-imagick`, `amphp/http-client` ^5.0

---

### Task 1: Add composer dependency

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Require the package**

Run: `composer require knivey/amphp-openai ^1.0`

- [ ] **Step 2: Verify installation**

Run: `composer show knivey/amphp-openai`
Expected: Shows package info with version >= 1.0

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "feat: add knivey/amphp-openai dependency for AI image descriptions"
```

---

### Task 2: Add config options to config.example.yaml

**Files:**
- Modify: `config.example.yaml`

- [ ] **Step 1: Add AI vision config block**

Add after the existing `linktitles_useragent` block (after line 112):

```yaml

# AI vision settings for image URL descriptions (requires knivey/amphp-openai)
#ai_vision_key: "sk-..."
#ai_vision_base_url: "https://api.openai.com/v1"
#ai_vision_model: "gpt-4o"
#ai_vision_max_dim: 1024
#ai_vision_jpg_quality: 85
#ai_vision_prompt: 'very short summary on one line. dont describe the format e.g. "the image", "the chart", "a meme", just the subject/content/data. dont add unnecessary moral judgments like "outdated", "controversial", "offensive", "antisemitic". keep it short!'
```

- [ ] **Step 2: Commit**

```bash
git add config.example.yaml
git commit -m "feat: add AI vision config options to config.example.yaml"
```

---

### Task 3: Implement AI image description in linktitles.php

**Files:**
- Modify: `scripts/linktitles/linktitles.php`

- [ ] **Step 1: Add imports for amphp-openai**

Add these `use` statements after the existing imports (after line 17):

```php
use Knivey\OpenAi\OpenAiClient;
use Knivey\OpenAi\Request\ChatRequest;
use Knivey\OpenAi\Request\Message;
use Knivey\OpenAi\Request\Content\TextPart;
use Knivey\OpenAi\Request\Content\ImagePart;
```

- [ ] **Step 2: Add default prompt constant to the class**

Add inside the `linktitles` class, after the existing `bufferLimit` constant (after line 26):

```php
    const defaultVisionPrompt = 'very short summary on one line. dont describe the format e.g. "the image", "the chart", "a meme", just the subject/content/data. dont add unnecessary moral judgments like "outdated", "controversial", "offensive", "antisemitic". keep it short!';
```

- [ ] **Step 3: Add getAiDescription method to the class**

Add this private method before the `isProxyExcluded` method (before line 223):

```php
    private function getAiDescription(string $body): ?string
    {
        global $config;
        if (!isset($config['ai_vision_key'])) {
            return null;
        }

        try {
            $maxDim = (int)($config['ai_vision_max_dim'] ?? 1024);
            $quality = (int)($config['ai_vision_jpg_quality'] ?? 85);

            $img = new \Imagick();
            $img->readImageBlob($body);
            $img->setImageFormat('jpeg');
            $img->setJPEGCompressionQuality($quality);
            $img->thumbnailImage($maxDim, $maxDim, true);
            $base64 = base64_encode($img->getImageBlob());
            $img->clear();

            $client = new OpenAiClient(
                apiKey: $config['ai_vision_key'],
                baseUrl: $config['ai_vision_base_url'] ?? 'https://api.openai.com/v1',
            );

            $prompt = $config['ai_vision_prompt'] ?? self::defaultVisionPrompt;
            $model = $config['ai_vision_model'] ?? 'gpt-4o';

            $response = $client->chatCompletion(new ChatRequest(
                model: $model,
                messages: [
                    Message::user([
                        new TextPart($prompt),
                        ImagePart::base64($base64, 'image/jpeg'),
                    ]),
                ],
            ));

            $description = $response->choices[0]->message->content;
            if ($description === null || trim($description) === '') {
                return null;
            }
            return trim($description);
        } catch (\Exception $e) {
            $this->logger->warning("AI vision description failed: " . $e->getMessage());
            return null;
        }
    }
```

- [ ] **Step 4: Update the image handler to use AI description**

Replace lines 124-140 (the image content-type handler block):

Old code (lines 124-140):
```php
                //TODO move these handlers to their own UrlEvents
                if (preg_match("@^image/(.*)$@i", $response->getHeader("content-type"), $m)) {
                    $size = $response->getHeader("content-length");
                    if ($size !== null && is_numeric($size))
                        $size = \knivey\tools\convert((int)$size);
                    else
                        $size = "?b";
                    $d = getimagesizefromstring($body);
                    if (!$d) {
                        $out = "[ $m[1] image $size ]";
                    } else {
                        $out = "[ $m[1] image $size $d[0]x$d[1] ]";
                    }
                    $bot->pm($chan, "  $out");
                    $this->logUrl($bot, $nick, $chan, $text, $out);
                    continue;
                }
```

New code:
```php
                if (preg_match("@^image/(.*)$@i", $response->getHeader("content-type"), $m)) {
                    $size = $response->getHeader("content-length");
                    if ($size !== null && is_numeric($size))
                        $size = \knivey\tools\convert((int)$size);
                    else
                        $size = "?b";
                    $d = getimagesizefromstring($body);
                    if (!$d) {
                        $out = "[ $m[1] image $size ]";
                    } else {
                        $out = "[ $m[1] image $size $d[0]x$d[1] ]";
                    }
                    $aiDesc = $this->getAiDescription($body);
                    if ($aiDesc !== null) {
                        $out = "$out — $aiDesc";
                    }
                    $bot->pm($chan, "  $out");
                    $this->logUrl($bot, $nick, $chan, $text, $out);
                    continue;
                }
```

Note: The `//TODO` comment is removed because the AI description feature is now the inline solution for images. The TODO was about moving to UrlEvent but we decided inline is the right place.

- [ ] **Step 5: Verify syntax**

Run: `php -l scripts/linktitles/linktitles.php`
Expected: No syntax errors

- [ ] **Step 6: Run static analysis**

Run: `composer phpstan`
Expected: No new errors related to linktitles changes

- [ ] **Step 7: Run existing tests**

Run: `composer test`
Expected: All existing tests pass

- [ ] **Step 8: Commit**

```bash
git add scripts/linktitles/linktitles.php
git commit -m "feat: add AI vision descriptions to image URL output (issue #6)"
```
