=== Personal RAG ===
Contributors: fellyph
Tags: rag, ai, ollama, search
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private local RAG over WordPress posts and pages using Ollama embeddings and WordPress AI Connectors.

== Description ==

Personal RAG adds a local retrieval-augmented generation tool under Tools > Personal RAG. It indexes published and private posts/pages, stores chunk vectors in WordPress database tables, ranks local sources, and generates answers through the WordPress AI Client.

The default local embedding model is `embeddinggemma`. Answer generation uses configured WordPress AI Connectors, with `gemma4:e4b` as the preferred chat model when available.

Personal RAG stores only non-secret preferences. API keys and provider credentials should be managed through Settings > Connectors. The AI Provider for Ollama plugin is supported as an optional local provider for answer generation.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/personal-rag`.
2. Activate Personal RAG through the Plugins screen.
3. Install and activate AI Provider for Ollama or another WordPress AI Client text-generation provider.
4. Configure provider credentials in Settings > Connectors.
5. Go to Tools > Personal RAG.
6. Confirm the Ollama embedding endpoint and model names.
7. Rebuild embeddings to create the local index.

== Frequently Asked Questions ==

= Does this send content to a hosted AI service? =

Embedding requests go directly from the browser to the configured Ollama endpoint. Answer generation is handled by whichever WordPress AI Connector the site administrator has configured. Use a local connector such as AI Provider for Ollama if you want answer generation to stay on your own machine.

= Do I need the AI Provider for Ollama plugin? =

No. Personal RAG can use any configured WordPress AI Client text-generation provider. AI Provider for Ollama is the recommended local provider because it integrates Ollama with WordPress 7.0 Connectors.

= What data is removed on uninstall? =

The plugin drops its source, chunk, and vector tables, and deletes its stored options.

== Changelog ==

= 0.2.0 =

Requires WordPress 7.0 or later.
Adds WordPress AI Client answer generation, connector status, settings endpoints, and a React admin interface built with WordPress components.

= 0.1.0 =

Initial standalone extraction from the Personal RAG blueprint.
Adds WordPress Playground PHPUnit, Playwright E2E coverage, and translation scaffolding.
