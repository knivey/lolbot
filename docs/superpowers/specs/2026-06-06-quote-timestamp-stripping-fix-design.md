# Fix Quote Timestamp Stripping (Issue #47)

## Problem

The `stripTimestamp()` function in `artbot_scripts/quotes.php` fails to strip many common IRC timestamp formats from the beginning of lines when recording quotes. This causes timestamps to be stored as part of the quote data.

## Root Cause

The current regex at line 92:
```
@^( *\[? *[\d:\-\\\/ ]+ *(?:am|pm)? *[\d:\-\\\/ ]* *]? *).+$@i
```
is too restrictive. It doesn't match several common IRC client timestamp formats, especially those with leading spaces, single-digit hours, no brackets, or box-drawing character prefixes.

## Observed Formats (from real DB data)

All of these appear in `db/db/gamesurge_graped_quote.db` and `db/db/efnet_quote.db`:

| Format | Example |
|--------|---------|
| `[HH:MM:SS]` | `[12:28:31] <slime>...` |
| `[HH:MM]` | `[00:42] <&sniff>...` |
| `HH:MM:SS` | `12:43:42 <~Altair8800>...` |
| `HH:MM` | `06:20 <+darkmage>...` |
| `H:MM:SS` | ` 9:38:00 <+darkmage>...` |
| With leading space | ` 8:20:44 --> hgc...` |
| With box-drawing prefix | `\│00:17:34 +sn1ff...` |

No IRC color/formatting codes were found in any of the timestamped entries — they are all plain text.

## Design

### 1. Rewrite `stripTimestamp()` regex

Replace the current regex with a more permissive pattern:

- Match optional leading whitespace
- Match optional box-drawing chars (`│`, `┃`, `║`, `|`)
- Match optional `[` bracket
- Match time: `\d{1,2}:\d{2}(?::\d{2})?` (H:MM or HH:MM, optional seconds)
- Match optional `]` bracket
- Match trailing whitespace
- Keep `strtotime()` validation as the guard against false positives

The `strtotime()` check remains the critical safety net — if the captured prefix doesn't parse as a valid time, the line is returned unchanged.

### 2. Add unit tests

Create a test file covering:
- All timestamp formats observed in the DB
- Negative cases: lines without timestamps pass through unchanged
- Lines where text looks like a time but isn't (e.g., version numbers)

## Scope

- Only the `stripTimestamp()` function in `artbot_scripts/quotes.php` changes
- No changes to recording/playback logic
- No DB migration — existing bad data stays as-is
- New test file for the function

## Out of Scope

- Fixing existing quotes already in the database
- Stripping nick prefixes before timestamps (those are part of the quote)
- Handling IRC color codes in timestamps (none found in real data)
