<?php
/**
 * Content indexing and search.
 *
 * @package Personal_RAG
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maintains indexed WordPress content and stored embeddings.
 */
class Personal_RAG_Indexer {
	/**
	 * Vector helper.
	 *
	 * @var Personal_RAG_Vectors
	 */
	private $vectors;

	/**
	 * Constructor.
	 *
	 * @param Personal_RAG_Vectors $vectors Vector helper.
	 */
	public function __construct( Personal_RAG_Vectors $vectors ) {
		$this->vectors = $vectors;
	}

	/**
	 * Returns index status counts.
	 *
	 * @return array<string,mixed>
	 */
	public function get_status() {
		global $wpdb;

		$tables = Personal_RAG_Schema::table_names();

		return array(
			'sources'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['sources']}" ),
			'chunks'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['chunks']}" ),
			'queued'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['chunks']} WHERE embedding_status = 'queued'" ),
			'embedded'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tables['vectors']}" ),
			'dbVersion'  => get_option( Personal_RAG_Schema::OPTION_VERSION ),
			'indexables' => count( $this->get_indexable_post_ids() ),
		);
	}

	/**
	 * Queues all indexable content.
	 *
	 * @param bool $force Whether to force re-queueing unchanged content.
	 * @return array<string,mixed>
	 */
	public function queue_all_sources( $force = false ) {
		global $wpdb;

		$results = array(
			'queued'    => 0,
			'unchanged' => 0,
			'skipped'   => 0,
			'deleted'   => 0,
			'status'    => array(),
		);

		$post_ids = $this->get_indexable_post_ids();
		$seen     = array();

		foreach ( $post_ids as $post_id ) {
			$seen[ $post_id ] = true;
			$result           = $this->queue_post( $post_id, $force );
			if ( isset( $results[ $result ] ) ) {
				++$results[ $result ];
			}
		}

		$tables  = Personal_RAG_Schema::table_names();
		$sources = $wpdb->get_results( "SELECT id, source_id FROM {$tables['sources']} WHERE source_type = 'post'", ARRAY_A );
		foreach ( $sources as $source ) {
			if ( ! isset( $seen[ (int) $source['source_id'] ] ) ) {
				$this->delete_source_by_id( (int) $source['id'] );
				++$results['deleted'];
			}
		}

		$results['status'] = $this->get_status();
		return $results;
	}

	/**
	 * Gets queued chunks for embedding.
	 *
	 * @param int $limit Maximum number of chunks.
	 * @return array<string,mixed>
	 */
	public function get_index_batch( $limit = 8 ) {
		global $wpdb;

		$tables = Personal_RAG_Schema::table_names();
		$limit  = max( 1, min( 50, absint( $limit ) ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.chunk_index, c.chunk_text, c.token_estimate, s.title, s.url, s.source_id, s.post_type
				FROM {$tables['chunks']} c
				INNER JOIN {$tables['sources']} s ON s.id = c.source_id
				WHERE c.embedding_status = 'queued'
				ORDER BY s.updated_at DESC, c.id ASC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'id'            => (int) $row['id'],
				'chunkIndex'    => (int) $row['chunk_index'],
				'text'          => $row['chunk_text'],
				'tokenEstimate' => (int) $row['token_estimate'],
				'title'         => $row['title'],
				'url'           => $row['url'],
				'sourceId'      => (int) $row['source_id'],
				'postType'      => $row['post_type'],
			);
		}

		return array(
			'items'  => $items,
			'status' => $this->get_status(),
		);
	}

	/**
	 * Saves chunk embeddings.
	 *
	 * @param array<int,array<string,mixed>> $items Embedding payloads.
	 * @param string                         $model Embedding model.
	 * @return array<string,mixed>|WP_Error
	 */
	public function save_embeddings( $items, $model ) {
		global $wpdb;

		$model = sanitize_text_field( $model );
		if ( '' === $model ) {
			return new WP_Error(
				'personal_rag_missing_model',
				__( 'Embedding model is required.', 'personal-rag' ),
				array( 'status' => 400 )
			);
		}

		$tables     = Personal_RAG_Schema::table_names();
		$saved      = 0;
		$source_ids = array();

		foreach ( $items as $item ) {
			$chunk_id = absint( isset( $item['chunkId'] ) ? $item['chunkId'] : 0 );
			$encoded  = isset( $item['vector'] ) ? (string) $item['vector'] : '';

			if ( ! $chunk_id || '' === $encoded ) {
				continue;
			}

			$decoded = $this->vectors->decode_vector( $encoded );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}

			$dimension = count( $decoded['values'] );
			if ( isset( $item['dimensions'] ) && absint( $item['dimensions'] ) !== $dimension ) {
				return new WP_Error(
					'personal_rag_dimension_mismatch',
					__( 'Embedding dimension mismatch.', 'personal-rag' ),
					array( 'status' => 400 )
				);
			}

			$source_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT source_id FROM {$tables['chunks']} WHERE id = %d",
					$chunk_id
				)
			);

			if ( ! $source_id ) {
				continue;
			}

			$stored = $wpdb->replace(
				$tables['vectors'],
				array(
					'chunk_id'   => $chunk_id,
					'model'      => $model,
					'dimensions' => $dimension,
					'vector'     => $decoded['encoded'],
					'norm'       => $decoded['norm'],
					'created_at' => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%d', '%s', '%f', '%s' )
			);

			if ( false === $stored ) {
				return new WP_Error(
					'personal_rag_vector_save_failed',
					$wpdb->last_error ? $wpdb->last_error : __( 'Could not save embedding vector.', 'personal-rag' ),
					array( 'status' => 500 )
				);
			}

			$wpdb->update(
				$tables['chunks'],
				array(
					'embedding_status' => 'embedded',
					'updated_at'       => current_time( 'mysql' ),
				),
				array( 'id' => $chunk_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			$source_ids[ $source_id ] = true;
			++$saved;
		}

		$this->mark_completed_sources( array_keys( $source_ids ) );

		return array(
			'saved'  => $saved,
			'status' => $this->get_status(),
		);
	}

	/**
	 * Searches stored vectors.
	 *
	 * @param string $encoded Query vector payload.
	 * @param string $model   Embedding model filter.
	 * @param int    $top_k   Number of matches.
	 * @return array<string,mixed>|WP_Error
	 */
	public function search( $encoded, $model = '', $top_k = 5 ) {
		global $wpdb;

		$model        = sanitize_text_field( $model );
		$top_k        = max( 1, min( 12, absint( $top_k ) ) );
		$query_vector = $this->vectors->decode_vector( $encoded );

		if ( is_wp_error( $query_vector ) ) {
			return $query_vector;
		}

		$tables    = Personal_RAG_Schema::table_names();
		$dimension = count( $query_vector['values'] );

		if ( '' !== $model ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT v.vector, v.norm, c.id AS chunk_id, c.chunk_index, c.chunk_text, s.title, s.url, s.source_id, s.post_type
					FROM {$tables['vectors']} v
					INNER JOIN {$tables['chunks']} c ON c.id = v.chunk_id
					INNER JOIN {$tables['sources']} s ON s.id = c.source_id
					WHERE v.dimensions = %d AND v.model = %s",
					$dimension,
					$model
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT v.vector, v.norm, c.id AS chunk_id, c.chunk_index, c.chunk_text, s.title, s.url, s.source_id, s.post_type
					FROM {$tables['vectors']} v
					INNER JOIN {$tables['chunks']} c ON c.id = v.chunk_id
					INNER JOIN {$tables['sources']} s ON s.id = c.source_id
					WHERE v.dimensions = %d",
					$dimension
				),
				ARRAY_A
			);
		}

		$matches = array();
		foreach ( $rows as $row ) {
			$vector = $this->vectors->stored_vector_to_floats( $row['vector'] );
			if ( count( $vector ) !== $dimension ) {
				continue;
			}

			$score = $this->vectors->cosine_similarity( $query_vector['values'], $query_vector['norm'], $vector, (float) $row['norm'] );
			if ( null === $score ) {
				continue;
			}

			$matches[] = array(
				'chunkId'    => (int) $row['chunk_id'],
				'sourceId'   => (int) $row['source_id'],
				'postType'   => $row['post_type'],
				'chunkIndex' => (int) $row['chunk_index'],
				'title'      => $row['title'],
				'url'        => $row['url'],
				'text'       => $row['chunk_text'],
				'score'      => round( $score, 6 ),
			);
		}

		usort(
			$matches,
			function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return 0;
				}

				return ( $a['score'] > $b['score'] ) ? -1 : 1;
			}
		);

		return array(
			'matches' => array_slice( $matches, 0, $top_k ),
			'total'   => count( $matches ),
		);
	}

	/**
	 * Handles post saves.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 * @return void
	 */
	public function handle_save_post( $post_id, $post, $update ) {
		unset( $post, $update );

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$this->queue_post( $post_id, false );
	}

	/**
	 * Handles post deletion/trashing.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function handle_delete_post( $post_id ) {
		$this->delete_source_by_wp_post( $post_id );
	}

	/**
	 * Handles post restore.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function handle_untrashed_post( $post_id ) {
		$this->queue_post( $post_id, false );
	}

	/**
	 * Queues one post for embedding.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Whether to force re-queueing unchanged content.
	 * @return string Queue result.
	 */
	public function queue_post( $post_id, $force = false ) {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post || ! $this->is_indexable_post( $post ) ) {
			$this->delete_source_by_wp_post( $post_id );
			return 'skipped';
		}

		$tables       = Personal_RAG_Schema::table_names();
		$title        = get_the_title( $post );
		$content      = $this->extract_post_text( $post );
		$source_text  = trim( $title . "\n\n" . $content );
		$content_hash = hash( 'sha256', $source_text );
		$permalink    = get_permalink( $post );
		$url          = $permalink ? $permalink : home_url( '?p=' . $post_id );
		$now          = current_time( 'mysql' );

		$source = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tables['sources']} WHERE source_type = %s AND source_id = %d",
				'post',
				$post_id
			),
			ARRAY_A
		);

		if ( $source && ! $force && hash_equals( $source['content_hash'], $content_hash ) ) {
			return 'unchanged';
		}

		$source_data = array(
			'source_type'  => 'post',
			'source_id'    => $post_id,
			'post_type'    => $post->post_type,
			'post_status'  => $post->post_status,
			'title'        => $title,
			'url'          => (string) $url,
			'content_hash' => $content_hash,
			'updated_at'   => $now,
			'indexed_at'   => null,
		);

		if ( $source ) {
			$source_id = (int) $source['id'];
			$wpdb->update(
				$tables['sources'],
				$source_data,
				array( 'id' => $source_id ),
				array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert(
				$tables['sources'],
				$source_data,
				array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$source_id = (int) $wpdb->insert_id;
		}

		if ( ! $source_id ) {
			return 'skipped';
		}

		$this->delete_chunks_for_source( $source_id );

		$chunks = $this->chunk_text( $source_text, $title );
		if ( empty( $chunks ) ) {
			$this->delete_source_by_id( $source_id );
			return 'skipped';
		}

		foreach ( $chunks as $index => $chunk_text ) {
			$wpdb->insert(
				$tables['chunks'],
				array(
					'source_id'        => $source_id,
					'chunk_index'      => $index,
					'chunk_text'       => $chunk_text,
					'chunk_hash'       => hash( 'sha256', $chunk_text ),
					'token_estimate'   => $this->estimate_tokens( $chunk_text ),
					'embedding_status' => 'queued',
					'created_at'       => $now,
					'updated_at'       => $now,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
			);
		}

		return 'queued';
	}

	/**
	 * Returns indexable post IDs.
	 *
	 * @return array<int,int>
	 */
	public function get_indexable_post_ids() {
		return get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
	}

	/**
	 * Determines whether a post should be indexed.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	public function is_indexable_post( $post ) {
		return in_array( $post->post_type, array( 'post', 'page' ), true )
			&& in_array( $post->post_status, array( 'publish', 'private' ), true );
	}

	/**
	 * Extracts searchable text from a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public function extract_post_text( WP_Post $post ) {
		$content = do_blocks( $post->post_content );
		$content = strip_shortcodes( $content );
		$content = wp_strip_all_tags( $content, true );
		$content = html_entity_decode( $content, ENT_QUOTES, get_bloginfo( 'charset' ) );
		$content = preg_replace( '/\s+/', ' ', $content );

		return trim( $content );
	}

	/**
	 * Splits text into overlapping chunks.
	 *
	 * @param string $text  Text to chunk.
	 * @param string $title Source title.
	 * @return array<int,string>
	 */
	public function chunk_text( $text, $title ) {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( '' === $text ) {
			return array();
		}

		$words       = preg_split( '/\s+/', $text );
		$target_size = 650;
		$overlap     = 80;
		$chunks      = array();
		$count       = count( $words );

		for ( $start = 0; $start < $count; $start += max( 1, $target_size - $overlap ) ) {
			$slice = array_slice( $words, $start, $target_size );
			if ( empty( $slice ) ) {
				break;
			}

			$chunk = implode( ' ', $slice );
			if ( $title && 0 !== strpos( $chunk, $title ) ) {
				$chunk = $title . "\n\n" . $chunk;
			}
			$chunks[] = $chunk;

			if ( $start + $target_size >= $count ) {
				break;
			}
		}

		return $chunks;
	}

	/**
	 * Estimates token count.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public function estimate_tokens( $text ) {
		return max( 1, (int) ceil( strlen( $text ) / 4 ) );
	}

	/**
	 * Deletes a source by WordPress post ID.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function delete_source_by_wp_post( $post_id ) {
		global $wpdb;

		$tables = Personal_RAG_Schema::table_names();
		$id     = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$tables['sources']} WHERE source_type = %s AND source_id = %d",
				'post',
				$post_id
			)
		);

		if ( $id ) {
			$this->delete_source_by_id( $id );
		}
	}

	/**
	 * Deletes all index data.
	 *
	 * @return void
	 */
	public function reset_index() {
		global $wpdb;

		$tables = Personal_RAG_Schema::table_names();
		$wpdb->query( "DELETE FROM {$tables['vectors']}" );
		$wpdb->query( "DELETE FROM {$tables['chunks']}" );
		$wpdb->query( "DELETE FROM {$tables['sources']}" );
	}

	/**
	 * Deletes a source row and related chunks/vectors.
	 *
	 * @param int $source_id Source row ID.
	 * @return void
	 */
	public function delete_source_by_id( $source_id ) {
		global $wpdb;

		$tables = Personal_RAG_Schema::table_names();
		$this->delete_chunks_for_source( $source_id );
		$wpdb->delete( $tables['sources'], array( 'id' => $source_id ), array( '%d' ) );
	}

	/**
	 * Deletes chunks and vectors for a source.
	 *
	 * @param int $source_id Source row ID.
	 * @return void
	 */
	public function delete_chunks_for_source( $source_id ) {
		global $wpdb;

		$tables = Personal_RAG_Schema::table_names();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$tables['vectors']} WHERE chunk_id IN (SELECT id FROM {$tables['chunks']} WHERE source_id = %d)",
				$source_id
			)
		);
		$wpdb->delete( $tables['chunks'], array( 'source_id' => $source_id ), array( '%d' ) );
	}

	/**
	 * Marks sources fully embedded.
	 *
	 * @param array<int,int> $source_ids Source IDs.
	 * @return void
	 */
	public function mark_completed_sources( $source_ids ) {
		global $wpdb;

		if ( empty( $source_ids ) ) {
			return;
		}

		$tables = Personal_RAG_Schema::table_names();
		foreach ( $source_ids as $source_id ) {
			$queued = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$tables['chunks']} WHERE source_id = %d AND embedding_status = 'queued'",
					$source_id
				)
			);
			if ( 0 === $queued ) {
				$wpdb->update(
					$tables['sources'],
					array( 'indexed_at' => current_time( 'mysql' ) ),
					array( 'id' => $source_id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
	}
}
