<?php
/**
 * Tests for the banned-words filter inside ilsej_extract_entities() (the
 * fast gpt-5.2 path). Previously only the slow response-API path enforced
 * banned_words; banned anchors leaked through whenever the main path ran.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

use Brain\Monkey\Functions;
use ILSEJ_Core;

/**
 * Test suite for the banned-words filter in the fast entity-extraction path.
 *
 * @covers ::ilsej_extract_entities
 */
class EntityExtractionFilterTest extends TestCase {

	/**
	 * Set up — pass-through apply_filters and stub WP HTTP-related helpers.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		// Approximates wp_kses() for ilsej_prepare_extraction_content(): honours the
		// tag allowlist, ignores the attribute stripping these tests do not assert on.
		Functions\when( 'wp_kses' )->alias(
			static function ( $content, $allowed_html ) {
				return strip_tags( $content, array_keys( (array) $allowed_html ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			}
		);
	}

	/**
	 * With banned_words = ['casino'], entities containing 'casino' are dropped.
	 */
	public function test_banned_word_filters_matching_phrase(): void {
		$this->stub_settings( [ 'banned_words' => [ 'casino' ] ] );
		$this->stub_openai_response(
			[
				[
					'phrase'  => 'casino bonuses',
					'context' => 'crypto casino bonuses are popular',
				],
				[
					'phrase'  => 'mining rigs',
					'context' => 'mining rigs power blockchains',
				],
			]
		);

		$entities = ilsej_extract_entities( 'irrelevant content' );

		$this->assertCount( 1, $entities );
		$this->assertSame( 'mining rigs', $entities[0]['phrase'] );
	}

	/**
	 * Empty banned_words list keeps every entity.
	 */
	public function test_empty_banned_words_keeps_all_entities(): void {
		$this->stub_settings( [ 'banned_words' => [] ] );
		$this->stub_openai_response(
			[
				[
					'phrase'  => 'casino bonuses',
					'context' => 'crypto casino bonuses are popular',
				],
				[
					'phrase'  => 'mining rigs',
					'context' => 'mining rigs power blockchains',
				],
			]
		);

		$entities = ilsej_extract_entities( 'irrelevant content' );

		$this->assertCount( 2, $entities );
	}

	/**
	 * Missing banned_words key behaves identically to empty list.
	 */
	public function test_missing_banned_words_setting_keeps_all_entities(): void {
		$this->stub_settings( [] );
		$this->stub_openai_response(
			[
				[
					'phrase'  => 'casino bonuses',
					'context' => 'crypto casino bonuses are popular',
				],
			]
		);

		$entities = ilsej_extract_entities( 'irrelevant content' );

		$this->assertCount( 1, $entities );
	}

	/**
	 * Banned-word match is case-insensitive (stripos semantics).
	 */
	public function test_banned_word_match_is_case_insensitive(): void {
		$this->stub_settings( [ 'banned_words' => [ 'CASINO' ] ] );
		$this->stub_openai_response(
			[
				[
					'phrase'  => 'crypto casino guide',
					'context' => 'guide for crypto casinos',
				],
			]
		);

		$entities = ilsej_extract_entities( 'irrelevant content' );

		$this->assertSame( [], $entities );
	}

	/**
	 * Empty-string banned word is ignored (would otherwise match everything).
	 */
	public function test_empty_string_banned_word_is_ignored(): void {
		$this->stub_settings( [ 'banned_words' => [ '' ] ] );
		$this->stub_openai_response(
			[
				[
					'phrase'  => 'mining rigs',
					'context' => 'mining rigs power blockchains',
				],
			]
		);

		$entities = ilsej_extract_entities( 'irrelevant content' );

		$this->assertCount( 1, $entities );
	}

	/**
	 * Inject a settings array directly into the static cache so get_settings()
	 * skips the database read + AES decryption path.
	 *
	 * @param array<string, mixed> $extra Settings to merge alongside openai_api_key.
	 */
	private function stub_settings( array $extra ): void {
		$cache_prop = new \ReflectionProperty( ILSEJ_Core::class, 'settings_cache' );
		$cache_prop->setValue( null, array_merge( [ 'openai_api_key' => 'sk-test' ], $extra ) );
	}

	/**
	 * Tear down — clear the ILSEJ_Core settings cache.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		$cache_prop = new \ReflectionProperty( ILSEJ_Core::class, 'settings_cache' );
		$cache_prop->setValue( null, null );
		parent::tear_down();
	}

	/**
	 * Stub wp_remote_post / wp_remote_retrieve_body to return a canned OpenAI
	 * response containing the supplied entity fixtures.
	 *
	 * @param array<int, array<string, string>> $entities Entity fixtures.
	 * @return void
	 */
	private function stub_openai_response( array $entities ): void {
		$body = wp_json_encode(
			[
				'choices' => [
					[
						'message' => [
							'content' => wp_json_encode( [ 'entities' => $entities ] ),
						],
					],
				],
			]
		);

		Functions\when( 'wp_remote_post' )->justReturn(
			[ 'response' => [ 'code' => 200 ] ]
		);
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
	}
}
