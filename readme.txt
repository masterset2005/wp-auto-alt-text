=== Auto Alt Text Generator ===
Contributors: masterset2005
Tags: alt text, accessibility, images, media library, AI
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically generate and improve alt text for every image in your media library using the WordPress 7.0 built-in AI Client.

== Description ==

Auto Alt Text Generator harnesses the WordPress 7.0 AI Client to scan your entire media library and generate descriptive, accessible alt text for every image. No more manually editing each image — let AI handle the busywork while keeping you in control.

= Features =

* **Bulk AI generation** — Process thousands of images in one session. Batches run sequentially until all images are processed (no total limit).
* **Review & Improve mode** — AI evaluates your existing alt text against the image and keeps it if good, replaces it if generic or inaccurate.
* **Four processing modes** — Missing only, missing/empty, review existing, or regenerate all.
* **Pause / Resume / Cancel** — Full control over long-running jobs. Pause at any time and pick up where you left off.
* **Per-image logging** — See exactly what was generated for each image, including whether it was changed or kept, plus errors and skips.
* **Smart system prompt** — The AI is instructed to produce concise alt text (<125 characters), avoid "Image of" prefixes, and return empty for decorative images.
* **Adjustable batch size** — Control how many images are processed per AJAX request (1–20) to match your server's limits.
* **Uses WP 7.0 AI Client** — No third-party API keys required beyond what you configure in Settings > Connectors. Works with Anthropic, Google, and OpenAI provider plugins.

= Requirements =

* WordPress 7.0 or later
* At least one AI provider connected under Settings > Connectors (AI Provider for Anthropic, Google, or OpenAI)
* PHP 8.0 or later

== Installation ==

1. Upload the `wp-auto-alt-text` folder to `/wp-content/plugins/`, or install via Plugins > Add New.
2. Activate the plugin through the Plugins screen.
3. Go to **Settings > Connectors** and connect at least one AI provider (Anthropic, Google, or OpenAI).
4. Go to **Media > Auto Alt Text**.
5. Select your processing mode and batch size, then click **Start Processing**.

== Frequently Asked Questions ==

= Do I need an API key? =

Yes. The plugin uses the WordPress 7.0 AI Client, which requires an AI provider plugin and an API key configured in Settings > Connectors.

= Which AI providers are supported? =

Any provider plugin compatible with the WordPress 7.0 AI Client. Officially supported providers include Anthropic (Claude), Google (Gemini), and OpenAI (GPT).

= Will this overwrite my existing alt text? =

Only if you select "All images" mode. "Missing only" and "Missing or empty" modes leave existing alt text untouched. "Review & Improve" mode passes your existing alt to the AI for evaluation — it keeps it if accurate and descriptive, replaces it if generic or inaccurate.

= Can I cancel mid-processing? =

Yes. The Pause, Resume, and Cancel buttons give you full control. Cancelled jobs do not roll back already-processed images.

= What if an image fails? =

Failed images are logged with an error message and processing continues with the next image. You can review all errors in the processing log.

= Will the AI generate good alt text? =

The system instruction guides the AI to produce concise, descriptive alt text following accessibility best practices. It avoids "Image of" prefixes and returns empty for decorative images. You should always review AI-generated alt text for critical content.

== External Services ==

This plugin uses the WordPress 7.0 AI Client (`wp_ai_client_prompt()`) to generate alt text. Depending on the AI provider you configure under Settings > Connectors, the following data is sent to third-party AI services:

* The image file (as a data URI) is transmitted to the AI provider for analysis
* The text prompt and system instructions are sent as part of the API request
* No personally identifiable information, login details, or site configuration data is transmitted

Supported providers include Anthropic (Claude), Google (Gemini), and OpenAI (GPT). You must explicitly configure and activate at least one provider plugin and enter your own API key. No data is sent until you do so.

* **Anthropic**: https://www.anthropic.com/legal/consumer-terms
* **Google**: https://policies.google.com/privacy
* **OpenAI**: https://openai.com/policies/privacy-policy

== Screenshots ==

1. The Auto Alt Text admin page with mode selection and controls.
2. Real-time processing log showing per-image results.
3. Settings > Connectors screen where AI providers are configured.

== Changelog ==

= 1.0.0 =
* Initial release.
* Bulk AI alt text generation for media library images.
* Four processing modes: missing, poor, review, and all.
* Review mode: AI evaluates existing alt text and keeps or improves it.
* Batch processing with pause/resume/cancel.
* Per-image progress logging with changed/kept indicators.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
