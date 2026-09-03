# Linktitles reasoning (JSON) web form — design

Date: 2026-09-02
Status: approved (pending implementation)

## Problem

The Linktitles panel pages (global card, per-network cards, channel page)
render `reasoning (JSON)` as read-only text with the hint "editable via CLI".
Two problems: it isn't editable in the panel, and it isn't clear the shown
value is the *resolved cascade value* rather than the tier's own setting.

The backend already supports editing everywhere else:

- `ConfigService::LINKTITLES_KEYS` includes `ai_vision_reasoning`;
  `setLinktitlesSetting()` validates it as a string-keyed array
  (`normalizeStringKeyedArray()`), `resetLinktitlesSetting()` clears it to
  inherit, and both fire the change notifier (running bot picks it up live).
- `SettingsResolver::resolveLinktitles()` exposes
  `LinktitlesResolved->aiVisionReasoning` and
  `$sources['ai_vision_reasoning']` (tier that supplied it).

Only the web layer omits it.

## Decision

Make reasoning (JSON) a normal editable field on all three tier forms,
flowing through the existing descriptor pipeline, with these choices made
during brainstorming:

- **UX matches the other fields** (user-selected): textarea is prefilled only
  when *this tier* has its own value; when inherited it is blank with a hint
  showing the inherited value. Saving blank = inherit.
- **Compact JSON** (user decision): stored/displayed JSON is plain
  `json_encode()` output — no pretty-printing anywhere.
- **Server-side validation** (user decision): invalid JSON aborts the whole
  save with an error alert. No client-side JS validation.

Rejected alternatives: dedicated per-tier reasoning form (duplicated
scaffolding, two save buttons); struct-aware editor with separate
effort/enabled inputs (freezes the schema in the UI — the field is
deliberately freeform, CLI accepts any string-keyed object).

## UI

New field type `json` in the `inherit_field` macro (`web/templates/_macros.twig`):

- Renders `<textarea class="form-control font-monospace" rows="4">`.
- Same override semantics as the existing `textarea` branch: prefilled with
  the field value when `source == thisTier`, otherwise blank; hint shown only
  when not overridden.

Field descriptors (`web/sections/linktitles.php`):

- `web_lt_global_fields()` gains an `ai_vision_reasoning` descriptor:
  - override case (global row sets it): value = `json_encode($row->ai_vision_reasoning, JSON_UNESCAPED_SLASHES)`, source `global`;
  - otherwise: value `''`, source `default`, hint `default: (none)`.
- `web_lt_resolved_fields()` gains the descriptor:
  - override case (`$sources['ai_vision_reasoning'] == thisTier`): value = compact JSON of `$r->aiVisionReasoning`;
  - inherited case: value `''`, hint = `inherits: <compact JSON or (none)> (from <src>)`.

Templates: the static "editable via CLI" blocks and the
`globalReasoningJson` / `reasoningJson` template variables are removed from
`web/templates/linktitles.twig` and `web/templates/linktitles/channel.twig`
(the descriptor loop renders the field like every other one). The existing
page instructions ("Blank fields inherit …") already cover the new field.

## Save flow (`web_lt_apply()`)

1. Before applying any field, read `$_POST['ai_vision_reasoning']`, trim, and
   if non-empty parse it with a new pure helper
   `web_lt_parse_reasoning_json(string $raw): array`:
   - `json_decode($raw, true)` must succeed;
   - result must be an array whose keys are all strings (an empty array is
     valid); anything else throws `InvalidArgumentException`.
   Pre-parsing up front guarantees an invalid value aborts the whole save —
   no partial application of the other fields.
2. Empty string → `resetLinktitlesSetting($net, $chan, 'ai_vision_reasoning')`
   (inherit). Parsed array → `setLinktitlesSetting($net, $chan,
   'ai_vision_reasoning', $value)` (ConfigService re-validates string keys —
   harmless double check).
3. Validation failure re-renders the page with the message in the existing
   error alert (channel page → `web_linktitles_channel($chan->id, $msg)`;
   global/network → `web_linktitles($msg)`), mirroring the CSRF error path.
   Posted input is not preserved on error (consistent with the existing
   pattern). List-shaped JSON (`[1,2]`) and scalars (`5`) are rejected with a
   clear message.

## Testing

Following the existing `tests/Config/` function-surface conventions
(`ConfigTestCase`, globals `$em`/`$config`):

- `web_lt_parse_reasoning_json()`: valid object returns the array; `[]` valid;
  JSON list rejected; scalar JSON rejected; invalid syntax rejected.
- Descriptor emission: `web_lt_global_fields()` and `web_lt_resolved_fields()`
  include the reasoning descriptor with correct value/source/hint for both
  override and inherited cases (fixtures per `LinktitlesCascadeTest` style).

Manual verification: save round-trip per tier through the running panel.

## Verification

- `composer test`
- `vendor/bin/phpstan analyse web/ --no-progress` (scoped to touched paths;
  repo has a pre-existing baseline elsewhere)

## Out of scope

CLI commands unchanged (`linktitles:set` keeps working, including `inherit`).
No JS validation, no schema-specific sub-forms, no changes to
ConfigService/SettingsResolver.
