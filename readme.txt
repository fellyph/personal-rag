=== Personal RAG ===
Contributors: fellyph
Tags: rag, ai, ollama, search
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private local RAG over WordPress posts and pages using Ollama and WordPress database-backed storage.

== Description ==

Personal RAG adds a local retrieval-augmented generation tool under Tools > Personal RAG. It indexes published and private posts/pages, stores chunk vectors in WordPress database tables, and asks a local Ollama chat model to answer questions from matched site content.

The default local models are `embeddinggemma` for embeddings and `gemma4:e4b` for chat.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/personal-rag`.
2. Activate Personal RAG through the Plugins screen.
3. Go to Tools > Personal RAG.
4. Confirm the Ollama endpoint and model names.
5. Rebuild embeddings to create the local index.

== Frequently Asked Questions ==

= Does this send content to a hosted AI service? =

No. The browser calls the configured Ollama endpoint directly. Keep that endpoint local if you want all model traffic to stay on your machine.

= What data is removed on uninstall? =

The plugin drops its source, chunk, and vector tables, and deletes its database version option.

== Changelog ==

= 0.1.0 =

Initial standalone extraction from the Personal RAG blueprint.
Adds WordPress Playground PHPUnit, Playwright E2E coverage, and translation scaffolding.
