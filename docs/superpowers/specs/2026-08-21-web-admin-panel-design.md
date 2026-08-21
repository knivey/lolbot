# lolbot Web Admin Panel — Design Spec

**Date:** 2026-08-21
**Status:** Approved

## Summary

Full visual overhaul of the `web/` admin panel. The panel is a PHP/Twig app using Bootstrap 5.3 (dark theme) and htmx. The goal is to transform the current raw-Bootstrap look into a polished, modern minimal admin interface while staying on Bootstrap 5.3.

**Design direction:** Modern minimal, warm charcoal + amber accent, card-heavy components, refined sidebar.

## Constraints

- Stay on Bootstrap 5.3 — add custom CSS on top, do not replace the framework
- Keep htmx for live status polling and inline actions
- Keep all existing functionality and routes intact
- Custom CSS goes in a new `web/assets/custom.css` file
- No new JavaScript dependencies (htmx + Bootstrap JS is sufficient)
- No build tools — plain CSS file served statically

## Color Palette

| Token | Hex | Usage |
|---|---|---|
| Page background | `#141416` | Body, main content area |
| Surface | `#1c1c1e` | Cards, sidebar, inputs |
| Border | `#2c2c2e` | Card borders, dividers |
| Border subtle | `#2c2c2e40` | Table row dividers |
| Text primary | `#f5f5f7` | Headings, body text |
| Text secondary | `#8e8e93` | Labels, muted text |
| Accent (amber) | `#f59e0b` | Active nav, primary buttons, links |
| Accent hover | `#d97706` | Button/link hover |
| Success | `#34d399` | Connected status, positive badges |
| Danger | `#f87171` | Disconnected status, delete buttons |
| Focus ring | `#f59e0b40` | Input focus states |

## Layout

### Sidebar (220px)

- Width increased from 168px to 220px
- Background: `#1c1c1e`, right border: `#2c2c2e`
- Nav items have emoji icons prefixing the label:
  - ⬡ Overview
  - ⚙ Bots
  - 🌐 Networks
  - 🚫 Ignores
  - 🔧 Services
  - 🔗 Linktitles
- Active item: amber left border (3px), slightly lighter background (`#252528`)
- Hover: subtle background lighten with smooth transition (150ms)
- Brand/header at top: "🤖 lolbot control"
- Logout link pinned to bottom of sidebar

### Navbar

- Remove the current top navbar entirely — the sidebar header replaces the brand
- A slim top bar (40px) remains for the "operator · logout" text, right-aligned
- Background: `#1c1c1e`, bottom border: `#2c2c2e`

### Content Area

- Background: `#141416`
- Padding: 24px
- Max-width: none (full flex-grow)

## Components

### Status Cards (Overview)

Each bot gets a rounded card:

```
┌─────────────────────────────────────────────────┐
│ 🟢 mybot   connected   libera · nick mybot · 5ch │
│                                                   │
│ [reconnect] [jump] [respawn]                      │
└─────────────────────────────────────────────────┘
```

- Card: `border-radius: 10px`, `background: #1c1c1e`, `border: 1px solid #2c2c2e`
- Status dot: 10px circle, green (#34d399) or red (#f87171)
- Bot name: 16px, font-weight 600
- Status text: green/red with subtle background pill
- Info line: secondary color, 12px
- Action buttons: outlined pills, amber text on hover, 12px
- Empty state: warning card with icon, not bare text

### Tables (Bots, Networks, Ignores)

Tables wrapped in rounded cards:

```
┌──────────────────────────────────────────────┐
│ Bots                           [+ add bot]   │
│──────────────────────────────────────────────│
│ ID   NAME      NETWORK   TRIGGER             │
│ 1    mybot     libera    !          edit      │
│ 2    otherbot  oftc      .          edit      │
└──────────────────────────────────────────────┘
```

- Container: same card styling as status cards
- Header row: uppercase, 11px, letter-spacing 0.5px, muted color
- Data rows: 13px, subtle bottom dividers
- Row hover: very slight background lighten
- "Edit" links: amber color
- Add button: solid amber background, dark text, rounded

### Forms (Edit pages, Services, Linktitles)

Forms wrapped in cards:

- Each form section in its own card
- Input fields: `background: #252528`, `border: 1px solid #3a3a3c`, `border-radius: 6px`
- Focus: `border-color: #f59e0b`, `box-shadow: 0 0 0 3px #f59e0b40`
- Labels: 13px, font-weight 500, secondary color
- Submit button: solid amber, dark text, `border-radius: 6px`
- Delete button: outlined, red text/border
- Success alert: green-tinted card
- Error alert: red-tinted card

### Badges & Pills

- Channel badges: `background: #2c2c2e`, `border-radius: 20px`, `padding: 4px 12px`
- Network badges: same style
- Status badges: colored background at 10% opacity + colored text

### Login Page

- Centered card (max-width 420px) on dark background
- Same card styling as rest of panel
- Amber submit button
- Clean layout: just the brand, key input, and login button

### Alerts

- Success: `background: #34d39915`, `border: 1px solid #34d39930`, green text
- Error: `background: #f8717115`, `border: 1px solid #f8717130`, red text
- Warning: `background: #f59e0b15`, `border: 1px solid #f59e0b30`, amber text
- All: `border-radius: 8px`, `padding: 12px 16px`

## File Changes

### New Files

- `web/assets/custom.css` — all custom styles overriding Bootstrap defaults

### Modified Files

- `web/templates/base.twig` — add `custom.css` link, restructure sidebar (220px, icons, bottom logout), slim top bar
- `web/templates/login.twig` — add `custom.css` link, restyle to match
- `web/templates/setup.twig` — add `custom.css` link, restyle to match
- `web/templates/_status.twig` — restyle status cards per design
- `web/templates/bots/list.twig` — wrap table in card, add header
- `web/templates/bots/edit.twig` — card-wrapped form, styled buttons
- `web/templates/bots/_actions.twig` — styled action buttons
- `web/templates/bots/_channels.twig` — styled channel pills and form
- `web/templates/networks/list.twig` — wrap table in card
- `web/templates/networks/edit.twig` — card-wrapped form
- `web/templates/networks/_servers.twig` — card-wrapped table and form
- `web/templates/networks/edit_server.twig` — card-wrapped form
- `web/templates/ignores/list.twig` — card-wrapped table and form
- `web/templates/services.twig` — already card-wrapped, restyle cards
- `web/templates/linktitles.twig` — restyle cards
- `web/templates/linktitles/channel.twig` — restyle card
- `web/templates/overview.twig` — minor: page heading style

### Unchanged Files

- `web/app.php` — no changes
- `web/routes.php` — no changes
- `web/auth.php` — no changes
- `web/index.php` — no changes
- `web/sections/*.php` — no changes
- `web/assets/bootstrap.min.css` — untouched
- `web/assets/htmx.min.js` — untouched
- `web/templates/_macros.twig` — untouched (macros generate Bootstrap classes that custom.css will override)

## Implementation Order

1. Create `web/assets/custom.css` with the full color palette and component styles
2. Update `base.twig` — new sidebar, slim top bar, custom.css link
3. Update `login.twig` and `setup.twig` — add custom.css, restyle
4. Update `_status.twig` — new status card design
5. Update all list templates — card-wrapped tables
6. Update all edit/form templates — card-wrapped forms, styled inputs
7. Update sub-component templates (_actions, _channels, _servers)
8. Final polish pass across all templates
