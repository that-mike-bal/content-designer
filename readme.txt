=== Content Designer ===
Contributors: mikebal
Tags: ai, images, illustration, gemini, editorial
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI media director for your drafts. Reads a post, plans where a visual earns its place, generates it in your style, and places it. Bring your own key.

== Description ==

Content Designer turns a finished draft into an illustrated post. It reads the
draft like an editor, decides where a visual genuinely helps (to conceptualize
an idea or to pace long text — never decoration), generates illustrations **in
your own configured style** with Google's Gemini image model, lets you choose
between variations, and inserts the chosen images at the right section
boundaries — setting the hero as the featured image. It stops at the draft and
never publishes for you.

**Bring your own key.** You supply a Google Gemini API key and the plugin calls
Google directly with it. Nothing is proxied through a third-party service, no
account is required, and no credentials ship with the plugin. Define the key as
a constant in `wp-config.php` and it never touches your database — the settings
screen tells you exactly which source the key in use is coming from.

**Pick a style, or build your own.** Choose from built-in style presets — Clean
editorial, Claymation, Paper cut art, Whiteboard marker, Retro illustration,
Modern tech design, Watercolor storybook — or write and save your own styles,
each with its own master style prompt, planner guidance, and character
reference images for consistent recurring characters. Plugins can add presets
to the picker via the `content_designer_style_presets` filter;
`launchy-style-preset.php` is a complete worked example you can copy.

Built on modern WordPress engineering primitives:

* **Abilities API** — the scan → plan → generate → place pipeline is registered as
  first-class `content-designer/*` abilities, so AI agents and MCP clients can
  drive the exact same flow as the admin UI.
* **AI Client** — the editorial planning pass uses the WordPress AI Client
  (`wp_ai_client_prompt()`) when available, falling back to a direct Gemini call.
* **Interactivity API** — the Tools → Illustrate screen is a server-rendered,
  directive-driven app with no custom build step.
* **Block editor SlotFill** — the Illustrate sidebar runs the same pipeline from
  inside the post editor, inserting images through the editor's own data stores
  so unsaved work survives and every image is its own undo step.

**Extensible by design.** Illustrations are just the first media type. Implement
`Content_Designer\Media_Types\Media_Type` and register it on the
`content_designer_register_media_types` action to add diagrams, charts,
infographics, or video — the planner, UI, REST API, and abilities pick it up
automatically. See `examples/example-diagram-media-type.php`.

Source, issues, and contributing guidelines:
https://github.com/that-mike-bal/content-designer

== Installation ==

1. Install and activate the plugin.
2. Get a Google Gemini API key at https://aistudio.google.com/apikey
3. Settings → Content Designer → paste the key (or define
   `CONTENT_DESIGNER_GEMINI_API_KEY` in `wp-config.php`, which keeps it out of
   the database — see `wp-config-sample.php`).
4. Pick a style preset, or save a style of your own.
5. Illustrate a post either way:
   * **In the post editor** — open the **Illustrate** sidebar and scan the post
     you are writing. Nothing needs to be saved first.
   * **Tools → Illustrate** — pick a draft from a list and run the same pipeline.

== Frequently Asked Questions ==

= Do I need an account or a subscription? =

No. You need your own Google Gemini API key, which is free to create. The plugin
has no service behind it and no account of its own.

= Where does my API key go? =

To Google, and nowhere else. If you define it as a constant in `wp-config.php`
it is never written to your database. If you save it on the settings screen it
is stored in the options table like any other setting.

= Will it publish or change my posts without asking? =

No. The pipeline stops at the draft. In the editor, images are inserted only
after you keep them, as ordinary undoable block insertions. On Tools →
Illustrate, media is placed into the draft's content and the post is left in
whatever status it already had.

= What does it cost to run? =

Whatever Google charges for the models you point it at — one text call to plan a
post, then one image call per variation. Variation counts are configurable, and
rejected variations can be deleted automatically.

= Can I use a different AI provider? =

Image generation calls Gemini directly, because reference images and aspect
ratios are honored there. The editorial text pass goes through the WordPress AI
Client when it is available, which is provider-agnostic.

= Can I add my own brand style? =

Yes — either save a style in the admin, or ship one in code through the
`content_designer_style_presets` filter. `launchy-style-preset.php` in the
plugin root is a working example, complete with character reference images.

== Changelog ==

= 2.0.0 =
* Renamed: the plugin is now **Content Designer**. The directory, namespace,
  hooks, REST namespace, abilities, and the API key constant all changed. See
  the upgrade notice below.
* Settings, saved styles, and existing posts' planning meta are carried over
  automatically on first load; the old rows are left in place so a rollback
  still works.
* The pre-2.0 `LAUNCHY_ILLUSTRATOR_GEMINI_API_KEY` constant is still honored,
  and the settings screen asks you to rename it.
* Documentation rewritten for self-hosted use, with the bring-your-own-key
  setup, costs, and privacy spelled out. Added contributing guidelines.

= 1.1.1 =
* Housekeeping release so deploys are visible in the activity log.

= 1.1.0 =
* New: an **Illustrate** sidebar in the post editor. Scan the post you are
  editing, watch the plan build, and keep or reject each image as it arrives.
* New: create and save your own styles in the admin.
* Scanning now reads the editor's current content, so unsaved edits are included.
* Kept images are inserted through the block editor rather than by rewriting the
  post server-side: unsaved work survives and each image is a separate undo step.
* Placement labels now name the heading or opening words the image lands above
  instead of an internal block number.
* New REST routes: `outline` (structural read, no AI call) and `discard` (clean
  up rejected variations).
* Placements now carry `anchor_ordinal` alongside `anchor_index`.
* The settings screen now names which source the API key in use comes from.

= 1.0.0 =
* Initial release: draft scanning, AI placement planning, Gemini image
  generation with reference-image character consistency, variation picking,
  block placement, featured-image handling, Abilities API + REST surfaces, and a
  pluggable media-type system.

== Upgrade Notice ==

= 2.0.0 =
The plugin directory changed from launchy-illustrator to content-designer, so
WordPress lists this as a new plugin: activate Content Designer and deactivate
Launchy Illustrator. Your settings and styles carry over automatically. Code
that filters launchy_illustrator_* hooks or calls the launchy/v1 REST namespace
must be updated — there are no back-compat shims.
