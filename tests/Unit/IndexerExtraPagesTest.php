<?php
/**
 * Tests for selective extra-page indexing: get_extra_indexed_ids
 * parsing and the is_indexable membership rule.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

use Brain\Monkey\Functions;
use ILSEJ_Indexer;

/**
 * Test suite for the extra-indexed-pages membership rule.
 *
 * @covers ILSEJ_Indexer::is_indexable
 */
class IndexerExtraPagesTest extends TestCase {

	/**
	 * Reset ILSEJ_Core's static settings cache — without this, the first
	 * test's stubbed option leaks into every later test in the process.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();
		$ref = new \ReflectionClass( \ILSEJ_Core::class );
		if ( $ref->hasProperty( 'settings_cache' ) ) {
			$prop = $ref->getProperty( 'settings_cache' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}
	}

	/**
	 * Invoke a private static ILSEJ_Indexer method.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function call_indexer( string $method, ...$args ) {
		$ref = new \ReflectionMethod( ILSEJ_Indexer::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null, ...$args );
	}

	/**
	 * Stub the stored settings option.
	 *
	 * @param array<string, mixed> $settings Stored settings.
	 * @return void
	 */
	private function stub_settings( array $settings ): void {
		Functions\when( 'get_option' )->justReturn( $settings );
	}

	// Negatives first: the failure modes this rule exists to prevent.

	/**
	 * An unlisted page must NOT be indexable — the whole point is selectivity.
	 *
	 * @return void
	 */
	public function test_unlisted_page_is_not_indexable(): void {
		$this->stub_settings(
			[
				'post_types'       => [ 'post' ],
				'indexed_page_ids' => [ 28330 ],
			]
		);
		$this->assertFalse( $this->call_indexer( 'is_indexable', 99, 'page' ) );
	}

	/**
	 * A missing setting means no extras — never "all pages".
	 *
	 * @return void
	 */
	public function test_missing_setting_means_no_extras(): void {
		$this->stub_settings( [ 'post_types' => [ 'post' ] ] );
		$this->assertSame( [], $this->call_indexer( 'get_extra_indexed_ids' ) );
		$this->assertFalse( $this->call_indexer( 'is_indexable', 28330, 'page' ) );
	}

	/**
	 * Garbage entries (zero, non-numeric) are dropped, not coerced into ID 0.
	 *
	 * @return void
	 */
	public function test_extra_ids_drop_garbage(): void {
		$this->stub_settings( [ 'indexed_page_ids' => [ '45062', 0, 'x', 28330 ] ] );
		$this->assertSame( [ 45062, 28330 ], $this->call_indexer( 'get_extra_indexed_ids' ) );
	}

	// Positives.

	/**
	 * A listed page ID is indexable even though `page` is not an allowed type.
	 *
	 * @return void
	 */
	public function test_listed_page_is_indexable_despite_type(): void {
		$this->stub_settings(
			[
				'post_types'       => [ 'post' ],
				'indexed_page_ids' => [ 28330 ],
			]
		);
		$this->assertTrue( $this->call_indexer( 'is_indexable', 28330, 'page' ) );
	}

	/**
	 * An allowed type needs no listing — existing behavior is unchanged.
	 *
	 * @return void
	 */
	public function test_allowed_type_needs_no_listing(): void {
		$this->stub_settings(
			[
				'post_types'       => [ 'post' ],
				'indexed_page_ids' => [],
			]
		);
		$this->assertTrue( $this->call_indexer( 'is_indexable', 5, 'post' ) );
	}
}
