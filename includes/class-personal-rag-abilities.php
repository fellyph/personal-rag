<?php
/**
 * WordPress Abilities API integration.
 *
 * @package Personal_RAG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers optional Abilities API hooks.
 */
class Personal_RAG_Abilities {
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
	 * Registers Abilities API callbacks when available.
	 *
	 * @return void
	 */
	public function maybe_register() {
		if ( ! function_exists( 'wp_register_ability_category' ) || ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_ability_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_filter( 'ai_assistant_ability_domains', array( $this, 'ability_domains' ) );
	}

	/**
	 * Registers the ability category.
	 *
	 * @return void
	 */
	public function register_ability_category() {
		wp_register_ability_category(
			'personal-rag',
			array(
				'label'       => __( 'Personal RAG', 'personal-rag' ),
				'description' => __( 'Private local retrieval over WordPress content.', 'personal-rag' ),
			)
		);
	}

	/**
	 * Registers abilities.
	 *
	 * @return void
	 */
	public function register_abilities() {
		wp_register_ability(
			'personal-rag/get-status',
			array(
				'label'               => __( 'Get Personal RAG Status', 'personal-rag' ),
				'description'         => __( 'Returns local RAG index counts: source count, chunk count, queued embeddings, and embedded vectors.', 'personal-rag' ),
				'category'            => 'personal-rag',
				'execute_callback'    => array( $this, 'ability_get_status' ),
				'permission_callback' => array( $this, 'permission_read' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
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
	 * Returns status for the ability.
	 *
	 * @return array<string,mixed>
	 */
	public function ability_get_status() {
		return $this->indexer->get_status();
	}

	/**
	 * Adds ability domain hints.
	 *
	 * @param array<string,string> $domains Existing domains.
	 * @return array<string,string>
	 */
	public function ability_domains( $domains ) {
		$domains['personal-rag'] = __( 'private rag, local search, site knowledge, blog search, embedded WordPress content', 'personal-rag' );
		return $domains;
	}
}
