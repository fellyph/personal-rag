<?php
/**
 * REST API endpoints.
 *
 * @package Personal_RAG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers REST routes and adapts requests to the indexer.
 */
class Personal_RAG_REST {
	/**
	 * Indexing service.
	 *
	 * @var Personal_RAG_Indexer
	 */
	private $indexer;

	/**
	 * Settings service.
	 *
	 * @var Personal_RAG_Settings
	 */
	private $settings;

	/**
	 * AI integration service.
	 *
	 * @var Personal_RAG_AI
	 */
	private $ai;

	/**
	 * Constructor.
	 *
	 * @param Personal_RAG_Indexer  $indexer  Indexing service.
	 * @param Personal_RAG_Settings $settings Settings service.
	 * @param Personal_RAG_AI       $ai       AI integration service.
	 */
	public function __construct( Personal_RAG_Indexer $indexer, Personal_RAG_Settings $settings, Personal_RAG_AI $ai ) {
		$this->indexer  = $indexer;
		$this->settings = $settings;
		$this->ai       = $ai;
	}

	/**
	 * Registers REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			PERSONAL_RAG_REST_NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_status' ),
				'permission_callback' => array( $this, 'permission_read' ),
			)
		);

		register_rest_route(
			PERSONAL_RAG_REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_get_settings' ),
					'permission_callback' => array( $this, 'permission_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_save_settings' ),
					'permission_callback' => array( $this, 'permission_manage' ),
				),
			)
		);

		register_rest_route(
			PERSONAL_RAG_REST_NAMESPACE,
			'/ai/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_ai_status' ),
				'permission_callback' => array( $this, 'permission_read' ),
			)
		);

		register_rest_route(
			PERSONAL_RAG_REST_NAMESPACE,
			'/index/queue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_queue_index' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			)
		);

		register_rest_route(
			PERSONAL_RAG_REST_NAMESPACE,
			'/index/batch',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_index_batch' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			)
		);

		register_rest_route(
			PERSONAL_RAG_REST_NAMESPACE,
			'/index/embeddings',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_save_embeddings' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			)
		);

		register_rest_route(
			PERSONAL_RAG_REST_NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_search' ),
				'permission_callback' => array( $this, 'permission_read' ),
			)
		);

		register_rest_route(
			PERSONAL_RAG_REST_NAMESPACE,
			'/answer',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_answer' ),
				'permission_callback' => array( $this, 'permission_read' ),
			)
		);

		register_rest_route(
			PERSONAL_RAG_REST_NAMESPACE,
			'/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_reset' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			)
		);
	}

	/**
	 * Checks read access.
	 *
	 * @return bool
	 */
	public function permission_read() {
		return current_user_can( 'read' );
	}

	/**
	 * Checks manage access.
	 *
	 * @return bool
	 */
	public function permission_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Returns index status.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_status() {
		return rest_ensure_response( $this->indexer->get_status() );
	}

	/**
	 * Returns Personal RAG settings.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_get_settings() {
		return rest_ensure_response(
			array(
				'settings' => $this->settings->get(),
				'ai'       => $this->ai->get_status(),
			)
		);
	}

	/**
	 * Saves non-secret settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_save_settings( WP_REST_Request $request ) {
		$params   = $this->get_json_params( $request );
		$settings = $this->settings->update( $params );

		return rest_ensure_response(
			array(
				'settings' => $settings,
				'ai'       => $this->ai->get_status(),
			)
		);
	}

	/**
	 * Returns AI Client and connector status.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_ai_status() {
		return rest_ensure_response( $this->ai->get_status() );
	}

	/**
	 * Queues changed or all content.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_queue_index( WP_REST_Request $request ) {
		$params = $this->get_json_params( $request );
		$force  = ! empty( $params['force'] );

		return rest_ensure_response( $this->indexer->queue_all_sources( $force ) );
	}

	/**
	 * Returns an embedding batch.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_index_batch( WP_REST_Request $request ) {
		$limit = $request->get_param( 'limit' ) ? $request->get_param( 'limit' ) : 8;

		return rest_ensure_response( $this->indexer->get_index_batch( $limit ) );
	}

	/**
	 * Saves embeddings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_save_embeddings( WP_REST_Request $request ) {
		$params = $this->get_json_params( $request );
		$items  = isset( $params['items'] ) && is_array( $params['items'] ) ? $params['items'] : array();
		$model  = isset( $params['model'] ) ? $params['model'] : '';
		$result = $this->indexer->save_embeddings( $items, $model );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Searches the index.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_search( WP_REST_Request $request ) {
		$params  = $this->get_json_params( $request );
		$encoded = isset( $params['vector'] ) ? (string) $params['vector'] : '';
		$model   = isset( $params['model'] ) ? $params['model'] : '';
		$top_k   = isset( $params['topK'] ) ? $params['topK'] : 5;
		$result  = $this->indexer->search( $encoded, $model, $top_k );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Searches local sources and generates an answer through the AI Client.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_answer( WP_REST_Request $request ) {
		$params   = $this->get_json_params( $request );
		$settings = $this->settings->get();
		$question = isset( $params['question'] ) ? trim( wp_strip_all_tags( (string) $params['question'] ) ) : '';
		$encoded  = isset( $params['vector'] ) ? (string) $params['vector'] : '';
		$top_k    = isset( $params['topK'] ) ? $params['topK'] : $settings['topK'];

		if ( '' === $question ) {
			return new WP_Error(
				'personal_rag_empty_question',
				__( 'Question is required.', 'personal-rag' ),
				array( 'status' => 400 )
			);
		}

		$search = $this->indexer->search( $encoded, $settings['embeddingModel'], $top_k );
		if ( is_wp_error( $search ) ) {
			return $search;
		}

		$result = $this->ai->generate_answer( $question, $search['matches'], $settings );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'answer'  => $result['answer'],
				'sources' => $result['sources'],
				'total'   => $search['total'],
				'ai'      => isset( $result['status'] ) ? $result['status'] : $this->ai->get_status(),
			)
		);
	}

	/**
	 * Resets the index.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_reset() {
		$this->indexer->reset_index();
		return rest_ensure_response( $this->indexer->get_status() );
	}

	/**
	 * Safely returns JSON request params.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private function get_json_params( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		return is_array( $params ) ? $params : array();
	}
}
