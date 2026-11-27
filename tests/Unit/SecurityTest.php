<?php
/**
 * Tests for the ILSEJ_Security class.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

use Brain\Monkey\Functions;
use ILSEJ_Security;

/**
 * Tests for encryption, decryption, and prefix detection.
 *
 * @covers ILSEJ_Security
 */
class SecurityTest extends TestCase {

	/**
	 * Security instance under test.
	 *
	 * @var ILSEJ_Security
	 */
	private $security;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		// Mock wp_salt() to return a deterministic key for testing.
		Functions\when( 'wp_salt' )->justReturn( 'test-auth-salt-key-for-unit-tests' );

		$this->security = new ILSEJ_Security();
	}

	/**
	 * Test encrypt/decrypt roundtrip produces the original plaintext.
	 *
	 * @return void
	 */
	public function test_encrypt_decrypt_roundtrip(): void {
		$plaintext = 'sk-my-secret-api-key-12345';

		$encrypted = $this->security->encrypt_api_key( $plaintext );
		$decrypted = $this->security->decrypt_api_key( $encrypted );

		$this->assertSame( $plaintext, $decrypted );
		$this->assertNotSame( $plaintext, $encrypted );
		$this->assertStringStartsWith( 'encrypted:', $encrypted );
	}

	/**
	 * Test encrypting an empty string returns empty string.
	 *
	 * @return void
	 */
	public function test_encrypt_empty_string_returns_empty(): void {
		$this->assertSame( '', $this->security->encrypt_api_key( '' ) );
	}

	/**
	 * Test decrypting an empty string returns empty string.
	 *
	 * @return void
	 */
	public function test_decrypt_empty_string_returns_empty(): void {
		$this->assertSame( '', $this->security->decrypt_api_key( '' ) );
	}

	/**
	 * Test is_encrypted() detects the 'encrypted:' prefix.
	 *
	 * @return void
	 */
	public function test_is_encrypted_with_prefix(): void {
		$this->assertTrue( $this->security->is_encrypted( 'encrypted:abc123' ) );
	}

	/**
	 * Test is_encrypted() returns false for plaintext.
	 *
	 * @return void
	 */
	public function test_is_encrypted_with_plaintext(): void {
		$this->assertFalse( $this->security->is_encrypted( 'sk-my-plaintext-key' ) );
	}

	/**
	 * Test is_encrypted() returns false for non-string input.
	 *
	 * @return void
	 */
	public function test_is_encrypted_with_non_string(): void {
		$this->assertFalse( $this->security->is_encrypted( 12345 ) );
		$this->assertFalse( $this->security->is_encrypted( null ) );
	}

	/**
	 * Test encrypting an already-encrypted value returns it unchanged.
	 *
	 * @return void
	 */
	public function test_encrypt_already_encrypted_returns_unchanged(): void {
		$plaintext = 'my-api-key';
		$encrypted = $this->security->encrypt_api_key( $plaintext );

		// Encrypting again should return the same encrypted string.
		$double_encrypted = $this->security->encrypt_api_key( $encrypted );
		$this->assertSame( $encrypted, $double_encrypted );
	}

	/**
	 * Test decrypting a plaintext value returns it as-is (passthrough).
	 *
	 * @return void
	 */
	public function test_decrypt_plaintext_returns_unchanged(): void {
		$plaintext = 'sk-plaintext-key';

		$this->assertSame( $plaintext, $this->security->decrypt_api_key( $plaintext ) );
	}

	/**
	 * Test test_encryption() returns true when OpenSSL is available.
	 *
	 * @return void
	 */
	public function test_test_encryption_returns_true(): void {
		Functions\when( 'wp_rand' )->justReturn( 42 );

		$this->assertTrue( $this->security->test_encryption() );
	}
}
