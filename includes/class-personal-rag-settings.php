<?php
/**
 * Plugin settings.
 *
 * @package Personal_RAG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores non-secret Personal RAG preferences.
 */
class Personal_RAG_Settings {
	const OPTION_NAME = 'personal_rag_settings';

	/**
	 * Returns default settings.
	 *
	 * @return array<string,mixed>
	 */
	public function defaults() {
		return array(
			'endpoint'       => 'http://localhost:11434',
			'embeddingModel' => 'embeddinggemma',
			'chatModel'      => 'gemma4:e4b',
			'topK'           => 5,
		);
	}

	/**
	 * Registers the option for WordPress settings awareness.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			'personal_rag',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->defaults(),
				'show_in_rest'      => array(
					'schema' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'properties'           => array(
							'endpoint'       => array(
								'type'   => 'string',
								'format' => 'uri',
							),
							'embeddingModel' => array(
								'type' => 'string',
							),
							'chatModel'      => array(
								'type' => 'string',
							),
							'topK'           => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 12,
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Gets sanitized settings.
	 *
	 * @return array<string,mixed>
	 */
	public function get() {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return $this->sanitize( array_merge( $this->defaults(), $stored ) );
	}

	/**
	 * Updates sanitized settings.
	 *
	 * @param array<string,mixed> $settings Settings payload.
	 * @return array<string,mixed>
	 */
	public function update( $settings ) {
		$current  = $this->get();
		$settings = is_array( $settings ) ? $settings : array();
		$settings = $this->sanitize( array_merge( $current, $settings ) );
		update_option( self::OPTION_NAME, $settings );

		return $settings;
	}

	/**
	 * Sanitizes settings.
	 *
	 * @param mixed $settings Settings payload.
	 * @return array<string,mixed>
	 */
	public function sanitize( $settings ) {
		$defaults = $this->defaults();
		$settings = is_array( $settings ) ? $settings : array();

		$endpoint = isset( $settings['endpoint'] ) ? esc_url_raw( $settings['endpoint'] ) : $defaults['endpoint'];
		if ( ! preg_match( '#^https?://#i', $endpoint ) ) {
			$endpoint = $defaults['endpoint'];
		}

		$embedding_model = isset( $settings['embeddingModel'] ) ? sanitize_text_field( $settings['embeddingModel'] ) : $defaults['embeddingModel'];
		$chat_model      = isset( $settings['chatModel'] ) ? sanitize_text_field( $settings['chatModel'] ) : $defaults['chatModel'];
		$top_k           = isset( $settings['topK'] ) ? absint( $settings['topK'] ) : $defaults['topK'];

		return array(
			'endpoint'       => $endpoint,
			'embeddingModel' => '' !== $embedding_model ? $embedding_model : $defaults['embeddingModel'],
			'chatModel'      => '' !== $chat_model ? $chat_model : $defaults['chatModel'],
			'topK'           => max( 1, min( 12, $top_k ) ),
		);
	}
}
