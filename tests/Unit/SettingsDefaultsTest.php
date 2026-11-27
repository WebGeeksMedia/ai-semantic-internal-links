<?php
/**
 * Tests for plugin defaults — min_relevance_score, excluded_categories,
 * excluded_tags — and their sanitize round-trip.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

use Brain\Monkey\Functions;
use ILSEJ_Admin;
use ILSEJ_Core;

/**
 * Test suite for plugin defaults and excluded-term sanitization.
 *
 * @covers ILSEJ_Core::get_settings
 * @covers ILSEJ_Admin::sanitize_settings
 */
class SettingsDefaultsTest extends TestCase {

	/**
	 * Admin instance under test.
	 *
	 * @var ILSEJ_Admin
	 */
	private $admin;

	/**
	 * Set up — reset Admin singleton, stub apply_filters, build instance.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		$reflection    = new \ReflectionClass( ILSEJ_Admin::class );
		$instance_prop = $reflection->getProperty( 'instance' );
		$instance_prop->setValue( null, null );

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);

		$this->admin = ILSEJ_Admin::instance();
	}

	/**
	 * Tear down — reset Admin singleton and ILSEJ_Core settings cache.
	 *
	 * @return void
	 */
	protected function tear_down(): void {
		$reflection    = new \ReflectionClass( ILSEJ_Admin::class );
		$instance_prop = $reflection->getProperty( 'instance' );
		$instance_prop->setValue( null, null );

		$cache_prop = new \ReflectionProperty( ILSEJ_Core::class, 'settings_cache' );
		$cache_prop->setValue( null, null );

		parent::tear_down();
	}

	/**
	 * Reset the settings cache before each get_settings() call so the
	 * Functions\when('get_option') stub actually takes effect.
	 *
	 * @return void
	 */
	private function reset_settings_cache(): void {
		$cache_prop = new \ReflectionProperty( ILSEJ_Core::class, 'settings_cache' );
		$cache_prop->setValue( null, null );
	}

	/**
	 * Fresh-install defaults: min_relevance_score is 0.60.
	 *
	 * @return void
	 */
	public function test_default_min_relevance_score_is_060(): void {
		$this->reset_settings_cache();
		Functions\when( 'get_option' )->justReturn( [] );
		$settings = ILSEJ_Core::get_settings();
		$this->assertSame( 0.60, $settings['min_relevance_score'] );
	}

	/**
	 * Fresh-install defaults: excluded_categories and excluded_tags ship empty.
	 *
	 * @return void
	 */
	public function test_default_excluded_lists_are_empty(): void {
		$this->reset_settings_cache();
		Functions\when( 'get_option' )->justReturn( [] );
		$settings = ILSEJ_Core::get_settings();
		$this->assertSame( [], $settings['excluded_categories'] );
		$this->assertSame( [], $settings['excluded_tags'] );
	}

	/**
	 * Saved values override defaults (defaults merge preserves saved options).
	 *
	 * @return void
	 */
	public function test_saved_min_relevance_score_overrides_default(): void {
		$this->reset_settings_cache();
		Functions\when( 'get_option' )->justReturn( [ 'min_relevance_score' => 0.42 ] );
		$settings = ILSEJ_Core::get_settings();
		$this->assertSame( 0.42, $settings['min_relevance_score'] );
	}

	/**
	 * Sanitize_settings: known category slugs are kept, unknown dropped.
	 *
	 * @return void
	 */
	public function test_sanitize_excluded_categories_intersects_with_taxonomy(): void {
		$result = $this->call_sanitize(
			[ 'excluded_categories' => [ 'press-releases', 'unknown-slug', 'thai-press-releases' ] ],
			[
				'category' => [
					1 => 'press-releases',
					2 => 'thai-press-releases',
					3 => 'news-and-updates',
				],
				'post_tag' => [],
			]
		);
		$this->assertSame( [ 'press-releases', 'thai-press-releases' ], $result['excluded_categories'] );
	}

	/**
	 * Sanitize_settings: known tag slugs are kept, unknown dropped.
	 *
	 * @return void
	 */
	public function test_sanitize_excluded_tags_intersects_with_taxonomy(): void {
		$result = $this->call_sanitize(
			[ 'excluded_tags' => [ 'news', 'sp', 'made-up' ] ],
			[
				'category' => [],
				'post_tag' => [
					1 => 'news',
					2 => 'sp',
					3 => 'crypto',
				],
			]
		);
		$this->assertSame( [ 'news', 'sp' ], $result['excluded_tags'] );
	}

	/**
	 * Sanitize_settings: missing or non-array input becomes empty list.
	 *
	 * @return void
	 */
	public function test_sanitize_excluded_lists_default_to_empty_when_missing(): void {
		$result = $this->call_sanitize(
			[],
			[
				'category' => [],
				'post_tag' => [],
			]
		);
		$this->assertSame( [], $result['excluded_categories'] );
		$this->assertSame( [], $result['excluded_tags'] );
	}

	/**
	 * Sanitize_settings: non-string values are dropped, empty strings dropped.
	 *
	 * @return void
	 */
	public function test_sanitize_excluded_lists_reject_non_strings(): void {
		$result = $this->call_sanitize(
			[ 'excluded_categories' => [ 'press-releases', 123, '', null ] ],
			[
				'category' => [ 1 => 'press-releases' ],
				'post_tag' => [],
			]
		);
		$this->assertSame( [ 'press-releases' ], $result['excluded_categories'] );
	}

	/**
	 * Helper: call sanitize_settings with stubbed taxonomy data.
	 *
	 * @param array<string, mixed>             $input             Raw settings input.
	 * @param array<string, array<int,string>> $terms_by_taxonomy Taxonomy slugs keyed by taxonomy name.
	 * @return array<string, mixed>
	 */
	private function call_sanitize( array $input, array $terms_by_taxonomy ): array {
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'add_settings_error' )->justReturn( true );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt-for-unit-tests' );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'get_post_types' )->justReturn(
			[
				'post' => 'post',
				'page' => 'page',
			]
		);

		Functions\when( 'get_terms' )->alias(
			static function ( $args ) use ( $terms_by_taxonomy ) {
				$tax = is_array( $args ) ? ( $args['taxonomy'] ?? '' ) : '';
				return $terms_by_taxonomy[ $tax ] ?? [];
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );

		return $this->admin->sanitize_settings( $input );
	}

	/**
	 * Fresh install: Claude primary, OpenAI fallback, Opus model, no Anthropic key.
	 *
	 * @return void
	 */
	public function test_default_extraction_providers(): void {
		$this->reset_settings_cache();
		Functions\when( 'get_option' )->justReturn( [] );
		$settings = ILSEJ_Core::get_settings();
		$this->assertSame( 'claude', $settings['extraction_primary'] );
		$this->assertSame( 'openai', $settings['extraction_fallback'] );
		$this->assertSame( 'claude-opus-5-5', $settings['claude_model'] );
		$this->assertSame( '', $settings['anthropic_api_key'] );
	}

	/**
	 * Upgrade from 2.5.x: an already-migrated option with only an encrypted
	 * OpenAI key keeps that key readable and gains the provider defaults, so
	 * extraction falls through to OpenAI until a Claude key is saved.
	 *
	 * @return void
	 */
	public function test_upgraded_option_keeps_openai_key_and_gains_defaults(): void {
		Functions\when( 'wp_salt' )->justReturn( 'test-salt-for-unit-tests' );
		$security = new \ILSEJ_Security();
		$this->reset_settings_cache();
		Functions\when( 'get_option' )->justReturn(
			[
				'openai_api_key' => $security->encrypt_api_key( 'sk-live-openai' ),
				'_v2_migrated'   => true,
			]
		);

		$settings = ILSEJ_Core::get_settings();

		$this->assertSame( 'sk-live-openai', $settings['openai_api_key'] );
		$this->assertSame( '', $settings['anthropic_api_key'] );
		$this->assertSame( [ 'claude', 'openai' ], ilsej_extraction_provider_order( $settings ) );
		$this->assertTrue( $settings['_v2_migrated'] );
	}

	/**
	 * A provider value written outside the form is normalized on read.
	 *
	 * @return void
	 */
	public function test_invalid_stored_provider_values_are_normalized_on_read(): void {
		$this->reset_settings_cache();
		Functions\when( 'get_option' )->justReturn(
			[
				'extraction_primary'  => 'openai',
				'extraction_fallback' => 'openai',
				'claude_model'        => 'retired-model',
			]
		);
		$settings = ILSEJ_Core::get_settings();
		$this->assertSame( 'none', $settings['extraction_fallback'] );
		$this->assertSame( 'claude-opus-5-5', $settings['claude_model'] );
	}
}
