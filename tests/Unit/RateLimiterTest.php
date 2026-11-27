<?php
/**
 * Tests for the ILSEJ_Rate_Limiter class.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

use Brain\Monkey\Functions;
use ILSEJ_Rate_Limiter;

/**
 * Tests for rate limiting, status, and reset functionality.
 *
 * @covers ILSEJ_Rate_Limiter
 */
class RateLimiterTest extends TestCase {

	/**
	 * Rate limiter instance under test.
	 *
	 * @var ILSEJ_Rate_Limiter
	 */
	private $limiter;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->limiter = new ILSEJ_Rate_Limiter();
	}

	/**
	 * Test first call returns 0 (allowed).
	 *
	 * @return void
	 */
	public function test_first_call_returns_zero(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->once();

		$wait = $this->limiter->check_rate_limit( 'embedding_generation' );

		$this->assertSame( 0, $wait );
	}

	/**
	 * Test unknown operation always returns 0.
	 *
	 * @return void
	 */
	public function test_unknown_operation_returns_zero(): void {
		$wait = $this->limiter->check_rate_limit( 'nonexistent_operation' );

		$this->assertSame( 0, $wait );
	}

	/**
	 * Test exceeding limit returns wait time greater than 0.
	 *
	 * @return void
	 */
	public function test_exceeding_limit_returns_wait_time(): void {
		// Simulate a transient showing 60 calls (at limit) with reset in the future.
		Functions\when( 'get_transient' )->justReturn(
			[
				'count'    => 60,
				'reset_at' => time() + 30,
			]
		);

		$wait = $this->limiter->check_rate_limit( 'embedding_generation' );

		$this->assertGreaterThan( 0, $wait );
	}

	/**
	 * Test that expired window resets counter.
	 *
	 * @return void
	 */
	public function test_expired_window_resets_counter(): void {
		// Simulate limit reached but window has expired.
		Functions\when( 'get_transient' )->justReturn(
			[
				'count'    => 60,
				'reset_at' => time() - 5,
			]
		);
		Functions\expect( 'delete_transient' )->once();
		Functions\expect( 'set_transient' )->once();

		$wait = $this->limiter->check_rate_limit( 'embedding_generation' );

		$this->assertSame( 0, $wait );
	}

	/**
	 * Test get_status() returns correct structure for known operation.
	 *
	 * @return void
	 */
	public function test_get_status_returns_correct_structure(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'count'    => 5,
				'reset_at' => time() + 45,
			]
		);

		$status = $this->limiter->get_status( 'embedding_generation' );

		$this->assertIsArray( $status );
		$this->assertSame( 5, $status['count'] );
		$this->assertSame( 60, $status['limit'] );
		$this->assertGreaterThan( 0, $status['reset_in'] );
	}

	/**
	 * Test get_status() returns null for unknown operation.
	 *
	 * @return void
	 */
	public function test_get_status_unknown_operation_returns_null(): void {
		$this->assertNull( $this->limiter->get_status( 'nonexistent' ) );
	}

	/**
	 * Test get_status() with no transient data returns zero count.
	 *
	 * @return void
	 */
	public function test_get_status_no_data_returns_zero_count(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$status = $this->limiter->get_status( 'pinecone_query' );

		$this->assertSame( 0, $status['count'] );
		$this->assertSame( 100, $status['limit'] );
	}

	/**
	 * Test reset() calls delete_transient with the correct key.
	 *
	 * @return void
	 */
	public function test_reset_deletes_transient(): void {
		Functions\expect( 'delete_transient' )
			->once()
			->with( 'ilsej_rate_embedding_generation' );

		$this->limiter->reset( 'embedding_generation' );
	}

	/**
	 * Test reset_all() clears all operation counters.
	 *
	 * @return void
	 */
	public function test_reset_all_clears_all_counters(): void {
		Functions\expect( 'delete_transient' )->times( 3 );

		$this->limiter->reset_all();
	}
}
