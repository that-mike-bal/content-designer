# Security Policy

## Supported versions

The latest release is the supported one. Fixes are issued against `main`.

## Reporting a vulnerability

Email **me@mikebal.com** with the details. Please don't open a public issue first — the plugin
handles an API key and runs privileged admin actions, so a report is better read before it is
indexed.

Useful things to include: what an attacker can do, the capability or role needed, the plugin and
WordPress versions, and a reproduction if you have one. You'll get an acknowledgement, and credit
in the changelog unless you'd rather not have it.

## Where the sensitive parts are

- **The Gemini API key.** Resolved in `Content_Designer\Settings::api_key()`. A key defined as a
  constant is never written to the database; a key saved on the settings screen lives in the
  options table and is readable by anyone with `manage_options`.
- **REST routes** under `content-designer/v1`, and the `content-designer/*` abilities. Both run
  model calls and write attachments, so both are capability-gated.
- **Reference images** are downloaded from URLs the site owner configures and cached under
  `wp-content/uploads/content-designer/refs`.
