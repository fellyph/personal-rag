<?php
/**
 * REST API tests.
 *
 * @package Personal_RAG
 */

require_once __DIR__ . '/PersonalRagTestCase.php';

/**
 * Tests REST route permissions and behavior.
 */
class RestTest extends Personal_RAG_Test_Case {
	/**
	 * Registers REST routes once for direct REST requests.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Read endpoints are available to readers, manage endpoints require admins.
	 */
	public function test_rest_permissions_for_reader_and_manager() {
		$subscriber_id = $this->create_user( 'subscriber' );
		wp_set_current_user( $subscriber_id );

		$status_request  = new WP_REST_Request( 'GET', '/personal-rag/v1/status' );
		$status_response = rest_do_request( $status_request );
		$this->assertSame( 200, $status_response->get_status() );

		$queue_request  = new WP_REST_Request( 'POST', '/personal-rag/v1/index/queue' );
		$queue_response = rest_do_request( $queue_request );
		$this->assertSame( 403, $queue_response->get_status() );

		$admin_id = $this->create_user( 'administrator' );
		wp_set_current_user( $admin_id );

		$queue_response = rest_do_request( $queue_request );
		$this->assertSame( 200, $queue_response->get_status() );
		$this->assertArrayHasKey( 'status', $queue_response->get_data() );
	}

	/**
	 * Reset endpoint clears existing index data for admins.
	 */
	public function test_reset_endpoint_clears_index() {
		$admin_id = $this->create_user( 'administrator' );
		wp_set_current_user( $admin_id );

		$post_id = $this->create_post();
		$this->indexer->queue_post( $post_id, true );
		$this->assertSame( 1, $this->indexer->get_status()['sources'] );

		$response = rest_do_request( new WP_REST_Request( 'POST', '/personal-rag/v1/reset' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['sources'] );
		$this->assertSame( 0, $response->get_data()['chunks'] );
	}
}
