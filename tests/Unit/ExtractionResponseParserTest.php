<?php
/**
 * Tests for ilsej_parse_extraction_response().
 *
 * This is the shape gate for everything downstream. Two independent reviews
 * found the same High-severity consequence of it being absent: entities missing
 * a 'context' key were dropped by array_column() in
 * ilsej_internal_links_from_entities(), which re-indexes from 0 while the result
 * loop still looks up $embeddings[ $index ] by the ORIGINAL index. One bad
 * entity therefore shifted every later one onto the wrong embedding — anchor
 * "beta" linked to whatever Pinecone returned for "gamma". Plausible-looking,
 * wrong, and silent.
 *
 * The shape check used to live inside `if ( ! empty( $banned_words ) )`, and
 * banned_words has no default and no admin field, so on a default install it
 * never ran at all. These tests exist so it cannot quietly become conditional
 * again.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

use Brain\Monkey\Functions;
use ILSEJ_Core;

/**
 * Test suite for the shared extraction-response parser.
 *
 * @covers ::ilsej_parse_extraction_response
 */
class ExtractionResponseParserTest extends TestCase {

	/**
	 * Set up — stub the filters get_settings() would otherwise reach for.
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
		$this->stub_settings( [] );
	}

	/**
	 * A well-formed payload survives intact.
	 */
	public function test_valid_entities_pass_through(): void {
		$out = ilsej_parse_extraction_response(
			$this->payload(
				[
					'entities' => [
						[
							'phrase'  => 'alpha',
							'context' => 'ctx alpha',
						],
						[
							'phrase'  => 'beta',
							'context' => 'ctx beta',
						],
					],
				]
			)
		);
		$this->assertCount( 2, $out );
		$this->assertSame( 'alpha', $out[0]['phrase'] );
	}

	/**
	 * An entity missing 'context' is dropped rather than passed on — this is
	 * the exact row that used to shift every later embedding.
	 */
	public function test_entity_missing_context_is_dropped(): void {
		$out = ilsej_parse_extraction_response(
			$this->payload(
				[
					'entities' => [
						[
							'phrase'  => 'alpha',
							'context' => 'ctx alpha',
						],
						[ 'phrase' => 'beta' ],
						[
							'phrase'  => 'gamma',
							'context' => 'ctx gamma',
						],
					],
				]
			)
		);
		$this->assertCount( 2, $out );
		$this->assertSame( [ 'alpha', 'gamma' ], array_column( $out, 'phrase' ) );
	}

	/**
	 * A list whose only row lacks 'phrase' has nothing usable: a structural
	 * failure (null), so the dispatcher can try the fallback provider.
	 */
	public function test_entity_missing_phrase_only_row_is_structural_failure(): void {
		$out = ilsej_parse_extraction_response(
			$this->payload( [ 'entities' => [ [ 'context' => 'orphan context' ] ] ] )
		);
		$this->assertNull( $out );
	}

	/**
	 * An empty entity list is a valid answer, not a failure.
	 */
	public function test_empty_entity_list_is_valid(): void {
		$this->assertSame( [], ilsej_parse_extraction_response( '{"entities":[]}' ) );
	}

	/**
	 * An entities object (associative array) is not a list: structural failure.
	 */
	public function test_entities_object_is_structural_failure(): void {
		$this->assertNull( ilsej_parse_extraction_response( '{"entities":{"a":{"phrase":"x","context":"y"}}}' ) );
		$this->assertNull( ilsej_parse_extraction_response( '{"entities":{}}' ) );
		$this->assertNull( ilsej_parse_extraction_response( '{"entities":{"0":{"phrase":"x","context":"y"}}}' ) );
	}

	/**
	 * A root array is not the expected object, even with an entities key inside.
	 */
	public function test_root_array_is_structural_failure(): void {
		$this->assertNull( ilsej_parse_extraction_response( '[{"entities":[]}]' ) );
	}

	/**
	 * Non-string phrase or context rows are dropped, never cast.
	 */
	public function test_non_string_fields_are_dropped(): void {
		$out = ilsej_parse_extraction_response( '{"entities":[{"phrase":["x"],"context":"c"},{"phrase":"ok","context":"c ok"}]}' );
		$this->assertCount( 1, $out );
		$this->assertSame( 'ok', $out[0]['phrase'] );
	}

	/**
	 * The expected_topic field is kept when present, since the specificity gate reads it.
	 */
	public function test_expected_topic_is_kept(): void {
		$out = ilsej_parse_extraction_response( '{"entities":[{"phrase":"proof of stake","context":"uses proof of stake","expected_topic":"consensus"}]}' );
		$this->assertSame( 'consensus', $out[0]['expected_topic'] );
	}

	/**
	 * A list emptied only by the banned-words filter is a valid empty answer.
	 */
	public function test_banned_words_emptying_list_is_not_failure(): void {
		$this->stub_settings( [ 'banned_words' => [ 'casino' ] ] );
		$out = ilsej_parse_extraction_response( '{"entities":[{"phrase":"casino bonus","context":"a casino bonus"}]}' );
		$this->assertSame( [], $out );
	}

	/**
	 * Blank / whitespace-only values are as useless as absent ones.
	 */
	public function test_blank_values_are_dropped(): void {
		$out = ilsej_parse_extraction_response(
			$this->payload(
				[
					'entities' => [
						[
							'phrase'  => '   ',
							'context' => 'ctx',
						],
						[
							'phrase'  => 'ok',
							'context' => '',
						],
						[
							'phrase'  => 'keep',
							'context' => 'ctx keep',
						],
					],
				]
			)
		);
		$this->assertCount( 1, $out );
		$this->assertSame( 'keep', $out[0]['phrase'] );
	}

	/**
	 * A non-array row must not reach downstream array access.
	 */
	public function test_non_array_entity_is_dropped(): void {
		$out = ilsej_parse_extraction_response( '{"entities":["just a string",{"phrase":"ok","context":"ctx"}]}' );
		$this->assertCount( 1, $out );
	}

	/**
	 * Surviving entities are re-indexed contiguously, so a caller iterating by
	 * position never sees a gap.
	 */
	public function test_result_is_a_contiguous_list(): void {
		$out = ilsej_parse_extraction_response(
			$this->payload(
				[
					'entities' => [
						[ 'phrase' => 'drop' ],
						[
							'phrase'  => 'keep1',
							'context' => 'c1',
						],
						[ 'phrase' => 'drop2' ],
						[
							'phrase'  => 'keep2',
							'context' => 'c2',
						],
					],
				]
			)
		);
		$this->assertSame( [ 0, 1 ], array_keys( $out ) );
	}

	/**
	 * A markdown-fenced payload still decodes.
	 */
	public function test_markdown_fence_is_stripped(): void {
		$out = ilsej_parse_extraction_response( "```json\n{\"entities\":[{\"phrase\":\"a\",\"context\":\"c\"}]}\n```" );
		$this->assertCount( 1, $out );
	}

	/**
	 * Unparseable content returns null so the caller can distinguish "bad
	 * payload" from "no entities found".
	 */
	public function test_unparseable_returns_null(): void {
		$this->assertNull( ilsej_parse_extraction_response( 'not json at all' ) );
		$this->assertNull( ilsej_parse_extraction_response( '{"something_else":true}' ) );
	}

	/**
	 * Shape validation must run even when banned_words is empty — it used to be
	 * nested inside that branch, which made it dead code on a default install.
	 */
	public function test_shape_validation_runs_without_banned_words(): void {
		$this->stub_settings( [ 'banned_words' => [] ] );
		$out = ilsej_parse_extraction_response(
			$this->payload( [ 'entities' => [ [ 'phrase' => 'no ctx here' ] ] ] )
		);
		$this->assertNull( $out );
	}

	/**
	 * Banned words still filter, on top of shape validation.
	 */
	public function test_banned_words_still_apply(): void {
		$this->stub_settings( [ 'banned_words' => [ 'casino' ] ] );
		$out = ilsej_parse_extraction_response(
			$this->payload(
				[
					'entities' => [
						[
							'phrase'  => 'best casino bonus',
							'context' => 'c1',
						],
						[
							'phrase'  => 'staking rewards',
							'context' => 'c2',
						],
					],
				]
			)
		);
		$this->assertCount( 1, $out );
		$this->assertSame( 'staking rewards', $out[0]['phrase'] );
	}

	/**
	 * Inject settings directly into the static cache, skipping the DB read.
	 *
	 * @param array<string, mixed> $extra Settings to merge alongside openai_api_key.
	 */
	private function stub_settings( array $extra ): void {
		$cache_prop = new \ReflectionProperty( ILSEJ_Core::class, 'settings_cache' );
		$cache_prop->setValue( null, array_merge( [ 'openai_api_key' => 'sk-test' ], $extra ) );
	}

	/**
	 * Encode a fixture as the model would return it.
	 *
	 * @param array<string, mixed> $data Fixture.
	 * @return string JSON payload.
	 */
	private function payload( array $data ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- parser is called directly; WP is not loaded.
		return (string) json_encode( $data );
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
}
