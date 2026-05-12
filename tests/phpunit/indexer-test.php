<?php
/**
 * Indexer tests.
 *
 * @package Personal_RAG
 */

require_once __DIR__ . '/PersonalRagTestCase.php';

/**
 * Tests indexing, vectors, and search.
 */
class IndexerTest extends Personal_RAG_Test_Case {
	/**
	 * Schema installation creates usable tables and version metadata.
	 */
	public function test_schema_installation_creates_usable_tables() {
		$status = $this->indexer->get_status();

		$this->assertSame( 0, $status['sources'] );
		$this->assertSame( 0, $status['chunks'] );
		$this->assertSame( Personal_RAG_Schema::DB_VERSION, $status['dbVersion'] );
	}

	/**
	 * Published posts/pages and private posts/pages are queued.
	 */
	public function test_queue_all_sources_indexes_publish_and_private_posts_and_pages() {
		$this->create_post(
			array(
				'post_title'   => 'Published Playground Guide',
				'post_content' => 'Blueprints configure WordPress Playground with JSON.',
			)
		);
		$this->create_post(
			array(
				'post_title'   => 'Private Playground Notes',
				'post_content' => 'Private content should be available to authenticated readers.',
				'post_status'  => 'private',
				'post_type'    => 'page',
			)
		);
		$this->create_post(
			array(
				'post_title'  => 'Draft Playground Notes',
				'post_status' => 'draft',
			)
		);

		$result = $this->indexer->queue_all_sources( false );
		$status = $this->indexer->get_status();

		$this->assertSame( 2, $result['queued'] + $result['unchanged'] );
		$this->assertSame( 2, $status['sources'] );
		$this->assertSame( 2, $status['chunks'] );
		$this->assertSame( 2, $status['queued'] );
		$this->assertSame( 2, $status['indexables'] );
	}

	/**
	 * Drafts and revisions are ignored.
	 */
	public function test_drafts_and_revisions_are_not_indexed() {
		$draft_id = $this->create_post(
			array(
				'post_title'  => 'Draft Source',
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 'skipped', $this->indexer->queue_post( $draft_id, true ) );

		$post_id = $this->create_post( array( 'post_title' => 'Published Source' ) );
		$this->assertSame( 'queued', $this->indexer->queue_post( $post_id, true ) );

		$revision_id = wp_insert_post(
			array(
				'post_title'   => 'Published Source Revision',
				'post_content' => 'Revision content should not create a source.',
				'post_status'  => 'inherit',
				'post_type'    => 'revision',
				'post_parent'  => $post_id,
			),
			true
		);
		$this->assertNotWPError( $revision_id );

		$this->indexer->handle_save_post( (int) $revision_id, get_post( $revision_id ), true );

		$status = $this->indexer->get_status();
		$this->assertSame( 1, $status['sources'] );
		$this->assertSame( 1, $status['chunks'] );
	}

	/**
	 * Trashing and restoring content removes and requeues sources.
	 */
	public function test_trash_delete_and_untrash_handlers_update_sources() {
		$post_id = $this->create_post( array( 'post_title' => 'Lifecycle Source' ) );
		$this->indexer->queue_post( $post_id, true );
		$this->assertSame( 1, $this->indexer->get_status()['sources'] );

		$this->indexer->handle_delete_post( $post_id );
		$this->assertSame( 0, $this->indexer->get_status()['sources'] );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		$this->indexer->handle_untrashed_post( $post_id );
		$this->assertSame( 1, $this->indexer->get_status()['sources'] );
	}

	/**
	 * Chunking is deterministic and includes the source title.
	 */
	public function test_chunk_text_adds_title_and_overlaps_long_content() {
		$words = array();
		for ( $i = 0; $i < 800; $i++ ) {
			$words[] = 'word' . $i;
		}

		$chunks = $this->indexer->chunk_text( implode( ' ', $words ), 'Chunk Title' );

		$this->assertCount( 2, $chunks );
		$this->assertStringStartsWith( 'Chunk Title', $chunks[0] );
		$this->assertStringStartsWith( 'Chunk Title', $chunks[1] );
		$this->assertGreaterThan( 1, $this->indexer->estimate_tokens( $chunks[0] ) );
	}

	/**
	 * Invalid and mismatched embedding payloads return errors.
	 */
	public function test_save_embeddings_validates_vectors() {
		$post_id = $this->create_post();
		$this->indexer->queue_post( $post_id, true );
		$batch = $this->indexer->get_index_batch( 1 );
		$chunk = $batch['items'][0];

		$missing_model = $this->indexer->save_embeddings(
			array(
				array(
					'chunkId'    => $chunk['id'],
					'vector'     => $this->vector_payload( array( 1.0, 0.0 ) ),
					'dimensions' => 2,
				),
			),
			''
		);
		$this->assertInstanceOf( WP_Error::class, $missing_model );
		$this->assertSame( 'personal_rag_missing_model', $missing_model->get_error_code() );

		$invalid = $this->indexer->save_embeddings(
			array(
				array(
					'chunkId' => $chunk['id'],
					'vector'  => 'not-a-vector',
				),
			),
			'embeddinggemma'
		);
		$this->assertInstanceOf( WP_Error::class, $invalid );
		$this->assertSame( 'personal_rag_invalid_vector', $invalid->get_error_code() );

		$mismatch = $this->indexer->save_embeddings(
			array(
				array(
					'chunkId'    => $chunk['id'],
					'vector'     => $this->vector_payload( array( 1.0, 0.0 ) ),
					'dimensions' => 3,
				),
			),
			'embeddinggemma'
		);
		$this->assertInstanceOf( WP_Error::class, $mismatch );
		$this->assertSame( 'personal_rag_dimension_mismatch', $mismatch->get_error_code() );
	}

	/**
	 * Search ranks the closest vector first.
	 */
	public function test_search_ranks_by_cosine_similarity_and_reset_clears_index() {
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
		$this->assertSame( 2, $saved['saved'] );

		$search = $this->indexer->search( $this->vector_payload( array( 1.0, 0.0 ) ), 'embeddinggemma', 2 );
		$this->assertSame( 2, $search['total'] );
		$this->assertSame( 'Blueprint Source', $search['matches'][0]['title'] );

		$this->indexer->reset_index();
		$status = $this->indexer->get_status();
		$this->assertSame( 0, $status['sources'] );
		$this->assertSame( 0, $status['chunks'] );
		$this->assertSame( 0, $status['embedded'] );
	}
}
