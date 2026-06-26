=== Auto Alt Text Generator ===
Contributors: masterset2005
Tags: alt text, accessibility, images, media library, AI
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fill missing, review and improve, or regenerate alt text for your entire media library using the WordPress 7.0 AI Client — with one-click quick-action buttons on the Media Library page.

== Description ==

Auto Alt Text Generator adds a **quick-action notice bar** at the top of your Media Library page with one-click buttons to **Fill Missing Alt Text**, **Review & Improve All**, and **Regenerate All**. No image selection needed — just pick your action and go. A settings page under **Settings > Auto Alt Text** lets you configure batch size, customize the AI system prompt, and enable auto-generation on upload. Run processing in the **background via WP-Cron** or from the **command line with WP-CLI** — no need to keep your browser open for large libraries.

= Features =

* **One-click quick-action bar** — Three buttons at the top of the Media Library page. No selection required.
* **Fill Missing Alt Text** — Scans your entire library for images without alt text and generates descriptive alt text for each one.
* **Review & Improve Alt Text** — Two-phase AI processing: a vision model generates fresh alt text, then a text-only comparison call evaluates old vs new and picks or combines the best version.
* **Regenerate Alt Text** — Force-generate new alt text for every image, replacing whatever is there.
* **Compare display** — Each result shows the previous alt text, the new alt text, and the decision (KEPT, REPLACED, ADDED) in a compact inline log with color coding.
* **Stop anytime** — Click the stop link in the progress notice or dismiss the notice to cancel mid-processing.
* **Alt text quality stats** — The notice bar shows counts of missing, too-long (>125 chars), and too-short (<5 chars) alt text.
* **W3C Alt Decision Tree prompt** — Default system prompt follows the W3C framework: decorative → functional → informative. Returns empty alt for decorative images.
* **Customizable system prompts** — Separate editable prompts for the vision generation step and the text-only comparison step. Tune both for your specific model.
* **Auto-generate on upload** — Optional setting to generate alt text automatically when new images are uploaded.
* **Background processing** — Start a job and close the browser. WP-Cron processes your library in batches. Track progress on the admin page.
* **WP-CLI support** — Run `wp auto-alt process --mode=missing` from the terminal for the fastest possible processing. Includes a progress bar and batch memory management.
* **Settings page** — Configure batch size, system prompt, and auto-generation under Settings > Auto Alt Text.
* **Sequential processing** — Images are processed one by one with a visible progress notice and per-image results.
* **Uses WP 7.0 AI Client** — No third-party API keys required beyond what you configure in Settings > Connectors.

= Requirements =

* WordPress 7.0 or later
* At least one AI provider connected under Settings > Connectors (AI Provider for Anthropic, Google, or OpenAI)
* PHP 8.0 or later

== Installation ==

1. Upload the `wp-auto-alt-text` folder to `/wp-content/plugins/`, or install via Plugins > Add New.
2. Activate the plugin through the Plugins screen.
3. Go to **Settings > Connectors** and connect at least one AI provider (Anthropic, Google, or OpenAI).
4. Go to **Media > Library** — the quick-action notice bar appears at the top with one-click buttons.
5. Click **Fill Missing Alt Text**, **Review & Improve All**, or **Regenerate All** to start processing.

== Frequently Asked Questions ==

= Do I need an API key? =

Yes. The plugin uses the WordPress 7.0 AI Client, which requires an AI provider plugin and an API key configured in Settings > Connectors.

= Which AI providers are supported? =

Any provider plugin compatible with the WordPress 7.0 AI Client. Officially supported providers include Anthropic (Claude), Google (Gemini), and OpenAI (GPT).

= Will this overwrite my existing alt text? =

The **Regenerate** action replaces existing alt text. The **Review & Improve** action generates fresh alt text and runs a text-only comparison — it keeps your existing alt if it's better, replaces it if the new version is better, or combines the best parts of both. **Fill Missing Alt Text** only processes images without alt text and never overwrites existing ones.

= How do I stop processing mid-way? =

Click the **stop** link in the progress notice text, or dismiss the notice (X button). Already-processed images are saved; remaining images are skipped.

= What's with the two-phase processing in Review mode? =

The vision model generates raw alt text from the image. Then a text-only AI call compares the old alt with the new one and decides what to keep — the old, the new, or a combination of both. This produces better results than a single pass.

= Can I customize the AI prompt? =

Yes. Go to **Settings > Auto Alt Text** and edit the System Prompt textarea. The default follows the W3C Alt Decision Tree (decorative → functional → informative). You can add few-shot examples tuned for your specific model.

= Can I auto-generate alt text on upload? =

Yes. Enable **Auto-generate on upload** in Settings > Auto Alt Text. New images will be processed with the AI model during upload.

= How many images can I process at once? =

There is no limit. The plugin processes every matching image in your library. Images are fetched in configurable batches (default 5) and processed one at a time.

= Can I close the browser while processing? =

Yes. Use the **Process in Background** buttons on the processing page — the job runs via WP-Cron and progress is displayed on the admin page. Or use WP-CLI: `wp auto-alt process --mode=missing` from the terminal.

= What if an image fails? =

Failed images are logged with an error message and processing continues with the next image. For persistent failures, try running via WP-CLI to see detailed error messages.

= Which AI models work best? =

The plugin works with any provider connected via Settings > Connectors. The default prompts are tuned for **small local models** (e.g., Ollama with moondream, Llava, Qwen2-VL). If you use a **paid provider** (Anthropic, OpenAI, Google), consider increasing the batch size and customizing both the System Prompt and Comparison Prompt to match the model's strengths.

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

1. Quick-action notice bar at the top of the Media Library page with Fill Missing, Review & Improve, and Regenerate buttons and quality stats.
2. Processing notice with per-image results showing KEPT, REPLACED, and ADDED decisions and a stop link.
3. Settings page under Settings > Auto Alt Text with batch size, system prompt, and auto-generate options.
4. Settings > Connectors screen where AI providers are configured.

== Changelog ==

= 1.2.0 =
* Added WP-CLI command (`wp auto-alt process --mode=missing|review|regenerate`) with progress bar and batch cache flushing.
* Added background processing via WP-Cron: "Process in Background" buttons on the processing page, scheduled batch events, and a progress bar with cancel support.
* Added separate Comparison Prompt setting alongside the System Prompt — both independently editable with collapsible default reference.
* Settings page moved from **Media** to **Settings** menu.
* Files renamed to WordPress Coding Standards naming convention (`class-autoalt-*.php`).
* Full PHPStan (level 9) and PHPCS compliance — 0 errors.
* Minor internal optimizations and documentation cleanup.

= 1.1.0 =
* Added quick-action notice bar on Media Library page with one-click buttons (no image selection needed).
* Added settings page under Media > Auto Alt Text with batch size, system prompt, and auto-generate options.
* Added alt text quality stats in notice bar (missing / too long / too short counts).
* Added two-phase Review mode: vision generates alt, then text-only AI compares old vs new and picks the best.
* Added W3C Alt Decision Tree as default system prompt (decorative → functional → informative).
* Added customizable system prompt textarea with collapsible default reference.
* Added auto-generate alt text on upload option.
* Added stop link and dismiss handling in processing JS.
* Removed bulk action dropdown items (replaced by quick-action buttons).
* Removed hardcoded model preference — uses user's configured AI provider.

= 1.0.1 =
* Removed standalone admin page and AJAX batch processing system.
* Added "Fill Missing Alt Text" bulk action.
* Added "Review & Improve Alt Text" bulk action.
* Added "Regenerate Alt Text" bulk action.
* Compare display in processing notice.
* Images processed sequentially with live progress.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.0 =
Version 1.2.0 adds WP-CLI support, background WP-Cron processing, and a separate Comparison Prompt setting. The settings page has moved from Media to its own page under **Settings > Auto Alt Text**. Internal file names have changed — no action needed on your part.

= 1.1.0 =
Version 1.1.0 adds a one-click quick-action bar, settings page (batch size, system prompt, auto-generate), two-phase Review mode with text-only comparison, W3C Alt Decision Tree prompt, and quality stats. The bulk action dropdown has been removed — use the quick-action buttons instead.
