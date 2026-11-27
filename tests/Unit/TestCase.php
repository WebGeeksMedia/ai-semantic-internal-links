<?php
/**
 * Base test case for AI Semantic Internal Links unit tests.
 *
 * @package AISemanticInternalLinks
 */

namespace ILSEJ\Tests\Unit;

use Brain\Monkey\Functions;
use Yoast\WPTestUtils\BrainMonkey\TestCase as BrainMonkeyTestCase;

/**
 * Base test case with WordPress function stubs and Reflection helpers.
 */
abstract class TestCase extends BrainMonkeyTestCase {

	/**
	 * Set up common WordPress function stubs for unit tests.
	 *
	 * @return void
	 */
	protected function set_up(): void {
		parent::set_up();

		// WordPress escaping functions — pass through for unit tests.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();

		// Translation functions — pass through.
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();

		// Sanitization functions.
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
			}
		);
		Functions\when( 'absint' )->alias(
			function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults = [] ) {
				return array_merge( $defaults, $args );
			}
		);
	}

	/**
	 * Invoke a private or protected static method via Reflection.
	 *
	 * @param string       $fqcn   Fully qualified class name.
	 * @param string       $method Method name.
	 * @param array<mixed> $args   Arguments to pass.
	 * @return mixed Method return value.
	 */
	protected static function invoke_private( string $fqcn, string $method, array $args = [] ) {
		$reflection = new \ReflectionMethod( $fqcn, $method );
		return $reflection->invokeArgs( null, $args );
	}

	/**
	 * Get a private or protected static property value.
	 *
	 * @param string $fqcn     Fully qualified class name.
	 * @param string $property Property name.
	 * @return mixed Property value.
	 */
	protected static function get_private_property( string $fqcn, string $property ) {
		$reflection = new \ReflectionProperty( $fqcn, $property );
		return $reflection->getValue();
	}

	/**
	 * Set a private or protected static property value.
	 *
	 * @param string $fqcn     Fully qualified class name.
	 * @param string $property Property name.
	 * @param mixed  $value    Value to set.
	 * @return void
	 */
	protected static function set_private_property( string $fqcn, string $property, $value ): void {
		$reflection = new \ReflectionProperty( $fqcn, $property );
		$reflection->setValue( null, $value );
	}
}
