=== Guzmandrade AI Chatbot ===
Contributors: h4l9k
Tags: chatbot, ai, chat, spam, captcha
Requires at least: 7.0
Requires PHP: 8.0
Tested up to: 7.1
Stable tag: 1.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered frontend chatbot with a knowledge base, Akismet spam protection, and Cloudflare Turnstile captcha.

== Description ==

Guzmandrade AI Chatbot adds an AI-powered chatbot to the frontend of your WordPress site. It leverages the WordPress 7.0 Connectors API and the PHP AI Client SDK — meaning no custom AI provider integration code is needed. Configure your AI provider under **Settings › Connectors** and you're ready.

= Features =

* **Frontend Chat Widget** — A floating chat button that opens a conversational panel. Place it site-wide or via shortcode. The conversation is stored in the browser (localStorage) and restored on page reload.
* **Knowledge Base** — A custom post type (`gaic_article`) for curated content the chatbot can reference. The article content is where you write the information the chatbot should know. Optionally include standard posts and pages as context.
* **Citations** — Answers cite the knowledge base sources they use. The AI references sources by number via a `cite_sources` function call, and the plugin turns those into clickable links to the internal post/page (plus a sources list under the message). Links are built server-side from permalinks, so only real internal URLs can be cited.
* **Spam Protection** — Integrates with Akismet to detect and block spam messages. Falls back to rate-limiting when Akismet is not available.
* **Captcha Protection** — Integrates with the Simple CAPTCHA with Cloudflare Turnstile plugin to verify human visitors before they can send messages.
* **Lead Capture** — Uses AI function calling to detect when users share contact information in conversation and automatically saves leads. Delivers via email and/or webhook (connects to Zapier, Make, n8n, Google Sheets, CRMs). No forms or database tables needed.
* **Minimal Custom Code** — Uses WordPress Core's Connectors API for AI provider settings, the PHP AI Client SDK for AI calls, Akismet for spam, and Cloudflare Turnstile for captcha — no duplicate infrastructure.

= Dependencies =

* **WordPress 7.0+** — Provides the Connectors API for AI provider configuration.
* **AI Plugin or Core AI** — The PHP AI Client SDK must be available (`wp_ai_client_prompt()`). Install the [AI plugin](https://wordpress.org/plugins/ai/) if running WP < 7.0 or need the latest features.
* **AI Provider Connector** — Install a provider connector plugin (e.g., [OpenAI](https://wordpress.org/plugins/ai-provider-for-openai/), [Anthropic](https://wordpress.org/plugins/ai-provider-for-anthropic/), [Google](https://wordpress.org/plugins/ai-provider-for-google/)) and configure the API key under Settings › Connectors.
* **Akismet** (recommended) — For spam detection on chat messages. Rate-limiting fallback is used when Akismet is not active.
* **Simple CAPTCHA with Cloudflare Turnstile** (optional) — For captcha verification on chat messages. Install and configure it at Settings › Cloudflare Turnstile, then enable captcha in the chatbot settings.

== Installation ==

1. Upload the `guzmandrade-ai-chatbot` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Settings › Guzmandrade AI Chatbot** to configure the chatbot.
4. Enable the chatbot toggle and configure the system instructions.
5. Ensure an AI provider connector is installed and configured under **Settings › Connectors**.
6. Optionally install and activate Akismet for spam protection.
7. Optionally install the Simple CAPTCHA with Cloudflare Turnstile plugin for captcha protection, configure it at Settings › Cloudflare Turnstile, then enable captcha in the chatbot settings.

== Frequently Asked Questions ==

= Where do I configure my AI API key? =

Guzmandrade AI Chatbot does not manage API keys. Use the WordPress 7.0 **Settings › Connectors** screen to register and configure AI providers (OpenAI, Anthropic, Google, etc.).

= Can I use the chatbot on specific pages only? =

Yes. Use the `[gaic_chatbot]` shortcode on any page or post. For a site-wide floating widget, enable it in the plugin settings.

= Can I show a Gravatar instead of the "AI" badge? =

Yes. Enter an email address in the "Assistant avatar email" field in the plugin settings and the assistant's messages will display its Gravatar. If the email has no Gravatar, or the field is empty, your default avatar from Settings › Discussion is used — the same fallback as comment avatars.

= How does the knowledge base work? =

Knowledge Base Articles (the `gaic_article` custom post type) are the primary knowledge source. You create articles with a title and content — the content is where you write the information/instructions the chatbot should know. You can also include standard posts and pages. The plugin retrieves relevant content based on keywords in the user's message and includes it as context for the AI.

= What happens if Akismet is not installed? =

Rate-limiting is used as a fallback. Each IP address can send a limited number of messages per hour (configurable in settings). Install Akismet for full spam detection.

= How does captcha work? =

Captcha uses Cloudflare Turnstile via the "Simple CAPTCHA with Cloudflare Turnstile" plugin. After installing and configuring that plugin (Settings › Cloudflare Turnstile), enable captcha in the chatbot settings. A Turnstile challenge widget will appear in the chat panel before users can send their first message.

== Changelog ==

= 1.2.0 =
* Maintenance release tested against the latest WordPress (7.1.4).
* Added AGENTS.md with development and release-validation instructions (excluded from the distribution ZIP via .distignore).
* Added optional citation references to responses (disabled by default — enable under Settings › Guzmandrade AI Chatbot › Knowledge Base): the AI cites numbered knowledge base sources via a `cite_sources` function call; citations render as clickable internal links plus a sources list under each answer.
* The conversation is now persisted in the browser (localStorage) and restored after a page reload.
* Fixed the sources label/avatar colors to derive from the theme's contrast token so they stay readable on light and dark themes.
* Added a WordPress.org plugin icon (original robot design inspired by Gravatar's generated avatars) under `assets/`.

= 1.1.1 =
* Renamed the plugin from Just Another Generic Chatbot to Guzmandrade AI Chatbot.

= 1.1.0 =
* Renamed the plugin from AI Connector Chatbot to Just Another Generic Chatbot.
* Added an optional Gravatar for the assistant, configured by email in the plugin settings.
* The assistant avatar falls back to your default avatar from Settings › Discussion, the same fallback as comment avatars.

= 1.0.0 =
* Initial release.
* Frontend chatbot widget (floating button + chat panel).
* Knowledge base via `gaic_article` custom post type with keyword-based context retrieval.
* Akismet spam integration with rate-limiting fallback.
* Cloudflare Turnstile captcha integration via Simple CAPTCHA plugin.
* Lead capture via AI function calling — delivers leads via email and/or webhook (Zapier, Make, n8n, Google Sheets, CRMs).
* Admin settings page under Settings › Guzmandrade AI Chatbot.
* Leverages WordPress 7.0 Connectors API and PHP AI Client SDK.
