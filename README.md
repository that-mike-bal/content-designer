# Content Designer

An AI media director for your WordPress drafts. It reads a post like an editor, decides where a
visual genuinely earns its place — to conceptualize an idea or to pace long text, never decoration
— generates it in **your** configured style with Google Gemini, and places it at the right section
boundary. It never publishes: placement stops at the draft.

Bring your own API key. The plugin ships no credentials, proxies nothing through a third party, and
calls Google directly with the key you configure.

Built on three modern WordPress primitives: the **Abilities API**, the **AI Client**, and the
**Interactivity API**.

## Requirements

- WordPress 6.7+ (Abilities API features require 7.0+)
- PHP 7.4+
- A Google Gemini API key — free from https://aistudio.google.com/apikey

## Installation

Copy the plugin directory into `wp-content/plugins/content-designer` and activate it, or install
the zip through **Plugins → Add New → Upload Plugin**.

## Configuration

The API key is resolved in this order, first match wins (`Settings::api_key()`):

1. `CONTENT_DESIGNER_GEMINI_API_KEY` constant in `wp-config.php` — **recommended**
2. `LAUNCHY_ILLUSTRATOR_GEMINI_API_KEY` constant — deprecated, see *Upgrading* below
3. `GEMINI_API_KEY` constant
4. `GEMINI_API_KEY` environment variable
5. The `gemini_api_key` field on Settings → Content Designer

Prefer a constant. It keeps the key out of the database, and therefore out of every backup and
database export. See `wp-config-sample.php`. The settings screen always names the source the key is
actually being read from, so a forgotten constant can't masquerade as a saved field.

Remaining settings live at **Settings → Content Designer**: style preset, your own saved styles,
planner guidance, reference images, how many variations to generate, and cleanup behavior.

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
featured image). Filter `content_designer_editor_post_types` to change that.

## Styles

Style is entirely settings-driven. Seven presets ship built in — clean editorial (default),
claymation, paper-cut, whiteboard, retro, modern-tech, and watercolor-storybook. You can also write
and save your own styles in the admin, with their own master prompt, planner guidance, and
character reference images.

Plugins add presets through the `content_designer_style_presets` filter. `launchy-style-preset.php`
is the worked example: "Launchy", the 1930s rubber-hose mascot style from
[mikebal.com](https://mikebal.com). Copy that file, swap the strings and reference URLs, and you
have your own brand preset.

It sits at the plugin root rather than in `examples/` on purpose: WordPress scans one level into
each plugin directory, so a second file with a plugin header there registers as its own activatable
plugin. Activate **Content Designer — Launchy Style Preset (example)** separately; its activation
hook selects the `launchy` preset unless you have already saved a style of your own.

## Extending

New media types extend `Media_Types\Media_Type` and register on the
`content_designer_register_media_types` action. The planner, admin UI, REST routes, and abilities
all pick them up automatically. See `examples/example-diagram-media-type.php`.

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

## Abilities

Registered under the `content-designer` category:

| Ability | Purpose |
| --- | --- |
| `content-designer/plan-media` | Scan a draft and plan placements |
| `content-designer/generate-media` | Generate art for a planned placement |
| `content-designer/place-media` | Insert media into the draft |

## Tests

There is no PHPUnit harness. The checks under `tests/` are plain scripts that run against a real
WordPress via WP-CLI, which is enough to cover the logic where a silent error would be expensive:

```
wp eval-file wp-content/plugins/content-designer/tests/anchor-mapping.php
wp eval-file wp-content/plugins/content-designer/tests/rest-outline.php
wp eval-file wp-content/plugins/content-designer/tests/rest-discard.php
```

Each prints one line per assertion and exits non-zero on failure. None of them spend an AI call.

## Upgrading from Launchy Illustrator 1.x

2.0.0 renames the plugin. The pipeline, settings, and REST contract are unchanged in behavior, but
every identifier moved:

| 1.x | 2.0.0 |
| --- | --- |
| Plugin directory `launchy-illustrator` | `content-designer` |
| `Launchy_Illustrator\` namespace | `Content_Designer\` |
| `launchy_illustrator_*` hooks | `content_designer_*` |
| `launchy/v1` REST namespace | `content-designer/v1` |
| `launchy-illustrator/*` abilities | `content-designer/*` |
| `LAUNCHY_ILLUSTRATOR_GEMINI_API_KEY` | `CONTENT_DESIGNER_GEMINI_API_KEY` |

What happens automatically: your settings, saved styles, and the planning/provenance meta on
existing posts are copied to the new names the first time 2.0.0 loads. The old rows are left in
place, so rolling back to 1.x still finds its data. The old API key constant still works, and the
settings screen will tell you to rename it.

What you have to do yourself:

- Because the directory name changed, WordPress sees a new plugin. Activate **Content Designer**,
  and deactivate Launchy Illustrator if it is still listed.
- If you deploy from git, point the deployment at `/wp-content/plugins/content-designer`.
- Any code of yours that filters `launchy_illustrator_*` or calls `launchy/v1` needs updating.
  There are no back-compat shims for hooks or routes.

## Contributing

Issues and pull requests are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
