<?php
/**
 * WordPress AI Client and Connectors integration.
 *
 * @package Personal_RAG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates answers through the WordPress 7.0 AI Client.
 */
class Personal_RAG_AI {
	const OLLAMA_CONNECTOR_ID    = 'ollama';
	const OLLAMA_PROVIDER_PLUGIN = 'ai-provider-for-ollama/plugin.php';

	/**
	 * Settings service.
	 *
	 * @var Personal_RAG_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Personal_RAG_Settings $settings Settings service.
	 */
	public function __construct( Personal_RAG_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Returns AI and connector status.
	 *
	 * @return array<string,mixed>
	 */
	public function get_status() {
		$settings       = $this->settings->get();
		$connector      = $this->find_ollama_connector();
		$plugin_status  = $this->get_ollama_provider_plugin_status();
		$wp_supports_ai = ! function_exists( 'wp_supports_ai' ) || wp_supports_ai();

		$status = array(
			'aiClientAvailable'       => function_exists( 'wp_ai_client_prompt' ),
			'aiSupported'             => (bool) $wp_supports_ai,
			'connectorsAvailable'     => function_exists( 'wp_get_connector' ) || function_exists( 'wp_get_connectors' ),
			'textGenerationSupported' => $this->is_text_generation_supported( $settings ),
			'connectorsUrl'           => esc_url_raw( admin_url( 'options-connectors.php' ) ),
			'ollamaSettingsUrl'       => esc_url_raw( admin_url( 'options-general.php?page=ollama' ) ),
			'ollamaProvider'          => array_merge(
				$plugin_status,
				array(
					'connectorRegistered' => ! empty( $connector ),
					'connectorId'         => $connector ? $connector['id'] : self::OLLAMA_CONNECTOR_ID,
					'connector'           => $connector ? $this->normalize_connector( $connector['data'] ) : null,
				)
			),
		);

		/**
		 * Filters the Personal RAG AI status.
		 *
		 * @param array<string,mixed>  $status   AI status.
		 * @param array<string,mixed>  $settings Plugin settings.
		 * @param Personal_RAG_AI      $ai       AI service.
		 */
		return apply_filters( 'personal_rag_ai_status', $status, $settings, $this );
	}

	/**
	 * Generates a source-grounded answer.
	 *
	 * @param string                         $question User question.
	 * @param array<int,array<string,mixed>> $matches  Ranked source matches.
	 * @param array<string,mixed>            $settings Plugin settings.
	 * @return array<string,mixed>|WP_Error
	 */
	public function generate_answer( $question, $matches, $settings ) {
		$question = trim( wp_strip_all_tags( (string) $question ) );
		if ( '' === $question ) {
			return new WP_Error(
				'personal_rag_empty_question',
				__( 'Question is required.', 'personal-rag' ),
				array( 'status' => 400 )
			);
		}

		$matches = $this->normalize_matches( $matches );

		/**
		 * Short-circuits Personal RAG answer generation.
		 *
		 * @param null|array<string,mixed>|WP_Error $pre      Pre-generated answer payload.
		 * @param string                           $question User question.
		 * @param array<int,array<string,mixed>>   $matches  Ranked source matches.
		 * @param array<string,mixed>              $settings Plugin settings.
		 * @param Personal_RAG_AI                  $ai       AI service.
		 */
		$pre = apply_filters( 'personal_rag_pre_generate_answer', null, $question, $matches, $settings, $this );
		if ( null !== $pre ) {
			return $pre;
		}

		$status = $this->get_status();
		if ( empty( $status['aiClientAvailable'] ) ) {
			return new WP_Error(
				'personal_rag_ai_client_missing',
				__( 'WordPress AI Client is not available. Personal RAG requires WordPress 7.0 or later for answer generation.', 'personal-rag' ),
				array( 'status' => 501 )
			);
		}

		if ( empty( $status['aiSupported'] ) ) {
			return new WP_Error(
				'personal_rag_ai_disabled',
				__( 'AI features are disabled on this site.', 'personal-rag' ),
				array( 'status' => 403 )
			);
		}

		if ( empty( $status['textGenerationSupported'] ) ) {
			return new WP_Error(
				'personal_rag_text_generation_unavailable',
				__( 'No configured AI connector currently supports text generation.', 'personal-rag' ),
				array( 'status' => 424 )
			);
		}

		$system_prompt = implode(
			' ',
			array(
				__( 'You are a private RAG assistant running inside WordPress.', 'personal-rag' ),
				__( 'Answer only from the provided local WordPress sources.', 'personal-rag' ),
				__( 'Use citations like [1], [2] for claims.', 'personal-rag' ),
				__( 'If the sources do not contain enough evidence, say that clearly and do not guess.', 'personal-rag' ),
			)
		);

		$user_prompt = sprintf(
			/* translators: 1: User question. 2: Local source excerpts. */
			__( "Question: %1\$s\n\nLocal sources:\n%2\$s", 'personal-rag' ),
			$question,
			$this->format_sources_for_prompt( $matches )
		);

		$builder = wp_ai_client_prompt( $user_prompt );
		if ( method_exists( $builder, 'using_system_instruction' ) ) {
			$builder = $builder->using_system_instruction( $system_prompt );
		}
		if ( method_exists( $builder, 'using_temperature' ) ) {
			$builder = $builder->using_temperature( 0.2 );
		}
		if ( ! empty( $settings['chatModel'] ) && method_exists( $builder, 'using_model_preference' ) ) {
			$builder = $builder->using_model_preference( sanitize_text_field( $settings['chatModel'] ) );
		}

		$answer = $builder->generate_text();
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		return array(
			'answer'  => (string) $answer,
			'sources' => $matches,
			'status'  => $status,
		);
	}

	/**
	 * Checks whether text generation appears supported.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return bool
	 */
	private function is_text_generation_supported( $settings ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
			return false;
		}

		try {
			$builder = wp_ai_client_prompt( 'test' );
			if ( ! empty( $settings['chatModel'] ) && method_exists( $builder, 'using_model_preference' ) ) {
				$builder = $builder->using_model_preference( sanitize_text_field( $settings['chatModel'] ) );
			}

			if ( method_exists( $builder, 'is_supported_for_text_generation' ) ) {
				return (bool) $builder->is_supported_for_text_generation();
			}
		} catch ( Throwable $error ) {
			return false;
		}

		return true;
	}

	/**
	 * Finds the Ollama connector when registered.
	 *
	 * @return array{id:string,data:array<string,mixed>}|null
	 */
	private function find_ollama_connector() {
		if ( function_exists( 'wp_is_connector_registered' ) && ! wp_is_connector_registered( self::OLLAMA_CONNECTOR_ID ) ) {
			return $this->find_ollama_connector_from_registry();
		}

		if ( function_exists( 'wp_get_connector' ) ) {
			$connector = wp_get_connector( self::OLLAMA_CONNECTOR_ID );
			if ( is_array( $connector ) ) {
				return array(
					'id'   => self::OLLAMA_CONNECTOR_ID,
					'data' => $connector,
				);
			}
		}

		return $this->find_ollama_connector_from_registry();
	}

	/**
	 * Searches all registered connectors for the Ollama provider.
	 *
	 * @return array{id:string,data:array<string,mixed>}|null
	 */
	private function find_ollama_connector_from_registry() {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return null;
		}

		$connectors = wp_get_connectors();
		if ( ! is_array( $connectors ) ) {
			return null;
		}

		foreach ( $connectors as $id => $connector ) {
			if ( ! is_array( $connector ) ) {
				continue;
			}

			$plugin = isset( $connector['plugin']['file'] ) ? $connector['plugin']['file'] : '';
			$name   = isset( $connector['name'] ) ? $connector['name'] : '';
			if ( self::OLLAMA_PROVIDER_PLUGIN === $plugin || false !== stripos( (string) $name, 'ollama' ) ) {
				return array(
					'id'   => sanitize_key( $id ),
					'data' => $connector,
				);
			}
		}

		return null;
	}

	/**
	 * Returns plugin installation/activation status.
	 *
	 * @return array<string,mixed>
	 */
	private function get_ollama_provider_plugin_status() {
		$plugin_file = self::OLLAMA_PROVIDER_PLUGIN;
		$installed   = defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin_file );
		$active      = $installed && $this->is_plugin_active( $plugin_file );

		return array(
			'pluginFile'  => $plugin_file,
			'installed'   => $installed,
			'active'      => $active,
			'installUrl'  => esc_url_raw( admin_url( 'plugin-install.php?s=ai-provider-for-ollama&tab=search&type=term' ) ),
			'activateUrl' => $installed && ! $active ? esc_url_raw( wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $plugin_file ) ), 'activate-plugin_' . $plugin_file ) ) : '',
		);
	}

	/**
	 * Checks whether a plugin is active.
	 *
	 * @param string $plugin_file Plugin file.
	 * @return bool
	 */
	private function is_plugin_active( $plugin_file ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $plugin_file );
	}

	/**
	 * Normalizes connector data for REST output.
	 *
	 * @param array<string,mixed> $connector Connector data.
	 * @return array<string,mixed>
	 */
	private function normalize_connector( $connector ) {
		return array(
			'name'           => isset( $connector['name'] ) ? sanitize_text_field( $connector['name'] ) : __( 'Ollama', 'personal-rag' ),
			'description'    => isset( $connector['description'] ) ? sanitize_text_field( $connector['description'] ) : '',
			'type'           => isset( $connector['type'] ) ? sanitize_key( $connector['type'] ) : '',
			'authentication' => array(
				'method' => isset( $connector['authentication']['method'] ) ? sanitize_key( $connector['authentication']['method'] ) : '',
			),
		);
	}

	/**
	 * Normalizes matches for output and prompting.
	 *
	 * @param array<int,array<string,mixed>> $matches Search matches.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_matches( $matches ) {
		$normalized = array();
		foreach ( $matches as $match ) {
			$normalized[] = array(
				'chunkId'    => isset( $match['chunkId'] ) ? absint( $match['chunkId'] ) : 0,
				'sourceId'   => isset( $match['sourceId'] ) ? absint( $match['sourceId'] ) : 0,
				'postType'   => isset( $match['postType'] ) ? sanitize_key( $match['postType'] ) : '',
				'chunkIndex' => isset( $match['chunkIndex'] ) ? absint( $match['chunkIndex'] ) : 0,
				'title'      => isset( $match['title'] ) ? sanitize_text_field( $match['title'] ) : __( 'Untitled', 'personal-rag' ),
				'url'        => isset( $match['url'] ) ? esc_url_raw( $match['url'] ) : '',
				'text'       => isset( $match['text'] ) ? wp_strip_all_tags( (string) $match['text'] ) : '',
				'score'      => isset( $match['score'] ) ? (float) $match['score'] : 0,
			);
		}

		return $normalized;
	}

	/**
	 * Formats sources for a prompt.
	 *
	 * @param array<int,array<string,mixed>> $matches Search matches.
	 * @return string
	 */
	private function format_sources_for_prompt( $matches ) {
		if ( empty( $matches ) ) {
			return __( 'No matching local sources were found.', 'personal-rag' );
		}

		$source_lines = array();
		foreach ( $matches as $index => $match ) {
			$source_lines[] = sprintf(
				"[%1\$d] %2\$s\nURL: %3\$s\nText: %4\$s",
				$index + 1,
				$match['title'],
				$match['url'],
				$match['text']
			);
		}

		return implode( "\n\n", $source_lines );
	}
}
