# Personal RAG

Personal RAG is a standalone WordPress plugin by Fellyph Cintra, extracted from the blueprint at `/Users/fellyph/Sites/blueprints/blueprints/personal-rag/blueprint.json`.

It adds a private retrieval-augmented generation tool under **Tools > Personal RAG**. The plugin indexes published and private posts/pages into WordPress database tables, stores browser-generated Ollama embeddings, ranks local sources, and asks the WordPress AI Client to generate answers through configured AI Connectors.

## Requirements

- WordPress 7.0 or later
- PHP 7.4 or later
- Ollama running on a browser-reachable host, usually `http://localhost:11434`
- Local Ollama embedding model: `embeddinggemma`
- A text-generation connector configured in **Settings > Connectors**
- Optional local answer provider: [AI Provider for Ollama](https://wordpress.org/plugins/ai-provider-for-ollama/)

If the browser cannot reach Ollama because of CORS, start Ollama with this site origin in `OLLAMA_ORIGINS`.

## Installation

1. Place this directory at `wp-content/plugins/personal-rag`.
2. Activate **Personal RAG** in WordPress.
3. Install and activate **AI Provider for Ollama** or another WordPress AI Client text-generation provider.
4. Configure credentials in **Settings > Connectors** and, for Ollama, the host URL in **Settings > Ollama**.
5. Open **Tools > Personal RAG**.
6. Confirm the embedding endpoint/model and preferred chat model.
7. Click **Rebuild Embeddings** to create the local index.

## Data

The plugin stores non-secret preferences in the `personal_rag_settings` option and creates these site-specific database tables:

- `{prefix}_personal_rag_sources`
- `{prefix}_personal_rag_chunks`
- `{prefix}_personal_rag_vectors`

Deleting the plugin through WordPress runs `uninstall.php` and removes these tables plus Personal RAG options.

## Development

Runtime code is split into a small bootstrap, testable include classes, and a built React admin app:

- `personal-rag.php`
- `includes/`
- `src/admin/`
- `build/`
- `assets/personal-rag.css`
- `uninstall.php`

Install development dependencies:

```sh
composer install
npm install
```

Useful commands:

```sh
npm run build
npm run lint:js
npm run lint:php
npm run lint:phpcs
npm run i18n
npm run test:phpunit
npm run test:e2e
npm test
```

`npm run test:phpunit` runs PHPUnit inside WordPress Playground using the Playground CLI and `tests/blueprints/phpunit.json`. The script uses a `run-blueprint` wrapper because the currently installed `@wp-playground/cli` package does not expose the newer `php` subcommand yet.

`npm run test:e2e` starts WordPress Playground with Playwright, installs the Ollama provider plugin, imports `tests/fixtures/playground-docs.wxr.xml`, mocks browser-side Ollama embeddings, and uses a test mu-plugin to mock server-side AI answers deterministically.

Preview the plugin in WordPress Playground with the documentation fixture imported:

```sh
npx @wp-playground/cli server --mount=.:/wordpress/wp-content/plugins/personal-rag --php=8.3 --wp=latest --blueprint=blueprints/playground-docs --blueprint-may-read-adjacent-files --login
```

Translations are extracted to:

```sh
languages/personal-rag.pot
```
