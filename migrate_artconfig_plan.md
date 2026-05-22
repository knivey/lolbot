# migrate_artconfig.php — Multi-Input Migration Spec

## Overview

Rewrite `migrate_artconfig.php` to support multiple input configs, required network naming, merging into an existing output file, and automatic discovery/copying of referenced DB files.

## CLI Syntax

```
php migrate_artconfig.php -n <name> config1.yaml [-n <name> config2.yaml ...] [-o output.yaml]
```

### Argument Parsing

Walk `$argv[1:]` left to right:

| Token | Action |
|-------|--------|
| `-n <name>` | Set the network name for the next config file. **Required** before each input. If an input file is encountered without a preceding `-n`, print usage and exit with error. |
| `-o <path>` | Set output file path. Default: `artbotsconfig.yaml`. |
| anything else | Treated as an input config file. Consume the current `-n` name (error if unset). Reset `-n` after consumption. |

### Example Invocations

```bash
# Single network
php migrate_artconfig.php -n florp artconfig.yaml

# Multiple networks, custom output
php migrate_artconfig.php -n florp artconfig.yaml -n gamesurge artconfig_gs.yaml -o artbotsconfig.yaml

# Migrate incrementally (gamesurge already exists in output, adding florp)
php migrate_artconfig.php -n florp artconfig.yaml -o artbotsconfig.yaml
```

## Existing Output Handling

If the output file already exists:

1. Parse it as YAML.
2. Validate it has a top-level `networks` array. If not, error.
3. Load existing networks into a map keyed by `name`.
4. For each new network to add:
   - If a network with the same `name` already exists in the output, **error** and report the conflict. Tell the user to remove/rename the existing entry first.
   - Otherwise, append to the `networks` array.

This allows incremental migration — run the script multiple times, each adding a new network to the same output file.

## Per-Input Conversion

For each input file, run the existing conversion logic:

- **Already `networks` format**: skip with warning ("already uses networks format, nothing to do").
- **Old `bots:` format** (multiartconfig): extract bot array, wrap shared config into a `networks[]` entry.
- **Flat single-bot format**: extract bot-specific keys (`name`, `server`, `port`, `ssl`, `throttle`, `bindIp`, `pass`) into a `bots[]` entry, keep remaining keys at the network level.

The `-n <name>` value is **always applied** as the network `name`, overriding any derived name.

## DB File Handling

### Discovery

For each network config (both existing networks from the output file and newly converted ones), check for the `quotedb` key. This is the only db-like file reference in artbot configs.

### Path Resolution

- If the path is absolute, use as-is.
- If relative, resolve relative to the input file's directory.

### Tracking Map

Maintain a map of `resolved_source_path → [network_name, ...]` across all networks (existing + new). This enables detection of shared files.

### Output Naming

DB files are copied to `db/` directory, sibling to the output file.

| Scenario | Output filename |
|----------|----------------|
| One network uses the file | `db/<network>_quotes.db` |
| Two+ networks share the same source file | `db/<net1>_<net2>_quotes.db` (network names joined with `_`, sorted for determinism) |

### Copy Behavior

1. Create `db/` directory if it doesn't exist.
2. For each entry in the tracking map:
   - If the target file already exists and is identical to the source (same content or same inode), skip copy, print "already exists".
   - If the target file exists but differs, **error** — don't overwrite.
   - Otherwise, copy the source file to the target path.
3. Update each network's `quotedb` config value to the new relative path (e.g. `db/florp_quotes.db`).

### Existing Output DB Handling

When updating an existing output file, also read existing networks' `quotedb` values. If an existing network already has a `quotedb` pointing inside `db/`, add it to the tracking map so that collision detection works across already-migrated and new networks. Don't re-copy files that are already in `db/`.

## Output

1. Merge all networks into `['networks' => [...]]`.
2. Write YAML to output file (4-space indent, 2-space block, matching current `Yaml::dump($newConfig, 4, 2)`).
3. Print summary:
   - Number of networks added / total in output.
   - Each input file and its assigned network name.
   - Each DB file copied (or skipped as already present).
   - Any warnings.

## Error Cases

| Condition | Behavior |
|-----------|----------|
| No `-n` before an input file | Error + usage |
| Input file doesn't exist | Error per file |
| Input file can't be parsed | Error per file |
| Output file exists but isn't valid networks format | Error |
| Duplicate network name in output | Error, list conflict |
| DB source file not found on disk | Warning (config still written, but DB path may be broken) |
| DB target file exists with different content | Error, don't overwrite |

## File Reference

- Bot-specific keys to extract: `name`, `server`, `port`, `ssl`, `throttle`, `bindIp`, `pass`
- DB config key: `quotedb`
- Output directory for DB files: `db/` (sibling to output YAML)
