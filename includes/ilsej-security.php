<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * API Key Encryption/Decryption using WordPress salts.
 *
 * Provides AES-256-CBC encryption for sensitive API credentials stored
 * in the WordPress database. Uses WordPress auth salt as the encryption
 * key and a random IV for each encryption operation.
 *
 * Gracefully degrades to plaintext storage if OpenSSL is unavailable
 * or if encryption/decryption fails for any reason.
 *
 * @package AISemanticInternalLinks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ILSEJ_Security
 *
 * Handles AES-256-CBC encryption and decryption of API credentials.
 */
class ILSEJ_Security {

	/**
	 * Encryption cipher method.
	 *
	 * @var string
	 */
	private $cipher = 'AES-256-CBC';

	/**
	 * Prefix for encrypted strings to identify them.
	 *
	 * @var string
	 */
	private $prefix = 'encrypted:';

	/**
	 * Check if OpenSSL functions are available.
	 *
	 * @return bool True if OpenSSL is available.
	 */
	private function has_openssl() {
		return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
	}

	/**
	 * Encrypt an API key or sensitive string.
	 *
	 * Returns the original plaintext if OpenSSL is unavailable or encryption
	 * fails, ensuring API keys are never silently lost.
	 *
	 * @param string $plaintext The plaintext string to encrypt.
	 * @return string The encrypted string with 'encrypted:' prefix, or original plaintext on failure.
	 */
	public function encrypt_api_key( $plaintext ) {
		if ( empty( $plaintext ) ) {
			return '';
		}

		// Already encrypted, return as-is.
		if ( $this->is_encrypted( $plaintext ) ) {
			return $plaintext;
		}

		// Warn admin and fall back to plaintext if OpenSSL is unavailable.
		if ( ! $this->has_openssl() ) {
			error_log( 'ILSEJ Security: OpenSSL not available. Storing key as plaintext.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			add_action(
				'admin_notices',
				static function () {
					if ( ! current_user_can( 'manage_options' ) ) {
						return;
					}
					echo '<div class="notice notice-error"><p><strong>AI Semantic Internal Links:</strong> OpenSSL is not available. API keys are stored unencrypted. Contact your host to enable the OpenSSL PHP extension.</p></div>';
				}
			);
			return $plaintext;
		}

		$salt    = wp_salt( 'auth' );
		$iv_size = openssl_cipher_iv_length( $this->cipher );
		if ( false === $iv_size ) {
			error_log( 'ILSEJ Security: Could not determine IV length. Preserving plaintext key.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $plaintext;
		}

		$iv        = openssl_random_pseudo_bytes( $iv_size );
		$encrypted = openssl_encrypt( $plaintext, $this->cipher, $salt, 0, $iv );

		if ( false === $encrypted ) {
			error_log( 'ILSEJ Security: Encryption failed. Preserving plaintext key.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $plaintext;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for binary IV storage.
		return $this->prefix . base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypt an encrypted API key.
	 *
	 * Returns the raw input if decryption fails (e.g., after salt rotation),
	 * allowing the plugin to continue working with plaintext keys. Users
	 * can re-save settings to re-encrypt with the new salt.
	 *
	 * @param string $encrypted The encrypted string with 'encrypted:' prefix.
	 * @return string|false The decrypted plaintext, or false on failure.
	 */
	public function decrypt_api_key( $encrypted ) {
		if ( empty( $encrypted ) ) {
			return '';
		}

		// Not encrypted, return as-is (supports plaintext keys).
		if ( ! $this->is_encrypted( $encrypted ) ) {
			return $encrypted;
		}

		// OpenSSL unavailable — cannot decrypt.
		if ( ! $this->has_openssl() ) {
			error_log( 'ILSEJ Security: OpenSSL not available. Cannot decrypt key.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$data = str_replace( $this->prefix, '', $encrypted );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for binary IV extraction.
		$combined = base64_decode( $data );

		if ( false === $combined ) { // @phpstan-ignore identical.alwaysFalse
			error_log( 'ILSEJ Security: base64_decode failed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$iv_size = openssl_cipher_iv_length( $this->cipher );
		if ( false === $iv_size ) {
			error_log( 'ILSEJ Security: Could not determine IV length for decryption.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		$iv             = substr( $combined, 0, $iv_size );
		$encrypted_data = substr( $combined, $iv_size );
		$salt           = wp_salt( 'auth' );

		$decrypted = openssl_decrypt( $encrypted_data, $this->cipher, $salt, 0, $iv );

		if ( false === $decrypted ) {
			error_log( 'ILSEJ Security: Decryption failed (salt may have changed). Re-save settings to re-encrypt.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}

		return $decrypted;
	}

	/**
	 * Check if a string is already encrypted.
	 *
	 * @param mixed $string The string to check.
	 * @return bool True if encrypted (has prefix), false otherwise.
	 */
	public function is_encrypted( $string ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.stringFound
		return is_string( $string ) && 0 === strpos( $string, $this->prefix );
	}

	/**
	 * Test encryption roundtrip.
	 *
	 * @return bool True if encrypt/decrypt roundtrip succeeds.
	 */
	public function test_encryption() {
		if ( ! $this->has_openssl() ) {
			return false;
		}

		$test_string = 'ilsej_encryption_test_' . wp_rand();
		$encrypted   = $this->encrypt_api_key( $test_string );
		$decrypted   = $this->decrypt_api_key( $encrypted );

		return $test_string === $decrypted;
	}
}
