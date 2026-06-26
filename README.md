# Auto Alt Text Generator (v1.2.1)

Uses the **WordPress 7.0 AI Client** to automatically generate, review, and improve alt text for every image in your media library.

## Features

- **One-click quick-action bar** — Three buttons at the top of the Media Library. No image selection needed.
- **Fill Missing Alt Text** — Generates alt text for all images without any.
- **Review & Improve** — Two-phase processing: vision model generates alt, then text-only AI compares old vs new and picks/combines the best version.
- **Regenerate All** — Force-replace alt text for every image.
- **2-phase approach** — Vision call generates from image, then a text-only AI call evaluates quality and picks the best version.
- **W3C Alt Decision Tree** — Default prompt follows W3C guidelines: decorative → functional → informative.
- **Custom system prompt** — Edit the prompt in settings to add few-shot examples for your specific AI model.
- **Auto-generate on upload** — Optional setting to process new images automatically.
- **Quality stats** — Notice bar shows counts of missing, too-long, and too-short alt text.
- **Stop anytime** — Stop link or dismiss the notice to cancel mid-processing.
- **Compare display** — Each result shows previous vs new alt and the decision (KEPT/REPLACED/ADDED).
- **Adjustable batch size** — Configure how many IDs are fetched per request (1–50).
- **Sequential processing** — Images processed one at a time with 300ms delay; no server overload.

## Requirements

- WordPress 7.0+
- At least one AI provider connected under **Settings > Connectors** (Anthropic, Google, or OpenAI provider plugin)
- PHP 8.0+

## Installation

1. Upload to `/wp-content/plugins/` or install via Plugins > Add New
2. Activate the plugin
3. Go to **Settings > Connectors** and connect an AI provider
4. Go to **Media > Library** and use the quick-action buttons, or configure settings under **Media > Auto Alt Text**

## Design Notes

This plugin is tuned for **small local AI models** (Ollama + moondream, Llava, etc.) where per-call cost is zero. If using a paid provider, increase batch size and customize prompts — each image generates one vision call plus one text-only comparison call in Review mode.

## License

GPL v2 or later
