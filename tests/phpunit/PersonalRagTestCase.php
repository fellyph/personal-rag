<?php
/**
 * Shared PHPUnit helpers.
 *
 * @package Personal_RAG
 */

use PHPUnit\Framework\TestCase;

/**
 * Base test case for WordPress-backed tests.
 */
abstract class Personal_RAG_Test_Case extends TestCase {
	/**
	 * Indexing service.
	 *
	 * @var Personal_RAG_Indexer
	 */
	protected $indexer;

	/**
	 * Settings service.
	 *
	 * @var Personal_RAG_Settings
	 */
	protected $settings;

	/**
	 * AI integration service.
	 *
	 * @var Personal_RAG_AI
	 */
	protected $ai;

	/**
	 * User IDs created by the test.
	 *
	 * @var array<int,int>
	 */
	private $created_users = array();

	/**
	 * Prepares a clean WordPress content/index state.
	 */
	protected function setUp(): void {
		parent::setUp();

		Personal_RAG_Schema::install_schema();
		$this->indexer = Personal_RAG_Plugin::instance()->indexer();
		$this->settings = Personal_RAG_Plugin::instance()->settings();
		$this->ai       = Personal_RAG_Plugin::instance()->ai();
		delete_option( Personal_RAG_Settings::OPTION_NAME );
		$this->delete_all_posts_and_pages();
		$this->indexer->reset_index();
		wp_set_current_user( 0 );
	}

	/**
	 * Cleans up test data.
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		$this->indexer->reset_index();
		delete_option( Personal_RAG_Settings::OPTION_NAME );
		remove_all_filters( 'personal_rag_ai_status' );
		remove_all_filters( 'personal_rag_pre_generate_answer' );
		$this->delete_all_posts_and_pages();

		foreach ( $this->created_users as $user_id ) {
			wp_delete_user( $user_id );
		}

		$this->created_users = array();

		parent::tearDown();
	}

	/**
	 * Creates a post or page.
	 *
	 * @param array<string,mixed> $args Post args.
	 * @return int
	 */
	protected function create_post( $args = array() ) {
		$defaults = array(
			'post_title'   => 'Personal RAG Test Post',
			'post_content' => '<!-- wp:paragraph --><p>WordPress Playground content for local retrieval.</p><!-- /wp:paragraph -->',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		);

		$post_id = wp_insert_post( array_merge( $defaults, $args ), true );
		$this->assertNotWPError( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		return (int) $post_id;
	}

	/**
	 * Creates a user.
	 *
	 * @param string $role User role.
	 * @return int
	 */
	protected function create_user( $role ) {
		$username = 'personal_rag_' . $role . '_' . wp_generate_password( 8, false, false );
		$user_id  = wp_create_user( $username, 'password', $username . '@example.com' );
		$this->assertNotWPError( $user_id );

		$user = new WP_User( $user_id );
		$user->set_role( $role );

		$this->created_users[] = (int) $user_id;
		return (int) $user_id;
	}

	/**
	 * Encodes float values as the plugin's base64 vector payload.
	 *
	 * @param array<int,float> $values Float values.
	 * @return string
	 */
	protected function vector_payload( $values ) {
		$binary = '';
		foreach ( $values as $value ) {
			$binary .= pack( 'f', (float) $value );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $binary );
	}

	/**
	 * Creates a JSON REST request for direct endpoint tests.
	 *
	 * @param string              $method Route method.
	 * @param string              $route  REST route.
	 * @param array<string,mixed> $params JSON body params.
	 * @return WP_REST_Request
	 */
	protected function json_request( $method, $route, $params = array() ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );

		return $request;
	}

	/**
	 * Deletes default and test posts/pages.
	 *
	 * @return void
	 */
	private function delete_all_posts_and_pages() {
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $posts as $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}

	/**
	 * Asserts a value is not a WP_Error.
	 *
	 * @param mixed $actual Actual value.
	 * @return void
	 */
	protected function assertNotWPError( $actual ) {
		$this->assertFalse(
			is_wp_error( $actual ),
			is_wp_error( $actual ) ? $actual->get_error_message() : ''
		);
	}
}
