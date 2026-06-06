# AI Image Descriptions for Link Titles

**Issue**: #6 — Image URLs use AI service to describe them  
**Date**: 2026-06-05

## Summary

When image URLs are posted in IRC channels, augment the current basic image info (`[ jpg image 1.2MB 1920x1080 ]`) with a short AI-generated description of the image content (e.g., `[ jpg image 1.2MB 1920x1080 — a cat sitting on a windowsill ]`).

Uses `knivey/amphp-openai` library for async OpenAI-compatible API calls with vision support.

## Configuration

All config goes in `config.yaml`. Every option is optional — if `ai_vision_key` is not set, the feature degrades gracefully to the current basic output.

```yaml
ai_vision_key: "sk-..."                              # Required to enable feature
ai_vision_base_url: "https://api.openai.com/v1"      # Optional, for Ollama/Groq/etc
ai_vision_model: "gpt-4o"                            # Optional, defaults to gpt-4o
ai_vision_max_dim: 1024                              # Max width or height, keeps aspect ratio
ai_vision_jpg_quality: 85                            # JPEG quality (1-100)
ai_vision_prompt: "very short summary on one line..." # Optional, overrides default prompt
```

### Default prompt

```
very short summary on one line. dont describe the format e.g. "the image", "the chart", "a meme", just the subject/content/data. dont add unnecessary moral judgments like "outdated", "controversial", "offensive", "antisemitic". keep it short!
```

This is stored as a class constant in `linktitles` and can be overridden via `ai_vision_prompt` in config.

## Architecture

### Location

The AI description logic is added **inline** in `linktitles.php` within the existing image content-type handler (lines 125-139). This is the fallback handler that runs when no UrlEvent handler claims the URL.

### Flow

1. HTTP request detects `content-type: image/*` (unchanged)
2. Body is buffered, `getimagesizefromstring()` gets dimensions (unchanged)
3. Basic info string is built: format, size, dimensions (unchanged)
4. **If `ai_vision_key` is configured:**
   - Resize image with `Imagick`: fit within `ai_vision_max_dim` keeping aspect ratio
   - Convert to JPEG at `ai_vision_jpg_quality`
   - Encode as base64
   - Call `knivey/amphp-openai` with `ImagePart::base64()` + configurable prompt
   - Append AI description to basic info: `[ jpg image 1.2MB 1920x1080 — <description> ]`
5. **If no API key configured:** output basic info unchanged: `[ jpg image 1.2MB 1920x1080 ]`

### Image processing

Using `Imagick` (already required by the project via `ext-imagick`):

```php
$img = new \Imagick();
$img->readImageBlob($body);
$img->setImageFormat('jpeg');
$img->setJPEGCompressionQuality($quality);
$img->thumbnailImage($maxDim, $maxDim, true); // bestfit=true keeps aspect ratio
$base64 = base64_encode($img->getImageBlob());
```

### API call

Using `knivey/amphp-openai`:

```php
use Knivey\OpenAi\OpenAiClient;
use Knivey\OpenAi\Request\ChatRequest;
use Knivey\OpenAi\Request\Message;
use Knivey\OpenAi\Request\Content\TextPart;
use Knivey\OpenAi\Request\Content\ImagePart;

$client = new OpenAiClient(
    apiKey: $config['ai_vision_key'],
    baseUrl: $config['ai_vision_base_url'] ?? 'https://api.openai.com/v1',
);

$response = $client->chatCompletion(new ChatRequest(
    model: $config['ai_vision_model'] ?? 'gpt-4o',
    messages: [
        Message::user([
            new TextPart($prompt),
            ImagePart::base64($base64, 'image/jpeg'),
        ]),
    ],
));

$description = $response->choices[0]->message->content;
```

## Error handling

- If the API call throws an exception (timeout, rate limit, auth error, etc.), fall back to the basic image info output
- Log the error via `$this->logger`
- The OpenAiClient already handles retry on 429 (rate limit) with `Retry-After` header
- Set a transfer timeout on the API call (10 seconds)

## Dependencies

- `knivey/amphp-openai` ^1.0 — to be added to `composer.json`
- `ext-imagick` — already required
- `amphp/http-client` ^5.0 — already present (transitive dependency of amphp-openai)

## Files to modify

1. `composer.json` — add `knivey/amphp-openai` dependency
2. `scripts/linktitles/linktitles.php` — add AI description logic in image handler
3. `config.example.yaml` — add commented-out AI vision config options
4. `AGENTS.md` — note the new config keys (optional)

## Output examples

With AI enabled:
```
  [ jpeg image 1.2MB 1920x1080 — golden retriever catching a frisbee in a park ]
  [ png image 340KB 800x600 — line chart showing CPU usage over 24 hours ]
  [ gif image 5.1MB 480x360 — cat falling off a table ]
```

Without AI (unchanged from current):
```
  [ jpeg image 1.2MB 1920x1080 ]
  [ png image 340KB 800x600 ]
```
