=== Auto Alt Text Generator ===
Contributors: masterset2005
Tags: alt text, AI, accessibility, media library, images, SEO, WordPress 7.0
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

* **Bulk AI generation** — Process hundreds of images in one session with batch processing and real-time progress.
* **Three processing modes** — Process only images with missing alt text, images with missing or empty alt text, or regenerate all alt text.
* **Pause / Resume / Cancel** — Full control over long-running jobs. Pause at any time and pick up where you left off.
* **Per-image logging** — See exactly what was generated for each image, including errors and skips.
* **Smart system prompt** — The AI is instructed to produce concise alt text (<125 characters), avoid "Image of" prefixes, and return empty for decorative images.
* **Batch size control** — Adjust how many images are processed per AJAX request (1–20) to match your server's limits.
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

Only if you select "All images" mode. The default "Missing alt text only" and "Missing or empty alt text" modes preserve existing alt text.

= Can I cancel mid-processing? =

Yes. The Pause, Resume, and Cancel buttons give you full control. Cancelled jobs do not roll back already-processed images.

= What if an image fails? =

Failed images are logged with an error message and processing continues with the next image. You can review all errors in the processing log.

= Will the AI generate good alt text? =

The system instruction guides the AI to produce concise, descriptive alt text following accessibility best practices. It avoids "Image of" prefixes and returns empty for decorative images. You should always review AI-generated alt text for critical content.

== Screenshots ==

1. The Auto Alt Text admin page with mode selection and controls.
2. Real-time processing log showing per-image results.
3. Settings > Connectors screen where AI providers are configured.

== Changelog ==

= 1.0.0 =
* Initial release.
* Bulk AI alt text generation for media library images.
* Three processing modes: missing, poor, and all.
* Batch processing with pause/resume/cancel.
* Per-image progress logging.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
