<?php
/**
 * Tests for ilsej_vertex_chunk_by_token_budget() in google-vertex-ai.php.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

/**
 * Request splitting must respect Vertex AI's 250-instance and 20,000-token limits.
 *
 * @covers ::ilsej_vertex_chunk_by_token_budget
 */
class VertexTokenBudgetTest extends TestCase {

	/**
	 * Build an instance whose content is a given number of characters.
	 *
	 * @param int $chars Content length.
	 * @return array<string, string>
	 */
	private function instance( int $chars ): array {
		return [
			'content'   => str_repeat( 'a', $chars ),
			'title'     => '',
			'task_type' => 'RETRIEVAL_DOCUMENT',
		];
	}

	/**
	 * Regression: 50 long extracts must not travel in one request.
	 *
	 * @return void
	 */
	public function test_long_documents_split_under_token_budget(): void {
		$instances = array_fill( 0, 50, $this->instance( 1500 ) );

		$requests = ilsej_vertex_chunk_by_token_budget( $instances );

		$this->assertGreaterThan( 1, count( $requests ) );
		foreach ( $requests as $request ) {
			$this->assertLessThanOrEqual( 30, count( $request ), '1500 chars = 500 est. tokens; 30 fit in 15,000.' );
		}
		$this->assertSame( 50, array_sum( array_map( 'count', $requests ) ) );
	}

	/**
	 * Short documents still batch up to the 250-instance cap.
	 *
	 * @return void
	 */
	public function test_short_documents_capped_at_250_per_request(): void {
		$instances = array_fill( 0, 300, $this->instance( 30 ) );

		$requests = ilsej_vertex_chunk_by_token_budget( $instances );

		$this->assertSame( [ 250, 50 ], array_map( 'count', $requests ) );
	}

	/**
	 * Order is preserved so embeddings line up with their posts.
	 *
	 * @return void
	 */
	public function test_order_preserved(): void {
		$instances = [];
		for ( $i = 0; $i < 40; $i++ ) {
			$instances[] = [ 'content' => "doc-$i " . str_repeat( 'x', 2000 ) ];
		}

		$flat = array_merge( ...ilsej_vertex_chunk_by_token_budget( $instances ) );

		$this->assertSame( $instances, $flat );
	}

	/**
	 * An oversized document goes alone rather than being dropped.
	 *
	 * @return void
	 */
	public function test_oversized_document_sent_alone(): void {
		$instances = [ $this->instance( 100 ), $this->instance( 60000 ), $this->instance( 100 ) ];

		$requests = ilsej_vertex_chunk_by_token_budget( $instances );

		$this->assertSame( [ 1, 1, 1 ], array_map( 'count', $requests ) );
	}

	/**
	 * Empty input yields no requests.
	 *
	 * @return void
	 */
	public function test_empty_input(): void {
		$this->assertSame( [], ilsej_vertex_chunk_by_token_budget( [] ) );
	}
}
