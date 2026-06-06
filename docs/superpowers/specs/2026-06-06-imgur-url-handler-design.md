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

## Files Changed

| File | Change |
|------|--------|
| `scripts/imgur/imgur.php` | New file — the handler class |
| `lolbot.php` | Instantiate and register the imgur handler (3 lines) |
