<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Rate limiting using WordPress transients.
 *
 * Prevents exceeding API rate limits for external services by tracking
 * call counts per operation. Returns wait time when limits are reached
 * instead of blocking with sleep(), allowing callers to handle delays
 * appropriately (e.g., client-side retry for AJAX, sleep for cron).
 *
 * @package AISemanticInternalLinks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ILSEJ_Rate_Limiter
 *
 * Transient-based rate limiter for external API operations.
 */
class ILSEJ_Rate_Limiter {

	/**
	 * Rate limits per operation.
	 *
	 * @var array<string, array<string, int>>
	 */
	private $limits = [
		'embedding_generation' => [
			'calls'  => 60,
			'period' => 60,
		],
		'pinecone_query'       => [
			'calls'  => 100,
			'period' => 60,
		],
		'pinecone_upsert'      => [
			'calls'  => 50,
			'period' => 60,
		],
	];

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	private $prefix = 'ilsej_rate_';

	/**
	 * Check rate limit for an operation (non-blocking).
	 *
	 * Returns 0 if the operation is allowed, or the number of seconds
	 * to wait before retrying. Callers decide how to handle the wait
	 * (e.g., return retry-after to JS, or sleep in cron context).
	 *
	 * @param string $operation The operation name (e.g., 'embedding_generation').
	 * @return int Seconds to wait (0 = allowed to proceed).
	 */
	public function check_rate_limit( $operation ) {
		if ( ! isset( $this->limits[ $operation ] ) ) {
			return 0;
		}

		$limit = $this->limits[ $operation ];
		$key   = $this->prefix . $operation;
		$data  = get_transient( $key );

		if ( ! $data || ! is_array( $data ) ) {
			$data = [
				'count'    => 0,
				'reset_at' => time() + $limit['period'],
			];
		}

		// If limit reached, return wait time instead of sleeping.
		if ( $data['count'] >= $limit['calls'] ) {
			$wait_time = max( 0, $data['reset_at'] - time() );

			if ( $wait_time > 0 ) {
				error_log( "ILSEJ Rate Limit: {$operation} reached ({$data['count']}/{$limit['calls']}). Wait {$wait_time}s." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return (int) $wait_time;
			}

			// Window has expired, reset counter.
			delete_transient( $key );
			$data = [
				'count'    => 0,
				'reset_at' => time() + $limit['period'],
			];
		}

		++$data['count'];
		set_transient( $key, $data, $limit['period'] );

		return 0;
	}

	/**
	 * Get the current status for an operation.
	 *
	 * @param string $operation The operation name.
	 * @return array<string, int>|null Status array with 'count', 'limit', 'reset_in', or null if unknown operation.
	 */
	public function get_status( $operation ) {
		$limit = isset( $this->limits[ $operation ] ) ? $this->limits[ $operation ] : null;
		if ( ! $limit ) {
			return null;
		}

		$data = get_transient( $this->prefix . $operation );

		return [
			'count'    => $data && isset( $data['count'] ) ? (int) $data['count'] : 0,
			'limit'    => (int) $limit['calls'],
			'reset_in' => $data && isset( $data['reset_at'] ) ? (int) max( 0, (int) $data['reset_at'] - time() ) : (int) $limit['period'],
		];
	}

	/**
	 * Reset the counter for a specific operation.
	 *
	 * @param string $operation The operation name to reset.
	 * @return void
	 */
	public function reset( $operation ) {
		delete_transient( $this->prefix . $operation );
	}

	/**
	 * Reset all rate limit counters.
	 *
	 * @return void
	 */
	public function reset_all() {
		foreach ( array_keys( $this->limits ) as $operation ) {
			$this->reset( $operation );
		}
	}
}
