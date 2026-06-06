# Per-Network/Channel AI Vision Disable

**Issue**: #6 follow-up  
**Date**: 2026-06-06

## Summary

Add database-backed toggle to disable AI image descriptions per-network or per-channel, configurable via admin-cli. Uses a per-script settings entity pattern that can grow with future linktitles settings.

## Entity

New entity: `scripts\linktitles\entities\linktitles_setting`  
Table: `linktitles_settings`

| Column | Type | Constraints |
|--------|------|-------------|
| `id` | INT | PK, auto-increment |
| `network_id` | INT | FK to Networks, NULLABLE |
| `channel_id` | INT | FK to Channels, NULLABLE |
| `ai_vision_disabled` | BOOLEAN | NOT NULL, default false |

**Constraints:**
- Unique constraint on `(network_id, channel_id)` — one settings row per scope
- At least one of `network_id` or `channel_id` must be non-NULL
- Channel-level settings use `channel_id` only (channel already belongs to a bot which belongs to a network, so `network_id` is not required)
- Network-level settings use `network_id` with `channel_id = NULL`

**Scoping rules:**
- Channel-level setting (channel_id set) overrides network-level
- Network-level setting (network_id set, channel_id NULL) applies to all channels on that network without a channel-level override
- No settings row = AI vision enabled (default)

## Lookup Logic

In `linktitles.php`, before calling `getAiDescription()`, add a check:

1. Find the Channel entity for the current `$chan` name under `$this->bot`
2. Query `linktitles_setting` repository:
   - First check for channel-level: `channel_id = $channelId`
   - If not found, check network-level: `network_id = $this->network->id AND channel_id IS NULL`
3. If a setting is found and `ai_vision_disabled === true`, skip the AI call

## Admin CLI Command

New command: `linktitles:set` registered in `admin-cli.php`

```
php admin-cli.php linktitles:set <network_id> ai_vision_disabled 1
php admin-cli.php linktitles:set <network_id> ai_vision_disabled 0
php admin-cli.php linktitles:set --channel <channel_id> ai_vision_disabled 1
php admin-cli.php linktitles:set --channel <channel_id> ai_vision_disabled 0
```

- First arg is network_id (required, for network-level settings)
- `--channel` option sets per-channel instead (channel_id only, network_id not stored)
- Without `--channel`, sets network-level
- Shows current settings when called with no value arg
- Creates a settings row if one doesn't exist for the scope, updates if it does

## Files to Create/Modify

1. **New entity:** `scripts/linktitles/entities/linktitles_setting.php`
2. **New migration:** `Migrations/Version20260606120000.php`
3. **New CLI command:** `cli_cmds/linktitles_set.php`
4. **Modify:** `admin-cli.php` — register the new command
5. **Modify:** `scripts/linktitles/linktitles.php` — add disable check before AI call

## Doctrine Note

`scripts/linktitles/entities/` is already registered as a Doctrine annotation path in `bootstrap.php`, so no changes needed there.
