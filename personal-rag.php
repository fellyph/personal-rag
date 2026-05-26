<?php
/*
Plugin Name: Personal RAG
Description: Private local RAG over WordPress posts and pages using Ollama, EmbeddingGemma, Gemma 4, and SQLite-backed storage.
Version: 0.1.0
Requires at least: 6.5
Requires PHP: 7.4
Author: Fellyph Cintra
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: personal-rag
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PERSONAL_RAG_VERSION', '0.1.0' );
define( 'PERSONAL_RAG_REST_NAMESPACE', 'personal-rag/v1' );
define( 'PERSONAL_RAG_FILE', __FILE__ );
define( 'PERSONAL_RAG_DIR', plugin_dir_path( __FILE__ ) );
define( 'PERSONAL_RAG_URL', plugin_dir_url( __FILE__ ) );

require_once PERSONAL_RAG_DIR . 'includes/class-personal-rag-schema.php';
require_once PERSONAL_RAG_DIR . 'includes/class-personal-rag-vectors.php';
require_once PERSONAL_RAG_DIR . 'includes/class-personal-rag-indexer.php';
require_once PERSONAL_RAG_DIR . 'includes/class-personal-rag-admin.php';
require_once PERSONAL_RAG_DIR . 'includes/class-personal-rag-rest.php';
require_once PERSONAL_RAG_DIR . 'includes/class-personal-rag-abilities.php';
require_once PERSONAL_RAG_DIR . 'includes/class-personal-rag-plugin.php';

register_activation_hook( __FILE__, array( 'Personal_RAG_Schema', 'install_schema' ) );

Personal_RAG_Plugin::instance();
