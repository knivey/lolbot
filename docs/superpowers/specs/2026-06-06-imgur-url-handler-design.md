# Imgur URL Handler for Link Titles

**Issue**: #42 — linktitles: imgur link info
**Date**: 2026-06-06

## Summary

Add a new URL event handler script that intercepts imgur URLs before the generic linktitles fallback, providing rich metadata for gallery pages and proper image/video info for direct links.

Currently imgur links produce poor output:
- `i.imgur.com/ID.ext` → the bot's `Accept: text/html` header triggers a 302 redirect to `imgur.com/ID`, which renders a useless `[ Imgur: The magic of the Internet ]` title
- `imgur.com/gallery/slug-ID` → only shows the basic `<title>` tag, ignoring rich embedded JSON data
- `imgur.com/ID` and `imgur.com/a/ID` → same useless generic title (client-rendered pages)

## Architecture

New script: `scripts/imgur/imgur.php`, class `scripts\imgur\imgur` extending `script_base`, registered as a URL event handler following the same pattern as reddit, github, twitter, etc.

Registration in `lolbot.php`: instantiate, call `setEventProvider($eventProvider)`, call `router->loadMethods()`.

No API key required — all data comes from scraping `postDataJSON` embedded in gallery pages, or from direct image/video fetches with appropriate headers.

## URL Patterns and Handling

### 1. Gallery pages: `imgur.com/gallery/*-ID`

Fetch the page HTML and extract the `postDataJSON` attribute from the embedded data. This JSON contains: title, description, image count, view count, point/upvote/downvote counts, account username, is_album, media array (with per-image URL, dimensions, size, type), and more.

The `postDataJSON` is HTML-entity-encoded JSON assigned to a `postDataJSON=` attribute in the page source. Decoding requires HTML-unescaping then replacing `\"` with `"` and `\'` with `'` before JSON parsing.

### 2. Album pages: `imgur.com/a/ID`

Album IDs are not valid image IDs on `i.imgur.com` — fetching `i.imgur.com/FpuLRBp.jpg` returns a 302 to `removed.png`. Instead, fetch the album's HTML page and extract the cover image ID from the `og:image` meta tag (e.g. `content="https://i.imgur.com/vOFL64u.jpeg?fb"` → ID is `vOFL64u`). Then fetch `i.imgur.com/{imageId}.jpg` with `Accept: */*` to get the actual image metadata.

### 3. Single image pages: `imgur.com/ID`

Same two-step approach as album pages — fetch the HTML, extract the image ID from `og:image`, then fetch the direct image. For `/ID` pages the URL ID and the `og:image` ID are usually the same, but the HTML fetch is still needed since the ID alone isn't sufficient to construct a working direct image URL (the Accept header trick is required to avoid the 302 redirect).

### 4. Direct image/video links: `i.imgur.com/ID.ext`

The bot's default `Accept: text/html` header causes imgur to 302 redirect these to `imgur.com/ID`. The handler intercepts before the generic fetch and re-requests with `Accept: */*` to get the actual binary content, then extracts image or video metadata.

For video content (content-type `video/*`), show video info (format, resolution, duration) if `mediainfo` is available, same as linktitles' existing video handling.

## Request Headers

The handler uses its own HTTP requests with these headers:
- `User-Agent`: same as linktitles config (`linktitles_useragent`)
- `Accept: */*` — prevents imgur from 302-redirecting direct image URLs to HTML pages

Gallery page and album/image page requests use the standard linktitles Accept header since we want HTML back. The `Accept: */*` header is only used when fetching direct binary content from `i.imgur.com`.

## Output Formats

**Gallery/album with postDataJSON:**
```
[Imgur] Froggy Friday — 30 images, 7.5K views, 261 pts by GigglySprinkleCupcake
```

**Single image (from direct fetch):**
```
[Imgur] jpeg 720x700, 397KB
```

**Video (from direct fetch, if mediainfo available):**
```
[Imgur] mp4 video 1080x1920, 15s, 2.1MB
```

View counts are human-readable (e.g., `7.5K`, `1.2M`) using the existing `\knivey\tools\convert()` helper. Point counts shown as-is.

## Error Handling

- If `postDataJSON` parsing fails on a gallery page, fall through to the generic linktitles title extraction as a fallback.
- If a direct image fetch fails or returns unexpected content, return without replying (let generic handling try).
- Network errors are caught and logged, same as other URL handlers.

## Refactoring: Extracting Shared Logic from linktitles

The imgur handler needs the same image/video content handling that exists inline in `linktitles::linktitles()` (lines 153-233). Rather than duplicating this code, extract it into `public` methods on the `linktitles` class and pass the linktitles instance to the imgur handler via `setEventProvider`.

### Passing linktitles to URL handlers

`setEventProvider` gains an optional second parameter for the linktitles instance. In `lolbot.php`:

```php
$imgur = new imgur(...);
$imgur->setEventProvider($eventProvider, $linktitles);
```

The imgur handler stores the linktitles instance and calls methods on it directly. Existing handlers don't need changes — the parameter is optional.

### Methods to extract

**`linktitles::formatImageResponse(string $body, string $contentType, ?string $contentLength, string $chan): string`**

Extracted from lines 153-172. Takes the raw response body, content-type header, content-length header, and channel name. Returns the formatted string (e.g. `[ jpeg image 397KB 720x700 — AI description ]`). Internally calls `$this->getAiDescription` and `$this->isAiVisionDisabled` as before — no changes to those methods' signatures.

**`linktitles::formatVideoResponse(string $body, string $ext, ?string $contentLength): string`**

Extracted from lines 174-233. Takes the raw response body, file extension, and content-length header. Returns the formatted string (e.g. `[ 15s mp4 video (AVC) 2.1MB 1080x1920 @ 30fps, AAC audio ]`). Uses `mediainfo` if available.

### How the imgur handler calls these

For direct image/video fetches (URL patterns 2, 3, 4), the imgur handler fetches the binary content with `Accept: */*`, then calls:

```php
$out = "[Imgur] " . $this->linktitles->formatImageResponse($body, $contentType, $contentLength, $event->chan);
```

The gallery handler (pattern 1) uses `postDataJSON` and doesn't need these methods.

### Changes to linktitles::linktitles()

The inline image and video blocks (lines 153-233) are replaced with calls to the new extracted methods. No behavioral change — same output, same logic, just factored out.

## Files Changed

| File | Change |
|------|--------|
| `scripts/imgur/imgur.php` | New file — the handler class |
| `scripts/linktitles/linktitles.php` | Extract image/video formatting into public methods, refactor inline blocks to use them |
| `lolbot.php` | Instantiate and register the imgur handler (3 lines) |
