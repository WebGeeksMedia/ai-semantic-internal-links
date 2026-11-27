<?php
/**
 * Tests for the hourly budget on logged-out vector searches.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Tests ilsej_public_search_budget_allows() and its gate in ilsej_get_embeddings().
 *
 * @covers ::ilsej_public_search_budget_allows
 */
class PublicSearchBudgetTest extends TestCase {

	/**
	 * Transient store standing in for the options table.
	 *
	 * @var array<string, mixed>
	 */
	private $transients = [];

	/**
	 * Temporary PHP error log, so a test can count the lines written.
	 *
	 * @var string
	 */
	private $log_file = '';

	/**
	 * PHP's error_log setting before this test.
	 *
	 * @var string
	 */
	private $previous_log = '';

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}

		$this->transients = [];
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);

		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'ilsej' );
		$this->previous_log = (string) ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- capture this test's log lines.
	}

	/**
	 * Remove the temporary log.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		ini_set( 'error_log', $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restore the setting.
		unlink( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- no WordPress in unit tests.
		parent::tear_down();
	}

	/**
	 * The transient key for the current hour.
	 *
	 * @return string
	 */
	private function key(): string {
		return 'ilsej_public_search_' . gmdate( 'YmdH' );
	}

	/**
	 * A spent budget refuses, and a flood of refusals writes one log line and stops writing.
	 *
	 * @return void
	 */
	public function test_spent_budget_refuses_and_logs_once(): void {
		$this->transients[ $this->key() ] = ILSEJ_PUBLIC_SEARCH_HOURLY_BUDGET;

		$this->assertFalse( ilsej_public_search_budget_allows() );
		$this->assertFalse( ilsej_public_search_budget_allows() );
		$this->assertFalse( ilsej_public_search_budget_allows() );

		$this->assertSame( ILSEJ_PUBLIC_SEARCH_HOURLY_BUDGET + 1, $this->transients[ $this->key() ] );
		$log = (string) file_get_contents( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
		$this->assertSame( 1, substr_count( $log, 'public search budget' ) );
	}

	/**
	 * A refused search makes no paid call and returns the function's existing error value.
	 *
	 * @return void
	 */
	public function test_get_embeddings_returns_false_without_calling_out(): void {
		$this->transients[ $this->key() ] = ILSEJ_PUBLIC_SEARCH_HOURLY_BUDGET + 1;
		Functions\expect( 'get_option' )->never();
		Functions\expect( 'wp_remote_post' )->never();

		$this->assertFalse( ilsej_get_embeddings( 'bitcoin etf flows' ) );
	}

	/**
	 * Calls under the budget are allowed and counted, up to exactly the budget.
	 *
	 * @return void
	 */
	public function test_counts_up_to_the_budget(): void {
		Filters\expectApplied( 'ilsej_public_search_hourly_budget' )->andReturn( 2 );

		$this->assertTrue( ilsej_public_search_budget_allows() );
		$this->assertSame( 1, $this->transients[ $this->key() ] );
		$this->assertTrue( ilsej_public_search_budget_allows() );
		$this->assertFalse( ilsej_public_search_budget_allows() );
	}

	/**
	 * A count from an earlier hour does not carry over.
	 *
	 * @return void
	 */
	public function test_new_hour_starts_a_new_count(): void {
		$this->transients[ 'ilsej_public_search_' . gmdate( 'YmdH', time() - HOUR_IN_SECONDS ) ] = ILSEJ_PUBLIC_SEARCH_HOURLY_BUDGET + 1;

		$this->assertTrue( ilsej_public_search_budget_allows() );
		$this->assertSame( 1, $this->transients[ $this->key() ] );
	}

	/**
	 * Logged-in users pass a spent budget and do not spend from it.
	 *
	 * @return void
	 */
	public function test_logged_in_user_is_not_counted_or_blocked(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$this->transients[ $this->key() ] = ILSEJ_PUBLIC_SEARCH_HOURLY_BUDGET + 1;

		$this->assertTrue( ilsej_public_search_budget_allows() );
		$this->assertSame( ILSEJ_PUBLIC_SEARCH_HOURLY_BUDGET + 1, $this->transients[ $this->key() ] );
	}

	/**
	 * A budget of zero stops logged-out searches outright.
	 *
	 * @return void
	 */
	public function test_zero_budget_refuses_everything(): void {
		Filters\expectApplied( 'ilsej_public_search_hourly_budget' )->andReturn( 0 );

		$this->assertFalse( ilsej_public_search_budget_allows() );
	}
}
