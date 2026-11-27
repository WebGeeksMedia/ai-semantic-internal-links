<?php
/**
 * Tests for ilsej_apply_excluded_term_filters() — merges excluded category /
 * tag slugs from settings into the Pinecone negative-filters array.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Test suite for the Pinecone negative-filter merger.
 *
 * @covers ::ilsej_apply_excluded_term_filters
 */
class PineconeNegativeFilterTest extends TestCase {

	/**
	 * Set up — pass-through apply_filters so default lists win.
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
	}

	/**
	 * With no excluded slugs in settings, caller-supplied filters pass through unchanged.
	 *
	 * @return void
	 */
	public function test_empty_settings_returns_filters_unchanged(): void {
		$existing = [ 'ID' => [ '42' ] ];
		$result   = ilsej_apply_excluded_term_filters( $existing, [] );
		$this->assertSame( $existing, $result );
	}

	/**
	 * Excluded category slugs land under the Pinecone 'category' key.
	 *
	 * @return void
	 */
	public function test_excluded_categories_added_to_negative_filters(): void {
		$result = ilsej_apply_excluded_term_filters(
			[],
			[ 'excluded_categories' => [ 'press-releases', 'thai-press-releases' ] ]
		);
		$this->assertSame(
			[ 'press-releases', 'thai-press-releases' ],
			$result['category']
		);
	}

	/**
	 * Excluded tag slugs land under the Pinecone 'tags' key.
	 *
	 * @return void
	 */
	public function test_excluded_tags_added_to_negative_filters(): void {
		$result = ilsej_apply_excluded_term_filters(
			[],
			[ 'excluded_tags' => [ 'news', 'sp' ] ]
		);
		$this->assertSame( [ 'news', 'sp' ], $result['tags'] );
	}

	/**
	 * Caller-supplied filters are preserved and merged with settings, not overwritten.
	 *
	 * @return void
	 */
	public function test_merges_with_caller_supplied_filters_without_overwrite(): void {
		$result = ilsej_apply_excluded_term_filters(
			[ 'category' => [ 'caller-supplied' ] ],
			[ 'excluded_categories' => [ 'press-releases' ] ]
		);
		$this->assertSame(
			[ 'caller-supplied', 'press-releases' ],
			$result['category']
		);
	}

	/**
	 * Overlapping slugs between caller and settings are deduped.
	 *
	 * @return void
	 */
	public function test_dedupes_overlap_between_caller_and_settings(): void {
		$result = ilsej_apply_excluded_term_filters(
			[ 'tags' => [ 'news' ] ],
			[ 'excluded_tags' => [ 'news', 'sp' ] ]
		);
		$this->assertSame( [ 'news', 'sp' ], $result['tags'] );
	}

	/**
	 * Other negative-filter keys (like 'ID') survive the merge untouched.
	 *
	 * @return void
	 */
	public function test_preserves_unrelated_negative_filter_keys(): void {
		$result = ilsej_apply_excluded_term_filters(
			[ 'ID' => [ '99' ] ],
			[
				'excluded_categories' => [ 'press-releases' ],
				'excluded_tags'       => [ 'news' ],
			]
		);
		$this->assertSame( [ '99' ], $result['ID'] );
		$this->assertSame( [ 'press-releases' ], $result['category'] );
		$this->assertSame( [ 'news' ], $result['tags'] );
	}

	/**
	 * Non-array setting values are silently ignored (defensive against bad data).
	 *
	 * @return void
	 */
	public function test_non_array_setting_value_is_ignored(): void {
		$result = ilsej_apply_excluded_term_filters(
			[],
			[ 'excluded_categories' => 'not-an-array' ]
		);
		$this->assertArrayNotHasKey( 'category', $result );
	}
}
