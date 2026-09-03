# Launchy Illustrator

AI media director for [Leading Product](https://mikebal.com). Scans a draft, plans where
supporting media actually earns its place, generates on-brand illustrations with Gemini, and
places them into the post. It never publishes — placement stops at the draft.

Built on three modern WordPress primitives: the **Abilities API**, the **AI Client**, and the
**Interactivity API**.

## Requirements

- WordPress 6.7+ (Abilities API features require 7.0+)
- PHP 7.4+
- A Google Gemini API key — https://aistudio.google.com/apikey

## Installation

Deployed to mikebal.com via WordPress.com GitHub Deployments: this repository's root maps to
`/wp-content/plugins/launchy-illustrator`, simple mode, no build step. Pushes to `main` deploy
automatically.

For any other site, copy the directory into `wp-content/plugins/` and activate.

## Configuration

The API key is resolved in this order, first match wins (`Settings::api_key()`):

1. `LAUNCHY_ILLUSTRATOR_GEMINI_API_KEY` constant in `wp-config.php` — **recommended**
2. `GEMINI_API_KEY` constant
3. `GEMINI_API_KEY` environment variable
4. The `gemini_api_key` field on Settings → Launchy Illustrator

Prefer the constant. It keeps the key out of the database, and therefore out of every backup
and database export. See `wp-config-sample.php`.

Remaining settings live at **Settings → Launchy Illustrator**: which style to use, model names,
and how many variations to generate. Art direction itself lives in styles (below).

## Usage

Two surfaces, one pipeline.

**In the post editor.** Open the **Illustrate** sidebar and scan the post you are writing. The
structural read lands immediately, placements follow when the editorial pass returns, and images
appear one at a time — keep or reject each as it arrives, while the rest are still rendering.

Scanning sends the editor's current content, so unsaved edits are included and nothing needs
saving first. Kept images are inserted with `insertBlocks`, which means the post is never rewritten
underneath you and every image is its own undo step. Anchors are pinned to block clientIds right
after the scan, so you can keep writing during a run without images landing in the wrong place.

**Tools → Illustrate.** Pick a draft from a list and run the same pipeline. This surface writes to
`post_content` server-side, so use it when the post is not already open in the editor.

The panel appears on post types supporting both `editor` and `thumbnail` (the hero sets the
featured image). Filter `launchy_illustrator_editor_post_types` to change that.

## Styles

A style is one named bundle of art direction: a **prompt** (the master style block prepended to
every image), **planner guidance** (what kind of scenes suit it), and **reference images** that keep
characters and palette consistent.

Seven ship built in — clean editorial (default), claymation, paper-cut, whiteboard, retro,
modern-tech, and watercolor-storybook.

### Making your own

**Settings → Launchy Illustrator → Your styles.** Pick any existing look, hit *Duplicate to my
styles*, and edit it — the built-ins are starting points, not fixed choices. Reference images come
from the media library picker; a style duplicated from a preset keeps that preset's references
(which may be URLs) and lets you add attachments alongside them.

Saved styles are stored in the `launchy_illustrator_user_styles` option and merged into the
registry through the same filter plugin presets use, so nothing downstream distinguishes them from
a built-in.

### Shipping a style in code

Add one through the `launchy_illustrator_style_presets` filter. `launchy-style-preset.php` is a
working example — the "Launchy (Leading Product)" preset.

It sits at the plugin root rather than in `examples/` on purpose: WordPress scans one level into
each plugin directory, so a second file with a plugin header there registers as its own
activatable plugin.

### Upgrading from 1.0.0

1.0.0 had three global override fields that replaced parts of the selected preset. Those are gone.
On first admin load after upgrading, any values you had are snapshotted — override where set,
preset where not — into a saved style called **My style**, which is then selected. The effective
output does not change.

## Extending

New media types extend `Media_Types\Media_Type` and register on the
`launchy_illustrator_register_media_types` action. The planner, admin UI, REST routes, and
abilities all pick them up automatically. See `examples/example-diagram-media-type.php`.

### Anchors

A placement carries two positions, and they are not interchangeable:

| Field | Meaning | Used by |
| --- | --- | --- |
| `anchor_index` | Raw `parse_blocks()` offset | `Media_Placer` (Tools screen) |
| `anchor_ordinal` | Position among blocks the scan kept | The editor panel |

They differ because `parse_blocks()` emits a null-name freeform block for the whitespace between
every pair of real blocks, and the editor never shows those. Three authored blocks parse to five
entries. Handing `anchor_index` to `insertBlocks()` would place images at roughly double their
intended position. `tests/anchor-mapping.php` locks this down.

## Tests

There is no PHPUnit harness. The checks under `tests/` are plain scripts that run against a real
WordPress via WP-CLI, which is enough to cover the logic where a silent error would be expensive:

```
wp eval-file wp-content/plugins/launchy-illustrator/tests/anchor-mapping.php
wp eval-file wp-content/plugins/launchy-illustrator/tests/rest-outline.php
wp eval-file wp-content/plugins/launchy-illustrator/tests/rest-discard.php
```

Each prints one line per assertion and exits non-zero on failure. None of them spend an AI call.

## Abilities

Registered under the `launchy-illustrator` category:

| Ability | Purpose |
| --- | --- |
| `launchy-illustrator/plan-media` | Scan a draft and plan placements |
| `launchy-illustrator/generate-media` | Generate art for a planned placement |
| `launchy-illustrator/place-media` | Insert media into the draft |

## License

GPL-2.0-or-later
