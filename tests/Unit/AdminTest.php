<?php
/**
 * Tests for the ILSEJ_Admin class sanitize_settings method and
 * pure helper functions from google-vertex-ai.php.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

use Brain\Monkey\Functions;
use ILSEJ_Admin;

/**
 * Tests for settings sanitization and pure helper functions.
 *
 * @covers ILSEJ_Admin
 */
class AdminTest extends TestCase {

	/**
	 * Admin instance under test.
	 *
	 * @var ILSEJ_Admin
	 */
	private $admin;

	/**
	 * Set up each test.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		// Reset the ILSEJ_Admin singleton.
		$reflection    = new \ReflectionClass( ILSEJ_Admin::class );
		$instance_prop = $reflection->getProperty( 'instance' );
		$instance_prop->setValue( null, null );

		// Mock WordPress hooks the constructor registers.
		Functions\when( 'add_action' )->justReturn( true );

		$this->admin = ILSEJ_Admin::instance();
	}

	/**
	 * Tear down each test — reset singleton.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		$reflection    = new \ReflectionClass( ILSEJ_Admin::class );
		$instance_prop = $reflection->getProperty( 'instance' );
		$instance_prop->setValue( null, null );

		parent::tear_down();
	}

	/**
	 * Helper: call sanitize_settings with common mocks.
	 *
	 * Passes empty API keys by default to skip live validation blocks.
	 * Mocks WordPress functions needed for the sanitization path.
	 *
	 * @param array<string, mixed> $input Raw settings input.
	 * @return array<string, mixed> Sanitized output.
	 */
	private function call_sanitize( array $input ): array {
		// Stub transient/settings-error functions.
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'add_settings_error' )->justReturn( true );

		// Stub get_post_types to return common public types.
		Functions\when( 'get_post_types' )->justReturn(
			[
				'post'    => 'post',
				'page'    => 'page',
				'product' => 'product',
			]
		);

		// Stub wp_salt for ILSEJ_Security encryption.
		Functions\when( 'wp_salt' )->justReturn( 'test-salt-for-unit-tests' );

		// Stub get_option for preserving _v2_migrated flag.
		Functions\when( 'get_option' )->justReturn( [] );

		return $this->admin->sanitize_settings( $input );
	}

	/**
	 * Test batch_size is clamped to the minimum of 10.
	 *
	 * @return void
	 */
	public function test_batch_size_clamped_to_minimum(): void {
		$result = $this->call_sanitize( [ 'batch_size' => '5' ] );

		$this->assertSame( 10, $result['batch_size'] );
	}

	/**
	 * Test batch_size is clamped to the maximum of 100.
	 *
	 * @return void
	 */
	public function test_batch_size_clamped_to_maximum(): void {
		$result = $this->call_sanitize( [ 'batch_size' => '999' ] );

		$this->assertSame( 100, $result['batch_size'] );
	}

	/**
	 * Test negative batch_size is clamped to minimum.
	 *
	 * @return void
	 */
	public function test_batch_size_negative_clamped_to_minimum(): void {
		$result = $this->call_sanitize( [ 'batch_size' => '-10' ] );

		$this->assertSame( 10, $result['batch_size'] );
	}

	/**
	 * Test zero batch_size is clamped to minimum.
	 *
	 * @return void
	 */
	public function test_batch_size_zero_clamped_to_minimum(): void {
		$result = $this->call_sanitize( [ 'batch_size' => '0' ] );

		$this->assertSame( 10, $result['batch_size'] );
	}

	/**
	 * Test valid batch_size within range is preserved.
	 *
	 * @return void
	 */
	public function test_batch_size_valid_value_preserved(): void {
		$result = $this->call_sanitize( [ 'batch_size' => '50' ] );

		$this->assertSame( 50, $result['batch_size'] );
	}

	/**
	 * Test missing batch_size defaults to 50.
	 *
	 * @return void
	 */
	public function test_batch_size_missing_defaults_to_50(): void {
		$result = $this->call_sanitize( [] );

		$this->assertSame( 50, $result['batch_size'] );
	}

	/**
	 * Test post_types validated as array of strings against public post types.
	 *
	 * @return void
	 */
	public function test_post_types_validated_against_public_types(): void {
		$result = $this->call_sanitize(
			[ 'post_types' => [ 'post', 'page', 'fake_type' ] ]
		);

		$this->assertContains( 'post', $result['post_types'] );
		$this->assertContains( 'page', $result['post_types'] );
		$this->assertNotContains( 'fake_type', $result['post_types'] );
	}

	/**
	 * Test missing post_types defaults to ['post', 'page'].
	 *
	 * @return void
	 */
	public function test_post_types_missing_defaults_to_post_page(): void {
		$result = $this->call_sanitize( [] );

		$this->assertSame( [ 'post', 'page' ], $result['post_types'] );
	}

	/**
	 * Test non-array post_types input defaults to ['post', 'page'].
	 *
	 * @return void
	 */
	public function test_post_types_non_array_defaults(): void {
		$result = $this->call_sanitize( [ 'post_types' => 'post' ] );

		$this->assertSame( [ 'post', 'page' ], $result['post_types'] );
	}

	/**
	 * Test empty input returns defaults for all fields.
	 *
	 * @return void
	 */
	public function test_empty_input_returns_defaults(): void {
		$result = $this->call_sanitize( [] );

		$this->assertSame( 50, $result['batch_size'] );
		$this->assertSame( [ 'post', 'page' ], $result['post_types'] );
		$this->assertSame( '', $result['pinecone_api_key'] );
		$this->assertSame( '', $result['pinecone_host'] );
		$this->assertSame( '', $result['openai_api_key'] );
		$this->assertSame( '', $result['gcp_json_key'] );
		$this->assertFalse( $result['enable_retry'] );
		$this->assertFalse( $result['enable_rate_limiting'] );
		$this->assertFalse( $result['delete_data_on_uninstall'] );
	}

	/**
	 * Test min_relevance_score is clamped below 0.0.
	 *
	 * @return void
	 */
	public function test_min_relevance_score_clamped_below_zero(): void {
		$result = $this->call_sanitize( [ 'min_relevance_score' => '-0.5' ] );

		$this->assertSame( 0.0, $result['min_relevance_score'] );
	}

	/**
	 * Test min_relevance_score is clamped above 1.0.
	 *
	 * @return void
	 */
	public function test_min_relevance_score_clamped_above_one(): void {
		$result = $this->call_sanitize( [ 'min_relevance_score' => '1.5' ] );

		$this->assertSame( 1.0, $result['min_relevance_score'] );
	}

	/**
	 * Test missing min_relevance_score defaults to 0.60.
	 *
	 * @return void
	 */
	public function test_min_relevance_score_default(): void {
		$result = $this->call_sanitize( [] );

		$this->assertEqualsWithDelta( 0.60, $result['min_relevance_score'], 0.001 );
	}

	/**
	 * Test boolean settings are properly cast when enabled.
	 *
	 * @return void
	 */
	public function test_boolean_settings_enabled(): void {
		$result = $this->call_sanitize(
			[
				'enable_retry'             => '1',
				'enable_rate_limiting'     => '1',
				'enable_category_filter'   => '1',
				'enable_tag_filter'        => '1',
				'delete_data_on_uninstall' => '1',
			]
		);

		$this->assertTrue( $result['enable_retry'] );
		$this->assertTrue( $result['enable_rate_limiting'] );
		$this->assertTrue( $result['enable_category_filter'] );
		$this->assertTrue( $result['enable_tag_filter'] );
		$this->assertTrue( $result['delete_data_on_uninstall'] );
	}

	/**
	 * Test boolean settings are false when omitted.
	 *
	 * @return void
	 */
	public function test_boolean_settings_false_when_omitted(): void {
		$result = $this->call_sanitize( [] );

		$this->assertFalse( $result['enable_retry'] );
		$this->assertFalse( $result['enable_rate_limiting'] );
		$this->assertFalse( $result['enable_category_filter'] );
		$this->assertFalse( $result['enable_tag_filter'] );
		$this->assertFalse( $result['delete_data_on_uninstall'] );
	}

	/**
	 * Test API key fields are sanitized and encrypted when provided.
	 *
	 * Provides keys and stubs the HTTP functions the live validators need.
	 *
	 * @return void
	 */
	public function test_api_key_fields_encrypted(): void {
		// Stub transient/settings-error/option functions.
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'add_settings_error' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn(
			[
				'post' => 'post',
				'page' => 'page',
			]
		);
		Functions\when( 'wp_salt' )->justReturn( 'test-salt-for-unit-tests' );
		Functions\when( 'get_option' )->justReturn( [] );

		// Stub HTTP functions so live validation doesn't make real requests.
		// Return a successful response for all validators.
		Functions\when( 'wp_remote_get' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{}' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		// Stub transient functions used by Vertex AI token caching.
		Functions\when( 'get_transient' )->justReturn( false );

		$result = $this->admin->sanitize_settings(
			[
				'pinecone_api_key'  => 'pcsk_test123',
				'pinecone_host'     => 'my-index.svc.pinecone.io',
				'openai_api_key'    => 'sk-test456',
				'anthropic_api_key' => 'sk-ant-test789',
				'gcp_json_key'      => '{"type":"service_account"}',
			]
		);
		$this->assertStringStartsWith( 'encrypted:', $result['anthropic_api_key'] );

		// Keys get encrypted, so they should have the 'encrypted:' prefix.
		$this->assertStringStartsWith( 'encrypted:', $result['pinecone_api_key'] );
		$this->assertStringStartsWith( 'encrypted:', $result['openai_api_key'] );
		$this->assertStringStartsWith( 'encrypted:', $result['gcp_json_key'] );

		// Host is not encrypted.
		$this->assertSame( 'my-index.svc.pinecone.io', $result['pinecone_host'] );
	}

	/**
	 * Test _v2_migrated flag is preserved from existing settings.
	 *
	 * @return void
	 */
	public function test_v2_migrated_flag_preserved(): void {
		// Stub functions with _v2_migrated in existing settings.
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'add_settings_error' )->justReturn( true );
		Functions\when( 'get_post_types' )->justReturn(
			[
				'post' => 'post',
				'page' => 'page',
			]
		);
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'get_option' )->justReturn( [ '_v2_migrated' => true ] );

		$result = $this->admin->sanitize_settings( [] );

		$this->assertTrue( $result['_v2_migrated'] );
	}

	/**
	 * Test batch_size boundary value at minimum (10).
	 *
	 * @return void
	 */
	public function test_batch_size_at_minimum_boundary(): void {
		$result = $this->call_sanitize( [ 'batch_size' => '10' ] );

		$this->assertSame( 10, $result['batch_size'] );
	}

	/**
	 * Test batch_size boundary value at maximum (100).
	 *
	 * @return void
	 */
	public function test_batch_size_at_maximum_boundary(): void {
		$result = $this->call_sanitize( [ 'batch_size' => '100' ] );

		$this->assertSame( 100, $result['batch_size'] );
	}

	// =========================================================================
	// Pure helper function tests: ilsej_normalize_text_for_embedding().
	// =========================================================================

	/**
	 * Test normalize strips HTML tags.
	 *
	 * @return void
	 */
	public function test_normalize_text_strips_html(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$result = ilsej_normalize_text_for_embedding( '<p>Hello <strong>world</strong></p>' );

		$this->assertSame( 'Hello world', $result );
	}

	/**
	 * Test normalize collapses whitespace.
	 *
	 * @return void
	 */
	public function test_normalize_text_collapses_whitespace(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$result = ilsej_normalize_text_for_embedding( "Hello   \n\n  world   " );

		$this->assertSame( 'Hello world', $result );
	}

	/**
	 * Test normalize returns empty string for non-string input.
	 *
	 * @return void
	 */
	public function test_normalize_text_non_string_returns_empty(): void {
		$this->assertSame( '', ilsej_normalize_text_for_embedding( 123 ) );
		$this->assertSame( '', ilsej_normalize_text_for_embedding( null ) );
		$this->assertSame( '', ilsej_normalize_text_for_embedding( [] ) );
	}

	/**
	 * Test normalize decodes HTML entities.
	 *
	 * @return void
	 */
	public function test_normalize_text_decodes_entities(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$result = ilsej_normalize_text_for_embedding( 'Tom &amp; Jerry&#8217;s show' );

		$this->assertSame( "Tom & Jerry\u{2019}s show", $result );
	}

	// =========================================================================
	// Pure helper function tests: ilsej_b64url().
	// =========================================================================

	/**
	 * Test base64url encoding produces URL-safe output.
	 *
	 * @return void
	 */
	public function test_b64url_encodes_correctly(): void {
		$result = ilsej_b64url( 'test' );

		$this->assertSame( 'dGVzdA', $result );
		$this->assertStringNotContainsString( '=', $result );
		$this->assertStringNotContainsString( '+', $result );
		$this->assertStringNotContainsString( '/', $result );
	}

	/**
	 * Test base64url with characters that produce + and / in standard base64.
	 *
	 * @return void
	 */
	public function test_b64url_replaces_plus_and_slash(): void {
		// Input chosen to produce + and / in standard base64.
		$input  = chr( 0xFB ) . chr( 0xFF ) . chr( 0xBF );
		$result = ilsej_b64url( $input );

		$this->assertStringNotContainsString( '+', $result );
		$this->assertStringNotContainsString( '/', $result );
	}

	/**
	 * Test base64url returns empty string for non-string input.
	 *
	 * @return void
	 */
	public function test_b64url_non_string_returns_empty(): void {
		$this->assertSame( '', ilsej_b64url( 123 ) );
		$this->assertSame( '', ilsej_b64url( null ) );
	}

	/**
	 * Test base64url with empty string.
	 *
	 * @return void
	 */
	public function test_b64url_empty_string(): void {
		$this->assertSame( '', ilsej_b64url( '' ) );
	}

	/**
	 * Provider fields default to Claude primary, OpenAI fallback, Opus model.
	 *
	 * @return void
	 */
	public function test_extraction_provider_fields_default_when_missing(): void {
		$result = $this->call_sanitize( [] );

		$this->assertSame( 'claude', $result['extraction_primary'] );
		$this->assertSame( 'openai', $result['extraction_fallback'] );
		$this->assertSame( 'claude-opus-5-5', $result['claude_model'] );
		$this->assertSame( '', $result['anthropic_api_key'] );
	}

	/**
	 * A chosen provider pair and model are saved as chosen.
	 *
	 * @return void
	 */
	public function test_extraction_provider_fields_are_saved(): void {
		$result = $this->call_sanitize(
			[
				'extraction_primary'  => 'openai',
				'extraction_fallback' => 'claude',
				'claude_model'        => 'claude-haiku-5-5',
			]
		);

		$this->assertSame( 'openai', $result['extraction_primary'] );
		$this->assertSame( 'claude', $result['extraction_fallback'] );
		$this->assertSame( 'claude-haiku-5-5', $result['claude_model'] );
	}

	/**
	 * A fallback equal to the primary becomes 'none'; unknown values become defaults.
	 *
	 * @return void
	 */
	public function test_extraction_provider_fields_are_normalized(): void {
		$same = $this->call_sanitize(
			[
				'extraction_primary'  => 'claude',
				'extraction_fallback' => 'claude',
			]
		);
		$this->assertSame( 'none', $same['extraction_fallback'] );

		$bogus = $this->call_sanitize(
			[
				'extraction_primary'  => 'gemini',
				'extraction_fallback' => '<script>',
				'claude_model'        => 'claude-2',
			]
		);
		$this->assertSame( 'claude', $bogus['extraction_primary'] );
		$this->assertSame( 'openai', $bogus['extraction_fallback'] );
		$this->assertSame( 'claude-opus-5-5', $bogus['claude_model'] );
	}

	/**
	 * The Anthropic key is stripped of invisible characters like the other keys.
	 *
	 * @return void
	 */
	public function test_anthropic_key_strips_invisible_characters(): void {
		Functions\when( 'wp_remote_get' )->justReturn( [ 'response' => [ 'code' => 200 ] ] );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$result = $this->call_sanitize( [ 'anthropic_api_key' => "\u{200B}sk-ant-abc\u{FEFF}" ] );

		$security = new \ILSEJ_Security();
		$this->assertSame( 'sk-ant-abc', $security->decrypt_api_key( $result['anthropic_api_key'] ) );
	}
}
