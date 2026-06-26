=== Auto Alt Text Generator ===
Contributors: masterset2005
Tags: alt text, accessibility, images, media library, AI
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.1
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fill missing, review and improve, or regenerate alt text for your entire media library using the WordPress 7.0 AI Client — directly from the Media Library bulk actions.

== Description ==

Auto Alt Text Generator adds **Fill Missing Alt Text**, **Review & Improve Alt Text**, and **Regenerate Alt Text** bulk actions to the Media Library list view. Select any image, choose an action, and it automatically processes every matching image in your library. No separate admin page needed — it integrates right into your existing workflow.

= Features =

* **Fill Missing Alt Text** — Scans your entire library for images without alt text and generates descriptive alt text for each one. Images that already have alt text are skipped.
* **Review & Improve Alt Text** — AI evaluates each image's existing alt text against the image itself. If the alt is accurate and descriptive, it's kept as-is. If it's generic, missing, or inaccurate, a better version is generated.
* **Regenerate Alt Text** — Force-generate new alt text for every image, replacing whatever is there.
* **Compare display** — Each result shows the previous alt text, the new alt text, and the decision (KEPT, REPLACED, ADDED) in a compact inline log.
* **Smart system prompt** — The AI is instructed to produce concise alt text (<125 characters), avoid "Image of" prefixes, and return empty for decorative images.
* **Sequential processing** — Images are processed one by one with a visible progress notice and per-image results.
* **Uses WP 7.0 AI Client** — No third-party API keys required beyond what you configure in Settings > Connectors. Works with Anthropic, Google, and OpenAI provider plugins.

= Requirements =

* WordPress 7.0 or later
* At least one AI provider connected under Settings > Connectors (AI Provider for Anthropic, Google, or OpenAI)
* PHP 8.0 or later

== Installation ==

1. Upload the `wp-auto-alt-text` folder to `/wp-content/plugins/`, or install via Plugins > Add New.
2. Activate the plugin through the Plugins screen.
3. Go to **Settings > Connectors** and connect at least one AI provider (Anthropic, Google, or OpenAI).
4. Go to **Media > Library** and switch to **List View**.
5. Check any single image, choose an action from the Bulk Actions dropdown (**Fill Missing Alt Text**, **Review & Improve Alt Text**, or **Regenerate Alt Text**), and click **Apply**. The plugin automatically processes all matching images — you don't need to select every image.

== Frequently Asked Questions ==

= Do I need an API key? =

Yes. The plugin uses the WordPress 7.0 AI Client, which requires an AI provider plugin and an API key configured in Settings > Connectors.

= Which AI providers are supported? =

Any provider plugin compatible with the WordPress 7.0 AI Client. Officially supported providers include Anthropic (Claude), Google (Gemini), and OpenAI (GPT).

= Will this overwrite my existing alt text? =

The **Regenerate** action replaces existing alt text. The **Review & Improve** action passes your existing alt to the AI for evaluation — it keeps it if accurate and descriptive, replaces it if generic or inaccurate. **Fill Missing Alt Text** only processes images without alt text and never overwrites existing ones.

= Where is the old admin page with the Start button? =

Version 1.0.1 removed the separate admin page in favor of native Media Library bulk actions. Processing now runs from the Media Library list view, matching the existing WordPress workflow.

= How many images can I process at once? =

There is no limit. The plugin processes every matching image in your library. Images are fetched in batches of 5 and processed one at a time with a 300ms delay between calls to avoid overwhelming your AI provider.

= What if an image fails? =

Failed images are logged with an error message in the processing notice and processing continues with the next image.

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

1. Bulk actions in the Media Library list view showing "Fill Missing Alt Text", "Review & Improve Alt Text", and "Regenerate Alt Text".
2. Processing notice with per-image results showing KEPT, REPLACED, and ADDED decisions.
3. Settings > Connectors screen where AI providers are configured.

== Changelog ==

= 1.0.1 =
* Removed standalone admin page and AJAX batch processing system.
* Added "Fill Missing Alt Text" bulk action — processes all images without alt text.
* Added "Review & Improve Alt Text" bulk action — AI evaluates and improves existing alt text.
* Added "Regenerate Alt Text" bulk action — force-generates new alt for every image.
* Bulk actions process all matching images (ignore selection) — just check any one image.
* Compare display in processing notice shows previous alt, new alt, and decision (KEPT/REPLACED/ADDED).
* Images processed sequentially with live progress notice and per-result log.
* Removed hardcoded model preference — now uses the user's configured AI provider.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.1 =
Version 1.0.1 replaces the standalone admin page with three Media Library bulk actions. The old admin page at Media > Auto Alt Text has been removed.
