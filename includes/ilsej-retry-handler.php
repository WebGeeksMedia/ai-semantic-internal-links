<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Exponential backoff retry logic for API calls.
 *
 * Wraps API calls with automatic retry on transient failures.
 * Uses exponential backoff (1s, 2s, 4s) with max 3 attempts
 * to stay within AJAX timeout limits (~7s total sleep).
 *
 * @package AISemanticInternalLinks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ILSEJ_Retry_Handler
 *
 * Wraps API calls with exponential backoff retry logic.
 */
class ILSEJ_Retry_Handler {

	/**
	 * Maximum number of retry attempts.
	 *
	 * Default 3 to keep total sleep under 7s (1+2+4) and avoid
	 * exceeding PHP max_execution_time during AJAX requests.
	 *
	 * @var int
	 */
	private $max_retries = 3;

	/**
	 * Initial delay in seconds before first retry.
	 *
	 * @var int
	 */
	private $initial_delay = 1;

	/**
	 * Execute an API call with automatic retry on failure.
	 *
	 * @param callable           $api_call    The API call to execute.
	 * @param array<int, string> $error_types Optional list of error message substrings to retry on.
	 *                              If empty, retries on all exceptions.
	 * @return mixed The result of the API call.
	 * @throws Exception If all retry attempts fail.
	 */
	public function execute_with_retry( callable $api_call, $error_types = [] ) {
		$last_exception = null;

		for ( $attempt = 1; $attempt <= $this->max_retries; $attempt++ ) {
			try {
				$result = $api_call();

				if ( $attempt > 1 ) {
					error_log( "ILSEJ Retry: Success on attempt {$attempt}/{$this->max_retries}." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}

				return $result;

			} catch ( Exception $e ) {
				$last_exception = $e;

				// If error types are specified, only retry on matching errors.
				if ( ! empty( $error_types ) && ! $this->should_retry( $e, $error_types ) ) {
					throw $e;
				}

				// Final attempt failed, throw the exception.
				if ( $attempt === $this->max_retries ) {
					error_log( "ILSEJ Retry: Failed after {$this->max_retries} attempts: " . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.Security.EscapeOutput.ExceptionNotEscaped
					throw $e;
				}

				// Exponential backoff: 1s, 2s, 4s.
				$delay = $this->initial_delay * pow( 2, $attempt - 1 );

				error_log( "ILSEJ Retry: Attempt {$attempt} failed. Retrying in {$delay}s..." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sleep( $delay );
			}
		}

		// Unreachable when max_retries >= 1 (loop always returns or throws).
		// Guard satisfies PHPStan level 8 since $last_exception starts as null.
		if ( $last_exception instanceof Exception ) {
			throw $last_exception;
		}
		throw new Exception( 'ILSEJ Retry: No attempts were made.' );
	}

	/**
	 * Check if an exception matches the retryable error types.
	 *
	 * @param Exception          $exception   The caught exception.
	 * @param array<int, string> $error_types List of error message substrings to match.
	 * @return bool True if the exception should trigger a retry.
	 */
	private function should_retry( Exception $exception, $error_types ) {
		$message = strtolower( $exception->getMessage() );

		foreach ( $error_types as $type ) {
			if ( false !== strpos( $message, strtolower( $type ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Set the maximum number of retries.
	 *
	 * @param int $max Maximum retries (clamped to 1-10).
	 * @return void
	 */
	public function set_max_retries( $max ) {
		$this->max_retries = max( 1, min( 10, (int) $max ) );
	}

	/**
	 * Set the initial delay before first retry.
	 *
	 * @param int $delay Delay in seconds (clamped to 1-60).
	 * @return void
	 */
	public function set_initial_delay( $delay ) {
		$this->initial_delay = max( 1, min( 60, (int) $delay ) );
	}
}
