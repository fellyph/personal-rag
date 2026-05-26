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
	const CACHE_GROUP = 'personal_rag';

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
		$found  = false;
		$status = wp_cache_get( 'status', self::CACHE_GROUP, false, $found );

		if ( $found && is_array( $status ) ) {
			return $status;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Counts are read from plugin-owned custom tables and cached.
		$status = array(
			'sources'    => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tables['sources'] ) ),
			'chunks'     => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tables['chunks'] ) ),
			'queued'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE embedding_status = 'queued'", $tables['chunks'] ) ),
			'embedded'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tables['vectors'] ) ),
			'dbVersion'  => get_option( Personal_RAG_Schema::OPTION_VERSION ),
			'indexables' => count( $this->get_indexable_post_ids() ),
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		wp_cache_set( 'status', $status, self::CACHE_GROUP );

		return $status;
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Write workflow needs the current custom-table source list.
		$sources = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, source_id FROM %i WHERE source_type = %s',
				$tables['sources'],
				'post'
			),
			ARRAY_A
		);
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
		$key    = 'index_batch_' . $limit;
		$found  = false;
		$rows   = wp_cache_get( $key, self::CACHE_GROUP, false, $found );

		if ( ! $found || ! is_array( $rows ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned custom table query cached by batch size.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.id, c.chunk_index, c.chunk_text, c.token_estimate, s.title, s.url, s.source_id, s.post_type
					FROM %i c
					INNER JOIN %i s ON s.id = c.source_id
					WHERE c.embedding_status = 'queued'
					ORDER BY s.updated_at DESC, c.id ASC
					LIMIT %d",
					$tables['chunks'],
					$tables['sources'],
					$limit
				),
				ARRAY_A
			);
			wp_cache_set( $key, $rows, self::CACHE_GROUP );
		}

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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Write workflow needs the current chunk source.
			$source_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT source_id FROM %i WHERE id = %d',
					$tables['chunks'],
					$chunk_id
				)
			);

			if ( ! $source_id ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to plugin-owned vector table.
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Updating plugin-owned chunk table.
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
		$this->flush_index_cache();

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
		$row_key   = 'search_rows_' . md5( $dimension . '|' . $model );
		$found     = false;
		$rows      = wp_cache_get( $row_key, self::CACHE_GROUP, false, $found );

		if ( ! $found || ! is_array( $rows ) ) {
			if ( '' !== $model ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned vector rows are cached per model/dimension.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT v.vector, v.norm, c.id AS chunk_id, c.chunk_index, c.chunk_text, s.title, s.url, s.source_id, s.post_type
						FROM %i v
						INNER JOIN %i c ON c.id = v.chunk_id
						INNER JOIN %i s ON s.id = c.source_id
						WHERE v.dimensions = %d AND v.model = %s",
						$tables['vectors'],
						$tables['chunks'],
						$tables['sources'],
						$dimension,
						$model
					),
					ARRAY_A
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned vector rows are cached per dimension.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT v.vector, v.norm, c.id AS chunk_id, c.chunk_index, c.chunk_text, s.title, s.url, s.source_id, s.post_type
						FROM %i v
						INNER JOIN %i c ON c.id = v.chunk_id
						INNER JOIN %i s ON s.id = c.source_id
						WHERE v.dimensions = %d",
						$tables['vectors'],
						$tables['chunks'],
						$tables['sources'],
						$dimension
					),
					ARRAY_A
				);
			}
			wp_cache_set( $row_key, $rows, self::CACHE_GROUP );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Write workflow needs current plugin-owned source row.
		$source = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE source_type = %s AND source_id = %d',
				$tables['sources'],
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Updating plugin-owned source table.
			$wpdb->update(
				$tables['sources'],
				$source_data,
				array( 'id' => $source_id ),
				array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Inserting into plugin-owned source table.
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Inserting into plugin-owned chunk table.
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

		$this->flush_index_cache();

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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete workflow needs the current source row ID.
		$id     = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE source_type = %s AND source_id = %d',
				$tables['sources'],
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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Resetting plugin-owned custom tables.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $tables['vectors'] ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $tables['chunks'] ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $tables['sources'] ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->flush_index_cache();
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting from plugin-owned source table.
		$wpdb->delete( $tables['sources'], array( 'id' => $source_id ), array( '%d' ) );
		$this->flush_index_cache();
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting from plugin-owned vector table.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE chunk_id IN (SELECT id FROM %i WHERE source_id = %d)',
				$tables['vectors'],
				$tables['chunks'],
				$source_id
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deleting from plugin-owned chunk table.
		$wpdb->delete( $tables['chunks'], array( 'source_id' => $source_id ), array( '%d' ) );
		$this->flush_index_cache();
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

		$tables  = Personal_RAG_Schema::table_names();
		$changed = false;
		foreach ( $source_ids as $source_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completion check must read current custom-table queue state.
			$queued = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE source_id = %d AND embedding_status = 'queued'",
					$tables['chunks'],
					$source_id
				)
			);
			if ( 0 === $queued ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Updating plugin-owned source table.
				$wpdb->update(
					$tables['sources'],
					array( 'indexed_at' => current_time( 'mysql' ) ),
					array( 'id' => $source_id ),
					array( '%s' ),
					array( '%d' )
				);
				$changed = true;
			}
		}
		if ( $changed ) {
			$this->flush_index_cache();
		}
	}

	/**
	 * Clears cached index reads.
	 *
	 * @return void
	 */
	private function flush_index_cache() {
		wp_cache_flush_group( self::CACHE_GROUP );
	}
}
