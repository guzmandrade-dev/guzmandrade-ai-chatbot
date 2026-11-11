# AGENTS.md — Guzmandrade AI Chatbot

AI-powered frontend chatbot for WordPress. It leverages the WordPress 7.0+
Connectors API and the PHP AI Client SDK (`wp_ai_client_prompt()`), so the
plugin contains no custom AI provider code. Spam protection via Akismet
(rate-limit fallback), captcha via the Simple CAPTCHA with Cloudflare Turnstile
plugin, lead capture via AI function calling, and a knowledge base built on the
`gaic_article` custom post type (plus optional posts/pages as context).

## Layout

- `guzmandrade-ai-chatbot.php` — plugin header (single source for `Version`)
  and bootstrap; defines `GAIC_VERSION`.
- `includes/` — `class-gaic-plugin.php` (orchestrator) plus one class per
  module: settings, knowledge-base, chatbot (shortcode/REST/frontend), spam,
  captcha, lead-capture, citations.
- `assets/js/chatbot.js`, `assets/css/chatbot.css` — vanilla JS/CSS widget, no
  build step. `formatMessage()` in the JS escapes HTML, then restores `**bold**`
  and `[text](https://…)` markdown links — anything the AI emits as a markdown
  link becomes a clickable, safe link in the chat. Conversation history is
  persisted in `localStorage` (key `gaic-chat-history-v1`) and re-rendered on
  load; entries are validated on restore (roles, length, same-origin source
  URLs) so tampered storage cannot inject markup or links. CSS colors derive
  from the active theme's `theme.json` tokens (`--wp--preset--color--*`) with
  fallbacks — never use the `base` (background) token for text.
- `bin/normalize-zip-dirname.php` — post-processes the dist ZIP so the root
  folder matches the plugin slug.
- `languages/` — POT template, regenerated on build.
- `assets/icon.svg`, `assets/icon-128x128.png`, `assets/icon-256x256.png` —
  WordPress.org **repository assets**: an original robot-chatbot icon
  (inspired by Gravatar's *generated* avatars, but drawn for this plugin —
  not Gravatar artwork). Synced to the SVN `/assets/` directory and excluded
  from the dist ZIP via `.distignore`. To change the icon, edit `icon.svg`
  and re-rasterize the two PNGs at 256/128 px.

## Commands

- `npx @wordpress/env <command>` — local dev environment (use this, not
  `npx wp-env`, which is deprecated). Site: http://localhost:8888
  (`npx @wordpress/env start|stop|destroy`).
- `composer lint` / `composer lint:fix` — PHPCS (WordPress Coding Standards).
- `composer analyze` — PHPStan.
- `composer build` — regenerates the POT file and builds
  `guzmandrade-ai-chatbot.zip` via `wp dist-archive` (honors `.distignore`),
  then normalizes the ZIP root folder. Requires `wp` (WP-CLI), `php`, and
  `composer` on PATH.

## Local testing & release validation (important)

The plugin is **intentionally installed in wp-env from the generated ZIP**, not
mounted through `.wp-env.json`. The **Plugin Check** plugin (`plugin-check`) is
installed automatically via the `plugins` list in `.wp-env.json`, so every
release build can be validated before publishing. Note: this wp-env version
requires full URLs in `plugins` (bare slugs are rejected) — use the
WordPress.org zip URL (`https://downloads.wordpress.org/plugin/<slug>.<version>.zip`)
or a GitHub *release asset* URL; a GitHub `archive/refs/tags/…zip` URL mounts
under the wrong basename and breaks activation.

Release checklist:

1. `composer lint` and `composer analyze` pass.
2. `composer build` → fresh `guzmandrade-ai-chatbot.zip` (check `dist/`).
3. Install the ZIP into the wp-env site, e.g. unzip over
   `wp-content/plugins/guzmandrade-ai-chatbot/` in the running env, then
   `npx @wordpress/env run cli -- wp plugin activate guzmandrade-ai-chatbot`.
4. `npx @wordpress/env run cli -- wp plugin check guzmandrade-ai-chatbot`
   (Plugin Check CLI) — must show no errors; also check Tools → Plugin Check in
   the admin. Fix any findings before tagging.
5. Smoke-test the REST status endpoint:
   `curl http://localhost:8888/wp-json/gaic/v1/status` and the chat endpoint
   `POST /wp-json/gaic/v1/chat`.
6. Test against the **latest stable WordPress** — wp-env defaults to `latest`;
   confirm with `npx @wordpress/env run cli -- wp core version`.

## Versioning & headers

- Bump the version in **all** of these together: the `Version:` header and
  `GAIC_VERSION` in `guzmandrade-ai-chatbot.php`, `Stable tag:` in `readme.txt`,
  and a new `== Changelog ==` entry in `readme.txt`.
- `Requires at least:` reflects the minimum WP that provides the Connectors
  API (currently 7.0). `Tested up to:` in `readme.txt` uses major.minor of the
  latest stable WP verified in wp-env (currently 7.1, i.e. 7.1.x).

## Coding standards

- WordPress Coding Standards (WPCS) + PHPStan; run both before committing.
- Prefix everything `GAIC_` / `gaic_`; text domain `guzmandrade-ai-chatbot`.
- Sanitize input, escape output, nonces + capability checks on any REST/AJAX
  write, `$wpdb->prepare()` for queries, enqueue assets (no inline scripts).
- Keep `.distignore` in sync: anything that is dev-only (including this
  `AGENTS.md`) must be listed there so it stays out of the distributed ZIP.
