# Auto Alt Text Generator

Uses the **WordPress 7.0 AI Client** to automatically generate and improve alt text for every image in your media library.

## Features

- **Bulk AI generation** — Process hundreds of images with real-time progress
- **Three modes** — Missing alt text only, missing/empty, or regenerate all
- **Pause / Resume / Cancel** — Full control over long-running jobs
- **Per-image logging** — See exactly what was generated for each image
- **Smart prompts** — AI follows accessibility best practices (no "Image of", <125 chars, decorative detection)
- **Adjustable batch size** — Tune 1–20 images per request for your server

## Requirements

- WordPress 7.0+
- At least one AI provider connected under **Settings > Connectors** (Anthropic, Google, or OpenAI provider plugin)
- PHP 8.0+

## Installation

1. Upload to `/wp-content/plugins/` or install via Plugins > Add New
2. Activate the plugin
3. Go to **Settings > Connectors** and connect an AI provider
4. Go to **Media > Auto Alt Text**, select your mode, and click **Start Processing**

## License

GPL v2 or later
