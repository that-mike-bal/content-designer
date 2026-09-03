=== Launchy Illustrator ===
Contributors: mikebal
Tags: ai, images, illustration, gemini, editorial
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI media director for your drafts. Scans a post, plans where supporting media earns its place, generates on-brand art, and places it for you.

== Description ==

Launchy Illustrator turns a finished draft into an illustrated post. It reads the
draft like an editor, decides where a visual genuinely helps (to conceptualize an
idea or to pace long text — never decoration), generates illustrations **in your
own configured style** with Google's Gemini image model ("Nano Banana"), lets you
choose between variations, and inserts the chosen images at the right section
boundaries — setting the hero as the featured image.

**Pick a style, or bring your own.** Choose from built-in style presets — Clean
editorial, Claymation, Paper cut art, Whiteboard marker, Retro illustration,
Modern tech design, Watercolor storybook — or write your own master style
prompt (palette, mascot, art direction) that overrides the preset. Plugins can
add presets to the picker via the `launchy_illustrator_style_presets` filter;
`examples/launchy-style-preset.php` packages the original "Launchy" mascot
style as an installable example, complete with character reference images.

Built on modern WordPress engineering primitives:

* **Abilities API** — the scan → plan → generate → place pipeline is registered as
  first-class `launchy-illustrator/*` abilities, so AI agents and MCP clients can
  drive the exact same flow as the admin UI.
* **AI Client** — the editorial planning pass uses the WordPress AI Client
  (`wp_ai_client_prompt()`) when available, falling back to a direct Gemini call.
* **Interactivity API** — the Tools → Illustrate screen is a server-rendered,
  directive-driven app with no custom build step.
* **Block editor SlotFill** — the Illustrate sidebar runs the same pipeline from
  inside the post editor, inserting images through the editor's own data stores
  so unsaved work survives and every image is its own undo step.

**Extensible by design.** Illustrations are just the first media type. Implement
`Launchy_Illustrator\Media_Types\Media_Type` and register it on the
`launchy_illustrator_register_media_types` action to add diagrams, charts,
infographics, or video — the planner, UI, REST API, and abilities pick it up
automatically. See `examples/example-diagram-media-type.php`.

== Configuration ==

1. Settings → Launchy Illustrator → add your Gemini API key (or define
   `LAUNCHY_ILLUSTRATOR_GEMINI_API_KEY` in `wp-config.php` — see
   `wp-config-sample.php`).
2. Pick a style — or duplicate one under "Your styles" and make it your own.
3. Illustrate a post either way:
   * **In the post editor** — open the **Illustrate** sidebar and scan the post
     you are writing. Nothing needs to be saved first.
   * **Tools → Illustrate** — pick a draft from a list and run the same pipeline.

== Changelog ==

= 1.1.0 =
* New: an **Illustrate** sidebar in the post editor. Scan the post you are
  editing, watch the plan build, and keep or reject each image as it arrives.
* New: create and save your own styles in the admin. A style bundles art
  direction, planner guidance, and reference images, and behaves exactly like a
  built-in preset everywhere it is used.
* New: duplicate any style — built-ins included — to use it as a starting point.
* New: reference images are chosen from the media library instead of typed as
  URLs or attachment IDs.
* Scanning now reads the editor's current content, so unsaved edits are included.
* Kept images are inserted through the block editor rather than by rewriting the
  post server-side: unsaved work survives and each image is a separate undo step.
* Placement labels now name the heading or opening words the image lands above
  instead of an internal block number, and correctly describe anchors that fall
  between blocks. Affects both the editor sidebar and Tools -> Illustrate.
* New REST routes: `launchy/v1/outline` (structural read, no AI call) and
  `launchy/v1/discard` (clean up rejected variations).
* Placements now carry `anchor_ordinal` alongside `anchor_index`. Code filtering
  `launchy_illustrator_placement_plan` will see the new field; `anchor_index` is
  unchanged.
* Changed: the three global override fields (custom style prompt, planner
  guidance, reference images) are gone from Settings. Existing values are
  migrated automatically into a saved style named "My style" on first admin
  load, preserving the output you were already getting.

= 1.0.0 =
* Initial release: draft scanning, AI placement planning, Gemini image
  generation with reference-image character consistency, variation picking,
  block placement, featured-image handling, Abilities API + REST surfaces, and a
  pluggable media-type system.
