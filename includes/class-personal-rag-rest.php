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
	 * Constructor.
	 *
	 * @param Personal_RAG_Indexer $indexer Indexing service.
	 */
	public function __construct( Personal_RAG_Indexer $indexer ) {
		$this->indexer = $indexer;
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
	 * Queues changed or all content.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_queue_index( WP_REST_Request $request ) {
		$params = $request->get_json_params();
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
		$params = $request->get_json_params();
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
		$params  = $request->get_json_params();
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
	 * Resets the index.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_reset() {
		$this->indexer->reset_index();
		return rest_ensure_response( $this->indexer->get_status() );
	}
}
