<?php
/**
 * PHPUnit bootstrap file for AI Semantic Internal Links unit tests.
 *
 * @package AISemanticInternalLinks
 */

// Define WordPress constants to bypass direct-access guards.
define( 'ABSPATH', '/tmp/wordpress/' );
define( 'WPINC', 'wp-includes' );
define( 'ILSEJ_VERSION', '2.2.0-test' );
define( 'ILSEJ_DIR', dirname( __DIR__ ) . '/' );
define( 'ILSEJ_URL', 'https://example.com/wp-content/plugins/ai-semantic-internal-links/' );

// Load Composer autoloader (Brain\Monkey, test classes via PSR-4).
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Register the plugin's autoloader for ILSEJ_* classes.
spl_autoload_register(
	function ( $class_name ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound
		if ( strpos( $class_name, 'ILSEJ_' ) !== 0 ) {
			return;
		}
		$file = ILSEJ_DIR . 'includes/' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

// Pre-load function files so require_once in source code is a no-op.
// This prevents conflicts with Brain\Monkey function stubs.
require_once ILSEJ_DIR . 'includes/google-vertex-ai.php';
require_once ILSEJ_DIR . 'includes/functions-internal-links.php';
