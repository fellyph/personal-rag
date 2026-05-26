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
class Personal_RAG_REST_Test extends Personal_RAG_Test_Case {
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

		$settings_request  = new WP_REST_Request( 'GET', '/personal-rag/v1/settings' );
		$settings_response = rest_do_request( $settings_request );
		$this->assertSame( 200, $settings_response->get_status() );

		$save_settings_response = rest_do_request(
			$this->json_request(
				'POST',
				'/personal-rag/v1/settings',
				array(
					'endpoint' => 'https://example.com',
				)
			)
		);
		$this->assertSame( 403, $save_settings_response->get_status() );

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
	 * Settings endpoint stores only sanitized non-secret preferences.
	 */
	public function test_settings_endpoint_sanitizes_and_saves_preferences() {
		$admin_id = $this->create_user( 'administrator' );
		wp_set_current_user( $admin_id );

		$response = rest_do_request(
			$this->json_request(
				'POST',
				'/personal-rag/v1/settings',
				array(
					'endpoint'       => 'javascript:alert(1)',
					'embeddingModel' => '<b>custom-embed</b>',
					'chatModel'      => '',
					'topK'           => 99,
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$settings = $response->get_data()['settings'];
		$this->assertSame( 'http://localhost:11434', $settings['endpoint'] );
		$this->assertSame( 'custom-embed', $settings['embeddingModel'] );
		$this->assertSame( 'gemma4:e4b', $settings['chatModel'] );
		$this->assertSame( 12, $settings['topK'] );
	}

	/**
	 * AI status reports filtered connector state for the admin UI.
	 */
	public function test_ai_status_endpoint_reports_connector_state() {
		$subscriber_id = $this->create_user( 'subscriber' );
		wp_set_current_user( $subscriber_id );

		add_filter(
			'personal_rag_ai_status',
			function ( $status ) {
				$status['aiClientAvailable']       = true;
				$status['aiSupported']             = true;
				$status['connectorsAvailable']     = true;
				$status['textGenerationSupported'] = true;
				$status['ollamaProvider']['active'] = true;
				$status['ollamaProvider']['connectorRegistered'] = true;

				return $status;
			}
		);

		$response = rest_do_request( new WP_REST_Request( 'GET', '/personal-rag/v1/ai/status' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['aiClientAvailable'] );
		$this->assertTrue( $data['textGenerationSupported'] );
		$this->assertTrue( $data['ollamaProvider']['active'] );
	}

	/**
	 * Answer endpoint searches sources and returns the mocked server-side answer.
	 */
	public function test_answer_endpoint_ranks_sources_and_returns_answer() {
		$subscriber_id = $this->create_user( 'subscriber' );
		wp_set_current_user( $subscriber_id );

		$this->embed_ranked_sources();

		add_filter(
			'personal_rag_pre_generate_answer',
			function ( $pre, $question, $matches ) {
				return array(
					'answer'  => 'Blueprints use JSON configuration to set up WordPress Playground instances [1].',
					'sources' => $matches,
					'status'  => array(
						'aiClientAvailable'       => true,
						'aiSupported'             => true,
						'connectorsAvailable'     => true,
						'textGenerationSupported' => true,
					),
				);
			},
			10,
			3
		);

		$response = rest_do_request(
			$this->json_request(
				'POST',
				'/personal-rag/v1/answer',
				array(
					'question' => 'How do I use Playground blueprints?',
					'vector'   => $this->vector_payload( array( 1.0, 0.0 ) ),
					'topK'     => 2,
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertStringContainsString( 'Blueprints use JSON configuration', $data['answer'] );
		$this->assertSame( 'Blueprint Source', $data['sources'][0]['title'] );
		$this->assertCount( 2, $data['sources'] );
	}

	/**
	 * Answer endpoint returns useful error shapes for unavailable AI states.
	 */
	public function test_answer_endpoint_returns_ai_availability_errors() {
		$subscriber_id = $this->create_user( 'subscriber' );
		wp_set_current_user( $subscriber_id );

		$this->embed_ranked_sources();

		$states = array(
			array(
				'status' => array(
					'aiClientAvailable'       => false,
					'aiSupported'             => true,
					'textGenerationSupported' => true,
				),
				'code'   => 'personal_rag_ai_client_missing',
				'http'   => 501,
			),
			array(
				'status' => array(
					'aiClientAvailable'       => true,
					'aiSupported'             => false,
					'textGenerationSupported' => true,
				),
				'code'   => 'personal_rag_ai_disabled',
				'http'   => 403,
			),
			array(
				'status' => array(
					'aiClientAvailable'       => true,
					'aiSupported'             => true,
					'textGenerationSupported' => false,
				),
				'code'   => 'personal_rag_text_generation_unavailable',
				'http'   => 424,
			),
		);

		foreach ( $states as $state ) {
			remove_all_filters( 'personal_rag_ai_status' );
			add_filter(
				'personal_rag_ai_status',
				function ( $status ) use ( $state ) {
					return array_merge( $status, $state['status'] );
				}
			);

			$response = rest_do_request(
				$this->json_request(
					'POST',
					'/personal-rag/v1/answer',
					array(
						'question' => 'How do I use Playground blueprints?',
						'vector'   => $this->vector_payload( array( 1.0, 0.0 ) ),
						'topK'     => 1,
					)
				)
			);

			$this->assertSame( $state['http'], $response->get_status() );
			$this->assertSame( $state['code'], $response->as_error()->get_error_code() );
		}
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

	/**
	 * Adds two embedded sources with known vectors.
	 *
	 * @return void
	 */
	private function embed_ranked_sources() {
		$this->create_post(
			array(
				'post_title'   => 'Blueprint Source',
				'post_content' => 'Blueprints are JSON files for WordPress Playground.',
			)
		);
		$this->create_post(
			array(
				'post_title'   => 'Xdebug Source',
				'post_content' => 'Xdebug helps inspect PHP execution.',
			)
		);

		$this->indexer->queue_all_sources( true );
		$batch = $this->indexer->get_index_batch( 10 );
		$items = array();
		foreach ( $batch['items'] as $chunk ) {
			$is_blueprint = false !== strpos( $chunk['title'], 'Blueprint' );
			$items[]      = array(
				'chunkId'    => $chunk['id'],
				'vector'     => $this->vector_payload( $is_blueprint ? array( 1.0, 0.0 ) : array( 0.0, 1.0 ) ),
				'dimensions' => 2,
			);
		}

		$saved = $this->indexer->save_embeddings( $items, 'embeddinggemma' );
		$this->assertNotWPError( $saved );
	}
}
