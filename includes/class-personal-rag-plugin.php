<?php
/**
 * Main plugin loader.
 *
 * @package Personal_RAG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates plugin services and WordPress hooks.
 */
class Personal_RAG_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Personal_RAG_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Indexing service.
	 *
	 * @var Personal_RAG_Indexer
	 */
	private $indexer;

	/**
	 * Admin UI service.
	 *
	 * @var Personal_RAG_Admin
	 */
	private $admin;

	/**
	 * REST service.
	 *
	 * @var Personal_RAG_REST
	 */
	private $rest;

	/**
	 * Abilities service.
	 *
	 * @var Personal_RAG_Abilities
	 */
	private $abilities;

	/**
	 * Returns the singleton.
	 *
	 * @return Personal_RAG_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$vectors         = new Personal_RAG_Vectors();
		$this->indexer   = new Personal_RAG_Indexer( $vectors );
		$this->admin     = new Personal_RAG_Admin();
		$this->rest      = new Personal_RAG_REST( $this->indexer );
		$this->abilities = new Personal_RAG_Abilities( $this->indexer );

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this->abilities, 'maybe_register' ) );
		add_action( 'init', array( 'Personal_RAG_Schema', 'maybe_install_schema' ) );
		add_action( 'admin_menu', array( $this->admin, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_admin_assets' ) );
		add_action( 'rest_api_init', array( $this->rest, 'register_routes' ) );
		add_action( 'save_post', array( $this->indexer, 'handle_save_post' ), 10, 3 );
		add_action( 'before_delete_post', array( $this->indexer, 'handle_delete_post' ) );
		add_action( 'trashed_post', array( $this->indexer, 'handle_delete_post' ) );
		add_action( 'untrashed_post', array( $this->indexer, 'handle_untrashed_post' ) );
	}

	/**
	 * Loads translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'personal-rag', false, dirname( plugin_basename( PERSONAL_RAG_FILE ) ) . '/languages' );
	}

	/**
	 * Exposes the indexer for tests and integrations.
	 *
	 * @return Personal_RAG_Indexer
	 */
	public function indexer() {
		return $this->indexer;
	}
}
