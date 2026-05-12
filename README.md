# Personal RAG

Personal RAG is a standalone WordPress plugin by Fellyph Cintra, extracted from the blueprint at `/Users/fellyph/Sites/blueprints/blueprints/personal-rag/blueprint.json`.

It adds a private local retrieval-augmented generation tool under **Tools > Personal RAG**. The plugin indexes published and private posts/pages into WordPress database tables, stores browser-generated Ollama embeddings, and asks a local chat model to answer questions from matched site content.

## Requirements

- WordPress 6.5 or later
- PHP 7.4 or later
- Ollama running on a browser-reachable host, usually `http://localhost:11434`
- Local Ollama models:
  - `embeddinggemma`
  - `gemma4:e4b`

If the browser cannot reach Ollama because of CORS, start Ollama with this site origin in `OLLAMA_ORIGINS`.

## Installation

1. Place this directory at `wp-content/plugins/personal-rag`.
2. Activate **Personal RAG** in WordPress.
3. Open **Tools > Personal RAG**.
4. Confirm the Ollama endpoint and model names.
5. Click **Rebuild Embeddings** to create the local index.

## Data

The plugin creates these site-specific database tables:

- `{prefix}_personal_rag_sources`
- `{prefix}_personal_rag_chunks`
- `{prefix}_personal_rag_vectors`

Deleting the plugin through WordPress runs `uninstall.php` and removes these tables plus the stored database version option.

## Development

Runtime code is split into a small bootstrap and testable include classes:

- `personal-rag.php`
- `includes/`
- `assets/personal-rag.js`
- `assets/personal-rag.css`
- `uninstall.php`

Install development dependencies:

```sh
composer install
npm install
```

Useful commands:

```sh
npm run lint:js
npm run lint:php
npm run lint:phpcs
npm run i18n
npm run test:phpunit
npm run test:phpunit:compat
npm run test:e2e
npm test
```

`npm run test:phpunit` runs PHPUnit inside WordPress Playground using the Playground CLI and `tests/blueprints/phpunit.json`. The script uses a `run-blueprint` wrapper because the currently installed `@wp-playground/cli` package does not expose the newer `php` subcommand yet.

`npm run test:e2e` starts WordPress Playground with Playwright, imports `tests/fixtures/playground-docs.wxr.xml`, mocks Ollama in the browser, and covers the critical admin/index/search flows.

Preview the plugin in WordPress Playground with the documentation fixture imported:

```sh
npx @wp-playground/cli server --mount=.:/wordpress/wp-content/plugins/personal-rag --php=8.3 --wp=latest --blueprint=blueprints/playground-docs --blueprint-may-read-adjacent-files
```

Translations are extracted to:

```sh
languages/personal-rag.pot
```
