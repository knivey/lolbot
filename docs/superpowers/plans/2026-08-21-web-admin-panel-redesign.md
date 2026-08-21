# Web Admin Panel Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Overhaul the lolbot web admin panel from raw Bootstrap 5.3 to a polished modern minimal design with warm charcoal + amber palette and card-heavy components.

**Architecture:** A single `custom.css` file overrides Bootstrap 5.3 defaults. All Twig templates are updated to use new markup for the refined sidebar, card-wrapped tables/forms, and styled status cards. No PHP backend changes.

**Tech Stack:** Bootstrap 5.3 (dark theme), custom CSS, Twig templates, htmx

**Spec:** `docs/superpowers/specs/2026-08-21-web-admin-panel-design.md`

---

### Task 1: Create `custom.css` with full color palette and base overrides

**Files:**
- Create: `web/assets/custom.css`

- [ ] **Step 1: Create the custom CSS file**

Create `web/assets/custom.css` with the complete color palette, body/sidebar/nav overrides, card styles, table styles, form input styles, button styles, badge styles, alert styles, and utility classes. See the spec for the full color palette table.

The CSS must include overrides for:
- Body: `background: #141416`, `color: #f5f5f7`
- `.sidebar`: 220px wide, `background: #1c1c1e`, flex column layout
- `.sidebar-brand`: 15px bold, bottom border
- `.sidebar-nav .nav-link`: 13px, `color: #8e8e93`, `border-left: 3px solid transparent`, transition on hover
- `.sidebar-nav .nav-link.active`: `background: #252528`, `border-left-color: #f59e0b`
- `.top-bar`: 40px height, `background: #1c1c1e`, right-aligned text
- `.card`: `background: #1c1c1e`, `border: 1px solid #2c2c2e`, `border-radius: 10px`
- `.table-card`: same card styling with `overflow: hidden`
- `.table-card-header`: flex row, space-between, bottom border
- `.table thead th`: uppercase, 11px, letter-spacing 0.5px, `color: #8e8e93`
- `.table tbody td`: 13px, `border-bottom: 1px solid #2c2c2e40`
- `.table tbody tr:hover`: `background: #252528`
- `.form-control`, `.form-select`: `background: #252528`, `border: 1px solid #3a3a3c`, `border-radius: 6px`
- `.form-control:focus`: `border-color: #f59e0b`, `box-shadow: 0 0 0 3px #f59e0b40`
- `.btn-primary`: `background: #f59e0b`, `color: #141416`, `border-radius: 6px`
- `.btn-primary:hover`: `background: #d97706`
- `.btn-outline-secondary`: `border-color: #3a3a3c`, `color: #8e8e93`; hover: amber border+text
- `.btn-outline-danger`: `color: #f87171`, `border-color: #f8717140`; hover: red bg tint
- `.badge.text-bg-secondary`: `background: #2c2c2e`, `border-radius: 20px`, `padding: 4px 12px`
- `.alert`: `border-radius: 8px`, `padding: 12px 16px`
- `.alert-danger`: `background: #f8717115`, `border: 1px solid #f8717130`, `color: #f87171`
- `.alert-success`: `background: #34d39915`, `border: 1px solid #34d39930`, `color: #34d399`
- `.alert-warning`: `background: #f59e0b15`, `border: 1px solid #f59e0b30`, `color: #f59e0b`
- `.status-dot`: 10px circle, `.connected` green, `.disconnected` red
- `.status-badge`: 12px pill with colored bg at 10% opacity
- `.page-heading`: 18px, 600 weight, 20px bottom margin
- Links: `color: #f59e0b`, hover `color: #d97706`
- `.text-body-secondary`: `color: #8e8e93 !important`
- `.navbar`: `display: none` (hide old Bootstrap navbar)
- `.form-check-input:checked`: amber background/border
- `.btn-group` checked states for linktitles inherit/on/off buttons
- Scrollbar styling for webkit browsers

- [ ] **Step 2: Verify CSS loads**

Open the panel in a browser and confirm the page loads without CSS errors.

- [ ] **Step 3: Commit**

```bash
git add web/assets/custom.css
git commit -m "Add custom.css with warm charcoal + amber palette for admin panel redesign"
```

---

### Task 2: Update `base.twig` — new sidebar, slim top bar, custom.css link

**Files:**
- Modify: `web/templates/base.twig`

- [ ] **Step 1: Rewrite base.twig**

Replace the entire contents with the new layout. Key changes:
- Add `<link rel="stylesheet" href="/assets/custom.css">` after bootstrap.min.css
- Replace the `<nav class="navbar">` with a `<div class="top-bar">` containing only the auth text
- Replace the `<aside>` with class `sidebar` (was inline-styled at 168px), set to 220px via CSS
- Add emoji icons to each nav link: ⬡ Overview, ⚙ Bots, 🌐 Networks, 🚫 Ignores, 🔧 Services, 🔗 Linktitles
- Use `sidebar-brand` class for the brand header
- Use `sidebar-nav` class for the nav list

- [ ] **Step 2: Verify layout renders**

Load the page in a browser. Confirm: sidebar is 220px wide with icons, active item has amber left border, top bar is slim with logout on right, content area has dark background.

- [ ] **Step 3: Commit**

```bash
git add web/templates/base.twig
git commit -m "Restyle base.twig: 220px sidebar with icons, slim top bar, custom.css"
```

---

### Task 3: Update `login.twig` and `setup.twig`

**Files:**
- Modify: `web/templates/login.twig`
- Modify: `web/templates/setup.twig`

- [ ] **Step 1: Update login.twig**

Add `custom.css` link after bootstrap.min.css. Wrap the form in a `<div class="card"><div class="card-body">`. Add a subtitle line "Enter your control key to continue". Make submit button full-width with `w-100` class.

- [ ] **Step 2: Update setup.twig**

Add `custom.css` link. Wrap content in a card. Keep the alert and instructions inside the card body.

- [ ] **Step 3: Verify**

Load `/login` in browser. Confirm: centered card with dark background, styled input, amber button.

- [ ] **Step 4: Commit**

```bash
git add web/templates/login.twig web/templates/setup.twig
git commit -m "Restyle login and setup pages with card layout and custom.css"
```

---

### Task 4: Update `_status.twig` — new status card design

**Files:**
- Modify: `web/templates/_status.twig`

- [ ] **Step 1: Rewrite _status.twig**

Replace the emoji-based status indicators with CSS classes:
- Use `<span class="status-dot {{ b.connected ? 'connected' : 'disconnected' }}">` instead of 🟢/🔴
- Use `<span class="status-badge {{ b.connected ? 'connected' : 'disconnected' }}">` for the status text
- Wrap each bot in a card with `class="card mb-3"` and `card-body`
- Layout: flex row with bot name+status on left, info text on right
- Action buttons below using `btn btn-outline-secondary btn-sm`
- Empty state: use `alert alert-warning` with ⚠ icon

- [ ] **Step 2: Verify**

Load the overview page. Confirm: bot status in rounded cards with colored dots, status badges, styled action buttons.

- [ ] **Step 3: Commit**

```bash
git add web/templates/_status.twig
git commit -m "Restyle status cards with colored dots, badges, and card layout"
```

---

### Task 5: Update list templates — card-wrapped tables

**Files:**
- Modify: `web/templates/bots/list.twig`
- Modify: `web/templates/networks/list.twig`
- Modify: `web/templates/ignores/list.twig`

- [ ] **Step 1: Update bots/list.twig**

Wrap the table in `<div class="table-card mb-3">`. Add a header div with `class="table-card-header"` containing the title and add button. Move the `<h1>` into the header as `<h2>`. Use `<thead>` for the header row. Remove the old `<p>` wrapping the add button.

- [ ] **Step 2: Update networks/list.twig**

Same pattern: `table-card` wrapper with `table-card-header` containing title and add button. Add `<thead>` to the table.

- [ ] **Step 3: Update ignores/list.twig**

Card-wrap the ignores table. Keep the add-ignore form below, wrapped in its own `<div class="card mb-3"><div class="card-body">`. Use `page-heading` class for the h1. Use `badge text-bg-secondary` for network badges.

- [ ] **Step 4: Verify**

Load each list page. Confirm: tables in rounded cards with headers, hover rows, amber edit links.

- [ ] **Step 5: Commit**

```bash
git add web/templates/bots/list.twig web/templates/networks/list.twig web/templates/ignores/list.twig
git commit -m "Wrap list tables in styled cards with headers"
```

---

### Task 6: Update edit/form templates

**Files:**
- Modify: `web/templates/bots/edit.twig`
- Modify: `web/templates/networks/edit.twig`
- Modify: `web/templates/networks/edit_server.twig`

- [ ] **Step 1: Update bots/edit.twig**

Wrap the main form in `<div class="card mb-3"><div class="card-body">`. Wrap the channels and actions sections in their own cards. Wrap the delete button in a card with danger styling. Use `page-heading` class for the h1. Use `alert alert-success` for the saved message, `alert alert-danger` for errors.

- [ ] **Step 2: Update networks/edit.twig**

Same pattern: card-wrapped form, card-wrapped servers section, danger-styled delete button.

- [ ] **Step 3: Update networks/edit_server.twig**

Card-wrap the form. Use `page-heading` for the h1.

- [ ] **Step 4: Verify**

Load each edit page. Confirm: forms in rounded cards, styled inputs with amber focus, amber submit buttons, red delete buttons.

- [ ] **Step 5: Commit**

```bash
git add web/templates/bots/edit.twig web/templates/networks/edit.twig web/templates/networks/edit_server.twig
git commit -m "Wrap edit forms in styled cards with amber inputs and danger delete buttons"
```

---

### Task 7: Update sub-component templates

**Files:**
- Modify: `web/templates/bots/_actions.twig`
- Modify: `web/templates/bots/_channels.twig`
- Modify: `web/templates/networks/_servers.twig`

- [ ] **Step 1: Update bots/_actions.twig**

Style action buttons with `btn btn-outline-secondary btn-sm`. Use `alert alert-success` for the queued message, `alert alert-danger` for errors.

- [ ] **Step 2: Update bots/_channels.twig**

Use `badge text-bg-secondary` for channel pills. Style the remove button as `btn btn-outline-secondary btn-sm`. Style the add-channel input and button consistently with the form styles.

- [ ] **Step 3: Update networks/_servers.twig**

Card-wrap the servers table with `table-card`. Style the add-server form. Use consistent button styling.

- [ ] **Step 4: Verify**

Load the bot edit and network edit pages. Confirm: action buttons, channel pills, and server tables all styled consistently.

- [ ] **Step 5: Commit**

```bash
git add web/templates/bots/_actions.twig web/templates/bots/_channels.twig web/templates/networks/_servers.twig
git commit -m "Style sub-components: action buttons, channel pills, server tables"
```

---

### Task 8: Update services and linktitles templates

**Files:**
- Modify: `web/templates/services.twig`
- Modify: `web/templates/linktitles.twig`
- Modify: `web/templates/linktitles/channel.twig`

- [ ] **Step 1: Update services.twig**

The cards are already wrapped. Ensure they use the new card styling (which custom.css provides automatically). Use `page-heading` for the h1. Style the info paragraph with `text-body-secondary`.

- [ ] **Step 2: Update linktitles.twig**

Use `page-heading` for the h1. Style the info paragraph. Ensure the network/channel cards use consistent card styling. Style the channel link buttons as `btn btn-sm btn-outline-secondary`.

- [ ] **Step 3: Update linktitles/channel.twig**

Use `page-heading` for the h1. Ensure consistent card styling.

- [ ] **Step 4: Verify**

Load services and linktitles pages. Confirm: consistent card styling, amber buttons, proper typography.

- [ ] **Step 5: Commit**

```bash
git add web/templates/services.twig web/templates/linktitles.twig web/templates/linktitles/channel.twig
git commit -m "Polish services and linktitles templates with consistent styling"
```

---

### Task 9: Final polish pass

**Files:**
- All template files (review only, fix any inconsistencies)

- [ ] **Step 1: Review all pages**

Load every page in the browser and check for visual inconsistencies:
- Sidebar active state correct on each page
- All cards have consistent border-radius and spacing
- All tables use thead/tbody correctly
- All buttons use consistent styling
- All alerts use the correct color variants
- No stray inline styles that conflict with custom.css
- Forms have consistent input styling

- [ ] **Step 2: Fix any issues found**

Make targeted fixes to any templates that are inconsistent.

- [ ] **Step 3: Final commit**

```bash
git add -A web/
git commit -m "Final polish pass on web admin panel redesign"
```
