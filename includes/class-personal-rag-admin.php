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

		$asset_file = PERSONAL_RAG_DIR . 'build/index.asset.php';
		$asset      = file_exists( $asset_file )
			? include $asset_file
			: array(
				'dependencies' => array( 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n' ),
				'version'      => PERSONAL_RAG_VERSION,
			);

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style(
			'personal-rag-admin',
			PERSONAL_RAG_URL . 'assets/personal-rag.css',
			array( 'wp-components' ),
			$asset['version']
		);

		wp_enqueue_script(
			'personal-rag-admin',
			PERSONAL_RAG_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'personal-rag-admin', 'personal-rag', PERSONAL_RAG_DIR . 'languages' );
		}

		wp_add_inline_script(
			'personal-rag-admin',
			'window.personalRagSettings = ' . wp_json_encode(
				array(
					'apiRoot'          => esc_url_raw( rest_url() ),
					'restNamespace'    => PERSONAL_RAG_REST_NAMESPACE,
					'nonce'            => wp_create_nonce( 'wp_rest' ),
					'canManageOptions' => current_user_can( 'manage_options' ),
				)
			) . ';',
			'before'
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
