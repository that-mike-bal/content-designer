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

Remaining settings live at **Settings → Launchy Illustrator**: style preset, an optional custom
style prompt that overrides the preset, planner guidance, and reference images.

## Usage

**Tools → Illustrate.** Pick a draft, scan it, review the planned placements, generate, and place.

## Style presets

Style is entirely settings-driven. Seven presets ship built in — clean editorial (default),
claymation, paper-cut, whiteboard, retro, modern-tech, and watercolor-storybook. A custom style
prompt overrides whichever preset is selected, and presets can carry reference images.

Add your own through the `launchy_illustrator_style_presets` filter. `launchy-style-preset.php`
is a working example — the "Launchy (Leading Product)" preset.

It sits at the plugin root rather than in `examples/` on purpose: WordPress scans one level into
each plugin directory, so a second file with a plugin header there registers as its own
activatable plugin. That lets both ship through a single GitHub Deployment pointed at
`/wp-content/plugins/launchy-illustrator`. Activate **Launchy Illustrator — Launchy Style Preset**
separately; its activation hook selects the `launchy` preset unless a custom style prompt is set.

## Extending

New media types extend `Media_Types\Media_Type` and register on the
`launchy_illustrator_register_media_types` action. The planner, admin UI, REST routes, and
abilities all pick them up automatically. See `examples/example-diagram-media-type.php`.

## Abilities

Registered under the `launchy-illustrator` category:

| Ability | Purpose |
| --- | --- |
| `launchy-illustrator/plan-media` | Scan a draft and plan placements |
| `launchy-illustrator/generate-media` | Generate art for a planned placement |
| `launchy-illustrator/place-media` | Insert media into the draft |

## License

GPL-2.0-or-later
