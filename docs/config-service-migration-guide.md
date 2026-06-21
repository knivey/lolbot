# Config Service Migration Guide (Sub-project 1)

One-time migration that moves AI vision, paste, and linktitles config out of
`config.yaml` into the database, and introduces the `ConfigService` library +
`service:*` / `config:import` CLI commands.

Applies to issues #109/#110/#111/#112/#120. This is **Sub-project 1 only** — live
reload (#109) and the web UI (#112) come later, so a **bot restart is required**
at the end for changes to take effect.

---

## What changes

| Before (config.yaml) | After (database) | Tool to change it |
|---|---|---|
| `ai_vision_key`, `ai_vision_base_url`, `ai_vision_max_dim`, `ai_vision_jpg_quality`, `ai_vision_timeout`, `ai_vision_reasoning_effort`, `ai_vision_reasoning` | `ai_service_config` table | `php admin-cli.php service:set ai <key> <value>` |
| `paste_host`, `paste_key` | `paste_service_config` table | `php admin-cli.php service:set paste <key> <value>` |
| `bots.<id>.linktitles`, `bots.<id>.url_log_chan` | `linktitles_settings` table (network-scoped) | `php admin-cli.php linktitles:set --network <id> <key> <value>` |
| (global) `ai_vision_model`, `ai_vision_prompt` | `linktitles_settings` table (per network) | `php admin-cli.php linktitles:set --network <id> ai_vision_model <value>` |

**Stays in `config.yaml`** (not migrated yet — their consumers still read them):
`codesand`, `codesand_maxlines`, `codesandMinAccess`, `pump_host`, `pump_key`,
`youtube_thumb`, `youtube_thumbwidth`, `youtube_pump_host`, `youtube_pump_key`,
`listen`, plus all other global API keys (wolfram, bing, weather, lastfm, etc.).

---

## Before you start

- [ ] **Schedule brief downtime.** The bots must be restarted after the migration.
- [ ] **Backup the database:**
  ```bash
  pg_dump -Fc -d lolbot > /tmp/lolbot_pre_config_service.dump
  ```
  (SQLite: `cp lolbot.db /tmp/lolbot.db.pre`)
- [ ] **Backup `config.yaml`:**
  ```bash
  cp config.yaml /tmp/config.yaml.pre
  ```

---

## Step 1 — Deploy the code

- [ ] Pull the new code on the production server:
  ```bash
  git pull
  ```
- [ ] Regenerate the autoloader (a new PSR-4 namespace `lolbot\config\` was added):
  ```bash
  composer dump-autoload
  ```
  (No new dependencies; `composer install` also works.)

---

## Step 2 — Run the database migration

This creates the `ai_service_config` and `paste_service_config` tables and expands
`linktitles_settings` with new columns. Portable across PostgreSQL and SQLite.

- [ ] **Dry-run first** (review the generated SQL, no changes):
  ```bash
  php admin-cli.php migrations:migrate --dry-run
  ```
  Expected: `CREATE TABLE ai_service_config ...`, `CREATE TABLE paste_service_config ...`,
  and `ALTER TABLE linktitles_settings ADD ...` (6 columns). No errors.

- [ ] **Apply it:**
  ```bash
  php admin-cli.php migrations:migrate
  ```

- [ ] Verify it's applied:
  ```bash
  php admin-cli.php migrations:status
  ```
  Expected: `Current = lolbot\Migrations\Version20260619120000`, 0 new pending.

> The bot refuses to start if a migration is pending, so this step is mandatory
> before restarting.

---

## Step 3 — Backfill the database from config.yaml

**Run this WHILE the legacy keys are still in `config.yaml`** (i.e. before Step 4).
It reads the values above and writes them into the new DB tables. Idempotent —
re-running skips values that are already set.

- [ ] Import:
  ```bash
  php admin-cli.php config:import
  ```
  Expected output: lines like `imported ai.apiKey`, `imported paste.host`,
  `imported linktitles enabled for network <name>`, `imported ai_vision_model ...`,
  ending with `Imported N value(s).`

- [ ] If anything was already set from a prior test run and you want a clean
      re-import from `config.yaml`, use `--force`:
  ```bash
  php admin-cli.php config:import --force
  ```

---

## Step 4 — Verify the backfill

- [ ] AI service config present:
  ```bash
  php admin-cli.php service:get ai
  ```
  Expected: `apiKey=(set) baseUrl=... maxDim=... jpgQuality=... timeout=...`
- [ ] Paste service config present:
  ```bash
  php admin-cli.php service:get paste
  ```
  Expected: `host=... key=(set)`
- [ ] Find your network id, then check linktitles settings:
  ```bash
  php admin-cli.php network:list
  php admin-cli.php linktitles:set --network <NET_ID>
  ```
  Expected: a table showing `enabled`, `url_log_chan`, `ai_vision_model`, etc.
  with the values that were in `config.yaml`.

> If the art bot used a paste host defined in **`artbotsconfig.yaml`** (not the main
> `config.yaml`), `config:import` won't have seen it — set it explicitly:
> `php admin-cli.php service:set paste host <host>` / `service:set paste key <key>`.

---

## Step 5 — Remove the migrated keys from config.yaml

Now that the DB holds them, delete these from `config.yaml`:

- [ ] All top-level `ai_vision_*` keys (and their comments).
- [ ] `paste_host`, `paste_key`.
- [ ] From each `bots:` block: the `linktitles:` and `url_log_chan:` lines.
- [ ] (Optional) A dead top-level `linktitles: true`, if present — no code reads it.

**Keep** in the `bots:` block: `codesand`, `codesand_maxlines`, `codesandMinAccess`,
`pump_host`, `pump_key`, `youtube_*`, `listen`. **Keep** the `database:` section and
all other global API keys.

- [ ] Validate the file still parses:
  ```bash
  php -r 'require "vendor/autoload.php"; use Symfony\Component\Yaml\Yaml; var_dump(is_array(Yaml::parseFile("config.yaml")));'
  ```
  Expected: `bool(true)`.

---

## Step 6 — Restart and smoke-test

- [ ] Restart the channel bot (and art bot, if separate):
  ```bash
  php lolbot.php
  # php artbots.php   (if you run the art bot)
  ```
- [ ] In a channel, confirm linktitles still works:
  - Paste a regular URL → the page title is fetched.
  - Paste an image URL → if AI vision is enabled and not disabled for the channel,
    an AI description is shown (using the model from the DB, e.g. your
    `google/gemini-2.5-flash-lite`).
  - URL logging still goes to the configured `url_log_chan`, if set.
- [ ] Confirm paste-backed commands still work (e.g. `!help` when the output is long,
  or an alias listing that overflows) — they now use the paste service from the DB.
- [ ] Run the test suite on the server (optional sanity check):
  ```bash
  vendor/bin/phpunit
  ```

---

## After migration: changing settings

These now live in the DB — edit them with the CLI, **not** `config.yaml`:

```bash
# AI service
php admin-cli.php service:set ai apiKey 'sk-...'
php admin-cli.php service:set ai baseUrl 'https://api.openai.com/v1'
php admin-cli.php service:set ai maxDim 1024
php admin-cli.php service:set ai reasoningEffort low

# Paste service
php admin-cli.php service:set paste host 'http://localhost:8080'
php admin-cli.php service:set paste key '...'

# Per-network/channel linktitles settings
php admin-cli.php linktitles:set --network <id> enabled true
php admin-cli.php linktitles:set --network <id> url_log_chan '#urls'
php admin-cli.php linktitles:set --network <id> ai_vision_model gpt-4o
php admin-cli.php linktitles:set --network <id> --channel <chan_id> ai_vision_disabled true

# Reset one to inherited/default
php admin-cli.php linktitles:set --network <id> ai_vision_model inherit

# Inspect
php admin-cli.php service:get ai
php admin-cli.php service:list
php admin-cli.php linktitles:set --network <id>
```

> Note: changes take effect on the **next bot restart** until Sub-project 2 (#109)
> ships live reload.

---

## Rollback (if something goes wrong)

1. Stop the bots.
2. Restore `config.yaml`:
   ```bash
   cp /tmp/config.yaml.pre config.yaml
   ```
3. Roll back the migration:
   ```bash
   php admin-cli.php migrations:migrate prev
   ```
   (Or restore the DB dump: `pg_restore -d lolbot /tmp/lolbot_pre_config_service.dump`.)
4. Check out the previous code (`git checkout <prev-commit>`), `composer dump-autoload`.
5. Restart the bots.

---

## Troubleshooting

- **Bot won't start: "You have pending migrations to execute"** → run Step 2.
- **`SQLSTATE ... no such column: t0.apiKey`** → means the `AiServiceConfig` entity
  column mapping and the migration got out of sync. Ensure both the latest code
  (which maps camelCase props to snake_case columns) and the migration are applied.
- **AI descriptions stopped after migration** → `service:get ai` shows `apiKey=(set)`?
  If not, run `config:import` again (or `config:import --force`). Also check
  `linktitles:set --network <id>` shows `enabled=true` and `ai_vision_disabled=false`.
- **Paste commands say "paste service not configured"** → `service:get paste` shows
  a host/key? If not, `config:import` or `service:set paste host/key`.
- **Wrong AI model after migration** → `linktitles:set --network <id>` shows the model?
  Set it: `linktitles:set --network <id> ai_vision_model <model>`.

---

## Sub-project 2: live config sync (after Sub-project 1)

Enables live-apply so config changes take effect without a bot restart.

- [ ] Add top-level `listen` (e.g. `"127.0.0.1:1339"`) and `control_key` to `config.yaml`.
- [ ] Remove the per-bot `bots.<id>.listen` lines (no longer used).
- [ ] Restart the channel bot once to start the global REST server.
- [ ] Verify live-apply: `php admin-cli.php bot:addchannel <botid> #test` → the bot
      joins `#test` live (no restart). If the bot is down, the command still
      succeeds (push is skipped); the change applies on next start.
- [ ] Manual control endpoints (core key, via `key:` header):
      `POST /_control/reconnect/{botid}`, `/_control/jump/{botid}`, `/_control/respawn/{botid}`.
- [ ] Notifier now lives at `POST /notifier/{botid}/privmsg/{chan}` and
      `/owncast/{chan}` (still `notifier_keys.yaml`); update any outside apps
      that posted to the old per-bot `/privmsg/{chan}` URL to include the bot id.

---

## Sub-project 3: web control panel (after Sub-project 2)

An out-of-process PHP web UI (Twig templates + HTMX) served from `web/`, letting
operators edit config from a browser. Mutations go through the same `ConfigService`
as the CLI and push live via the Sub-project-2 `/_control/*` surface, so bots
join/part/rename/jump without a restart.

### Setup

`twig/twig` is already a composer dependency (installed during the project), so
there is no extra install step beyond the usual:

```bash
composer install
```

The panel reuses the top-level `config.yaml` keys `listen` and `control_key`
already added in Sub-project 2 — nothing new to add to `config.yaml`.

### Running it (dev)

PHP's built-in server is enough for local use:

```bash
php -S 127.0.0.1:8088 -t web/ web/index.php
```

Then open `http://127.0.0.1:8088/`.

### Running it (prod)

nginx + php-fpm (or a reverse proxy → php-fpm). The same `web/index.php` runs
under both. `SCRIPT_FILENAME` is pinned to `index.php` so the front controller
handles all routing; static assets resolve via the `location /` `try_files` first:

```nginx
server {
    listen 443 ssl http2;
    server_name lolbot.panel.example;
    # ssl_certificate ...
    root /path/to/lolbot/web;
    location / { try_files $uri /index.php$is_args$args; }
    location ~ \.php$ { fastcgi_pass unix:/run/php/php-fpm.sock; include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTPS on; }
}
```

### Auth

- `control_key` (top-level `config.yaml`) is **required**. It is the browser-login
  password **and** the auth token for the Sub-project-2 `/_control/*` routes (and the
  status endpoint below) — the bot fail-closes those routes without it.
- If `control_key` is not set, the panel refuses to operate and shows a setup-error
  page (503) instructing you to add one (there is no "open" mode).
- Set it, then restart the bot so it picks up the key for `/_control/*`, and reload
  the panel — you'll get a login prompt.
- The panel is reverse-proxy aware: it honors `X-Forwarded-Proto` when setting
  the secure session cookie, so it works correctly behind an HTTPS-terminating
  proxy.

### Live runtime status

The panel reads live runtime status from one new bot endpoint:

```
GET /_control/status
```

It uses core-key auth (the same `control_key`, sent via the `key:` header). The
running bot (`lolbot.php`) serves it alongside the Sub-project-2 `/_control/*`
routes. The panel uses it to show whether the bot is up and other live state.

### Behavior

Every panel mutation flows through `ConfigService` + the Sub-project-2 live-sync
push (`POST /_control/apply`). Bots join/part/rename/jump live — no restart
needed. If a bot is down, the change is persisted and applies on its next start.

