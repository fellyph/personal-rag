<?php
/**
 * Admin UI.
 *
 * @package Personal_RAG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the admin screen.
 */
class Personal_RAG_Admin {
	/**
	 * Adds the Tools submenu.
	 *
	 * @return void
	 */
	public function add_admin_page() {
		add_management_page(
			__( 'Personal RAG', 'personal-rag' ),
			__( 'Personal RAG', 'personal-rag' ),
			'read',
			'personal-rag',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueues admin assets on the plugin screen.
	 *
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'tools_page_personal-rag' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'personal-rag-admin',
			PERSONAL_RAG_URL . 'assets/personal-rag.css',
			array( 'dashicons' ),
			PERSONAL_RAG_VERSION
		);

		wp_enqueue_script(
			'personal-rag-admin',
			PERSONAL_RAG_URL . 'assets/personal-rag.js',
			array( 'wp-i18n' ),
			PERSONAL_RAG_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'personal-rag-admin', 'personal-rag', PERSONAL_RAG_DIR . 'languages' );
		}

		wp_localize_script(
			'personal-rag-admin',
			'personalRagSettings',
			array(
				'restUrl'          => esc_url_raw( rest_url( PERSONAL_RAG_REST_NAMESPACE ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'canManageOptions' => current_user_can( 'manage_options' ),
				'origin'           => esc_url_raw( home_url( '/' ) ),
				'defaults'         => array(
					'endpoint'       => 'http://localhost:11434',
					'embeddingModel' => 'embeddinggemma',
					'chatModel'      => 'gemma4:e4b',
					'topK'           => 5,
				),
			)
		);
	}

	/**
	 * Renders the app mount point.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		?>
		<div class="wrap personal-rag-wrap">
			<div id="personal-rag-app">
				<h1><?php esc_html_e( 'Personal RAG', 'personal-rag' ); ?></h1>
				<p><?php esc_html_e( 'Loading local search assistant...', 'personal-rag' ); ?></p>
			</div>
		</div>
		<?php
	}
}
