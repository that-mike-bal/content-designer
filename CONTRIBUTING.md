# Contributing to Content Designer

Thanks for considering it. This is a small, opinionated plugin, so the most useful thing you can do
before writing code is open an issue describing the problem you hit — it is cheap to agree on an
approach and expensive to rewrite a finished branch.

## What this plugin is trying to be

An editor's judgment about where a visual earns its place, applied to a draft, with the final say
always left to a human. Two rules fall out of that and are not up for negotiation:

- **It never publishes.** Nothing the plugin does changes a post's status or sends it live.
- **Decoration is a bug.** A placement has to justify itself — conceptualizing an idea, or pacing a
  long stretch of text. "The post could use a picture here" is not a reason.

Features that make the plugin a general-purpose image generator, or that put a visual in a post the
author didn't approve, are out of scope.

## Setting up

There is no build step and no npm. Clone the repository into `wp-content/plugins/content-designer`
on a development site and activate it:

```
git clone https://github.com/that-mike-bal/content-designer.git wp-content/plugins/content-designer
```

The admin screens are server-rendered through the Interactivity API, and the editor sidebar is
plain JavaScript against `wp.*` globals, so an edit is live on the next page load.

Composer is optional. `composer install` pulls in `wordpress/wp-ai-client` for the editorial text
pass on WordPress versions that don't bundle it; without it the plugin calls Gemini directly.

You will need your own Google Gemini API key — see the README's Configuration section. Put it in
`wp-config.php` rather than the settings field so it can't end up in a database dump you share.

## Running the checks

```
wp eval-file wp-content/plugins/content-designer/tests/anchor-mapping.php
wp eval-file wp-content/plugins/content-designer/tests/rest-outline.php
wp eval-file wp-content/plugins/content-designer/tests/rest-discard.php
```

They are plain WP-CLI scripts, not PHPUnit. Each prints one line per assertion and exits non-zero
on failure, and none of them spend an AI call. Run all three before opening a pull request.

If you change anchor resolution, add a case to `tests/anchor-mapping.php`. That file exists because
`anchor_index` and `anchor_ordinal` are easy to confuse and the symptom — images landing at roughly
double their intended position — looks like a model problem rather than an off-by-N.

## Code style

- WordPress Coding Standards for PHP: tabs, `snake_case` functions, `Class_Name` classes, full
  docblocks on anything public.
- Prefix everything that lands in a global namespace with `content_designer_` / `CONTENT_DESIGNER_`
  / `Content_Designer\`.
- Escape on output, sanitize on input, capability-check every REST route and admin action.
- Translate user-facing strings with the `content-designer` text domain.
- Comments explain *why*, especially where the obvious implementation is wrong. If you work out
  something non-obvious about how WordPress behaves, leave that in a comment — the anchor mapping
  note is the model.

## Commits and pull requests

Commit subjects are Conventional Commits, lowercase, and describe the effect rather than the edit:

```
feat(editor): illustrate from inside the post editor
fix(labels): describe where an image lands, not an internal block number
docs: document the editor panel and release 1.1.0
```

In the pull request, say what changes for the person using the plugin and how you checked it. If
you touched the pipeline, say what you ran it against — a real post and a real key, or the test
scripts.

## Adding a style, not a feature

Most "can it also draw X" requests are a style, not code. Save a style in the admin, or ship one
through the `content_designer_style_presets` filter — `launchy-style-preset.php` is a complete
example you can copy. New *media types* (diagrams, charts, video) are code, and plug in through
`content_designer_register_media_types` without touching the planner.

## Security

Please don't open a public issue for anything involving credential exposure or an authenticated
bypass. Email me@mikebal.com instead. See [SECURITY.md](SECURITY.md).

## License

Content Designer is GPL-2.0-or-later, and contributions are accepted under the same license.
