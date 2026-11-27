<?php
/**
 * Tests for the ILSEJ_Retry_Handler class.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

use ILSEJ_Retry_Handler;

/**
 * Tests for retry logic, backoff, and configuration.
 *
 * @covers ILSEJ_Retry_Handler
 */
class RetryHandlerTest extends TestCase {

	/**
	 * Retry handler instance under test.
	 *
	 * @var ILSEJ_Retry_Handler
	 */
	private $handler;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$this->handler = new ILSEJ_Retry_Handler();
	}

	/**
	 * Test successful execution returns result on first attempt.
	 *
	 * @return void
	 */
	public function test_successful_execution_returns_result(): void {
		$result = $this->handler->execute_with_retry(
			function () {
				return 'success';
			}
		);

		$this->assertSame( 'success', $result );
	}

	/**
	 * Test that retries happen on exception and succeed on second attempt.
	 *
	 * Note: This test sleeps ~1s due to the retry handler's built-in sleep().
	 *
	 * @return void
	 */
	public function test_retries_on_exception_then_succeeds(): void {
		$attempt = 0;

		$result = $this->handler->execute_with_retry(
			function () use ( &$attempt ) {
				++$attempt;
				if ( $attempt < 2 ) {
					throw new \Exception( 'Transient error' );
				}
				return 'recovered';
			}
		);

		$this->assertSame( 'recovered', $result );
		$this->assertSame( 2, $attempt );
	}

	/**
	 * Test that exception is thrown after max retries exceeded.
	 *
	 * Uses set_max_retries(1) to avoid sleep delay.
	 *
	 * @return void
	 */
	public function test_throws_after_max_retries(): void {
		$this->handler->set_max_retries( 1 );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Persistent failure' );

		$this->handler->execute_with_retry(
			function () {
				throw new \Exception( 'Persistent failure' );
			}
		);
	}

	/**
	 * Test that non-matching error types are not retried.
	 *
	 * @return void
	 */
	public function test_non_matching_error_throws_immediately(): void {
		$attempt = 0;

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Permission denied' );

		$this->handler->execute_with_retry(
			function () use ( &$attempt ) {
				++$attempt;
				throw new \Exception( 'Permission denied' );
			},
			[ 'timeout', 'rate limit' ]
		);

		$this->assertSame( 1, $attempt );
	}

	/**
	 * Test that matching error types trigger retry.
	 *
	 * Note: This test sleeps ~1s due to the retry handler's built-in sleep().
	 *
	 * @return void
	 */
	public function test_matching_error_type_triggers_retry(): void {
		$attempt = 0;

		$result = $this->handler->execute_with_retry(
			function () use ( &$attempt ) {
				++$attempt;
				if ( $attempt < 2 ) {
					throw new \Exception( 'API rate limit exceeded' );
				}
				return 'done';
			},
			[ 'rate limit' ]
		);

		$this->assertSame( 'done', $result );
		$this->assertSame( 2, $attempt );
	}

	/**
	 * Test set_max_retries() clamps to minimum 1.
	 *
	 * @return void
	 */
	public function test_set_max_retries_clamps_minimum(): void {
		$this->handler->set_max_retries( 0 );

		// With max_retries=1, a single failure should throw immediately.
		$this->expectException( \Exception::class );

		$this->handler->execute_with_retry(
			function () {
				throw new \Exception( 'fail' );
			}
		);
	}

	/**
	 * Test set_max_retries() clamps to maximum 10.
	 *
	 * @return void
	 */
	public function test_set_max_retries_clamps_maximum(): void {
		$this->handler->set_max_retries( 100 );

		// Verify the value is clamped via Reflection.
		$reflection = new \ReflectionProperty( ILSEJ_Retry_Handler::class, 'max_retries' );
		$this->assertSame( 10, $reflection->getValue( $this->handler ) );
	}

	/**
	 * Test set_initial_delay() clamps to minimum 1.
	 *
	 * @return void
	 */
	public function test_set_initial_delay_clamps_minimum(): void {
		$this->handler->set_initial_delay( 0 );

		$reflection = new \ReflectionProperty( ILSEJ_Retry_Handler::class, 'initial_delay' );
		$this->assertSame( 1, $reflection->getValue( $this->handler ) );
	}

	/**
	 * Test set_initial_delay() clamps to maximum 60.
	 *
	 * @return void
	 */
	public function test_set_initial_delay_clamps_maximum(): void {
		$this->handler->set_initial_delay( 120 );

		$reflection = new \ReflectionProperty( ILSEJ_Retry_Handler::class, 'initial_delay' );
		$this->assertSame( 60, $reflection->getValue( $this->handler ) );
	}
}
