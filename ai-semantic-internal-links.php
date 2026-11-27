<?php
/**
 * Plugin Name: AI Semantic Internal Links
 * Description: Suggests contextually relevant internal links in the editor using semantic vector search. Built with Google Vertex AI embeddings, Pinecone vector database, and Claude or OpenAI entity extraction.
 * Author: WebGeeksMedia
 * Author URI: https://webgeeksmedia.com/
 * Version: 1.0.0
 * Text Domain: ai-semantic-internal-links
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Based on Internal Linking by Search Engine Journal by Vahan Petrosyan
 * (GPL-2.0-or-later). Modified by WebGeeksMedia, 2026.
 *
 * EDUCATIONAL PURPOSE:
 * This plugin demonstrates how to build a semantic internal linking system using:
 * - Vector embeddings (Google Vertex AI text-embedding-005 model)
 * - Vector database (Pinecone) for similarity search
 * - LLM-based entity extraction (Claude, with OpenAI as a selectable fallback)
 * - WordPress integration (Classic & Gutenberg editors)
 *
 * ARCHITECTURE OVERVIEW:
 * 1. Indexing: Converts WordPress posts into 768-dimensional vectors stored in Pinecone
 * 2. Querying: Converts user-selected phrases into vectors and finds similar articles
 * 3. Editor Integration: Provides UI in both TinyMCE and Gutenberg for link suggestions
 * 4. Automation: Automatically updates vectors when posts are published/updated/deleted
 *
 * @package AISemanticInternalLinks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly - WordPress security best practice.
}

/**
 * PLUGIN CONSTANTS
 * These constants define the plugin's version and file paths, used throughout the codebase
 * for asset loading, versioning, and file system operations.
 */
if ( ! defined( 'ILSEJ_VERSION' ) ) {
	define( 'ILSEJ_VERSION', '1.0.0' );
}
if ( ! defined( 'ILSEJ_DIR' ) ) {
	define( 'ILSEJ_DIR', plugin_dir_path( __FILE__ ) ); // Absolute server path to plugin directory.
}
if ( ! defined( 'ILSEJ_URL' ) ) {
	define( 'ILSEJ_URL', plugin_dir_url( __FILE__ ) ); // URL path for enqueuing CSS/JS assets.
}

/**
 * AUTOLOADER FUNCTION
 * Automatically loads class files when they're first referenced.
 *
 * HOW IT WORKS:
 * - When PHP encounters a class name starting with 'ILSEJ_', it triggers this function
 * - Converts class name format: ILSEJ_Core → ilsej-core.php
 * - Looks in the 'includes' directory and loads the file if it exists
 *
 * WHY IT'S USEFUL:
 * - No need to manually require_once every class file
 * - Classes are loaded only when needed (lazy loading)
 * - Follows WordPress plugin development best practices
 */
spl_autoload_register(
	function ( $class_name ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound
		if ( 0 === strpos( $class_name, 'ILSEJ_' ) ) {
				$file = ILSEJ_DIR . 'includes/' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}
);

/**
 * PLUGIN INITIALIZATION
 * This is the main entry point that initializes all plugin components.
 *
 * EXECUTION FLOW:
 * 1. ILSEJ_Core::instance() - Sets up admin pages and editor integrations
 * 2. ILSEJ_Indexer::register_hooks() - Registers WordPress hooks for automatic indexing
 *
 * WHY 'plugins_loaded' HOOK:
 * - Ensures WordPress core functions are fully loaded
 * - All other plugins have been loaded (important for compatibility)
 * - Translation functions are available
 *
 * This hook fires after WordPress core and all plugins are loaded, making it
 * the ideal place to initialize plugin functionality that depends on WordPress APIs.
 */
add_action(
	'plugins_loaded',
	function () {
		// Initialize core plugin functionality (admin pages, editor integration).
		if ( class_exists( 'ILSEJ_Core' ) ) {
			ILSEJ_Core::instance();
		}

		// Register hooks for automatic post indexing (publish/update/delete).
		if ( class_exists( 'ILSEJ_Indexer' ) ) {
			ILSEJ_Indexer::register_hooks();
		}
	}
);

/**
 * Migrate to v2.0 on plugin activation.
 *
 * Encrypts existing plaintext API keys and adds new settings defaults.
 * Only runs once — sets a '_v2_migrated' flag to prevent re-migration.
 *
 * @return void
 */
function ilsej_migrate_to_v2() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/ilsej-security.php';

	$settings = get_option( 'ilsej_settings', [] );

	if ( ! is_array( $settings ) ) {
		$settings = [];
	}

	// Already migrated, skip.
	if ( ! empty( $settings['_v2_migrated'] ) ) {
		return;
	}

	$security = new ILSEJ_Security();
	foreach ( ILSEJ_Core::ENCRYPTED_KEYS as $key ) {
		if ( ! empty( $settings[ $key ] ) && ! $security->is_encrypted( $settings[ $key ] ) ) {
			$settings[ $key ] = $security->encrypt_api_key( $settings[ $key ] );
		}
	}

	// Add new v2.0 defaults if not already set.
	if ( ! isset( $settings['batch_size'] ) ) {
		$settings['batch_size'] = 50;
	}
	if ( ! isset( $settings['enable_retry'] ) ) {
		$settings['enable_retry'] = true;
	}
	if ( ! isset( $settings['enable_rate_limiting'] ) ) {
		$settings['enable_rate_limiting'] = true;
	}

	$settings['_v2_migrated'] = true;

	update_option( 'ilsej_settings', $settings );

	error_log( 'ILSEJ: Successfully migrated to v2.0 (encrypted API keys, added new defaults).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}
register_activation_hook( __FILE__, 'ilsej_migrate_to_v2' );

/**
 * Remove stale active_plugins entry from the old plugin slug.
 *
 * Earlier builds used the folder internal-linking-sej (the original plugin's slug), and
 * WordPress may retain that path in the active_plugins option. This runs once
 * on plugins_loaded (not activation) so it works regardless of how the plugin was
 * deployed (manual activation, FTP upload, server-side rename, deployment script).
 *
 * @return void
 */
function ilsej_cleanup_old_slug() {
	if ( get_option( 'ilsej_old_slug_cleaned' ) ) {
		return;
	}

	$old_plugin = 'internal-linking-sej/internal-linking-sej.php';
	$active     = get_option( 'active_plugins', [] );
	$key        = array_search( $old_plugin, $active, true );
	if ( false !== $key ) {
		unset( $active[ $key ] );
		update_option( 'active_plugins', array_values( $active ) );
	}

	update_option( 'ilsej_old_slug_cleaned', true );
}
add_action( 'plugins_loaded', 'ilsej_cleanup_old_slug' );

/**
 * Clean up cron events on plugin deactivation.
 *
 * Prevents orphaned cron jobs from firing after the plugin is deactivated.
 * Without this, WP Cron would still attempt to run indexing/deletion callbacks
 * that no longer exist, causing silent failures in the error log.
 *
 * @return void
 */
function ilsej_deactivate() {
	wp_clear_scheduled_hook( 'ilsej_async_index_event' );
	wp_clear_scheduled_hook( 'ilsej_async_delete_event' );
}
register_deactivation_hook( __FILE__, 'ilsej_deactivate' );
