<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * MAIN PLUGIN CORE CLASS
 *
 * This is the central orchestrator class that initializes all plugin components.
 * It follows the Singleton pattern to ensure only one instance exists throughout
 * the WordPress request lifecycle.
 *
 * @package AISemanticInternalLinks
 *
 * ARCHITECTURAL ROLE:
 * - Acts as the main entry point for plugin initialization
 * - Manages plugin settings storage and retrieval
 * - Coordinates between admin interface and editor integrations
 * - Provides centralized access to configuration data
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ILSEJ_Core
 *
 * Central orchestrator for plugin initialization and settings management.
 */
class ILSEJ_Core {

	/**
	 * SINGLETON INSTANCE
	 *
	 * Stores the single instance of this class. The Singleton pattern ensures:
	 * - Only one instance of the class exists at a time
	 * - Provides global access point to the instance
	 * - Prevents multiple initializations that could cause conflicts
	 *
	 * @var ILSEJ_Core
	 */
	private static $instance = null;

	/**
	 * OPTION KEY FOR WordPress DATABASE
	 *
	 * This constant defines the key used to store plugin settings in the
	 * WordPress wp_options table. All plugin configuration (API keys, post types, etc.)
	 * is stored under this single key as a serialized array.
	 *
	 * WHY A SINGLE KEY:
	 * - Reduces database queries (one option vs. multiple)
	 * - Easier to manage and backup
	 * - Follows WordPress best practices for plugin settings
	 */
	const OPTION_KEY = 'ilsej_settings';

	/**
	 * Settings that hold credentials and are stored encrypted.
	 *
	 * One list for every read, save and migration path: a key missing from one
	 * copy would be saved in plaintext or fail to decrypt.
	 */
	const ENCRYPTED_KEYS = [ 'pinecone_api_key', 'gcp_json_key', 'openai_api_key', 'anthropic_api_key' ];

	/**
	 * Entity-extraction providers, keyed by setting value.
	 */
	const EXTRACTION_PROVIDERS = [
		'claude' => 'Claude (Anthropic)',
		'openai' => 'OpenAI',
	];

	/**
	 * Claude models offered for entity extraction, keyed by API model id.
	 */
	const CLAUDE_MODELS = [
		'claude-opus-5-5'   => 'Claude Opus 5.5',
		'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
		'claude-haiku-5-5'  => 'Claude Haiku 5.5',
	];

	/**
	 * Claude models that accept the server-side refusal fallback.
	 *
	 * Claude Haiku 5.5 has none, and sending the parameter for it is rejected.
	 */
	const CLAUDE_MODELS_WITH_SERVER_FALLBACK = [ 'claude-opus-5-5', 'claude-sonnet-5-5' ];

	const DEFAULT_CLAUDE_MODEL        = 'claude-opus-5-5';
	const DEFAULT_EXTRACTION_PRIMARY  = 'claude';
	const DEFAULT_EXTRACTION_FALLBACK = 'openai';

	/**
	 * Cached settings array to avoid repeated DB reads and decryption.
	 *
	 * @var array<string, mixed>|null
	 */
	private static $settings_cache = null;

	/**
	 * SINGLETON GETTER METHOD
	 *
	 * Returns the single instance of ILSEJ_Core, creating it if it doesn't exist.
	 *
	 * HOW IT WORKS:
	 * - First call: Creates new instance and stores it in $instance
	 * - Subsequent calls: Returns the existing instance
	 *
	 * WHY SINGLETON:
	 * - Prevents duplicate initialization of admin/editor components
	 * - Ensures settings are loaded once and cached
	 * - Reduces memory usage and potential conflicts
	 *
	 * @return ILSEJ_Core The single instance of the core class
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * PRIVATE CONSTRUCTOR
	 *
	 * Initializes the plugin by loading and instantiating required components.
	 * The constructor is private to enforce the Singleton pattern (can only
	 * be called from within the class via instance()).
	 *
	 * INITIALIZATION FLOW:
	 * 1. Admin Interface: Loads settings page and indexing management (admin only)
	 * 2. Editor Integration: Loads TinyMCE and Gutenberg editor integrations
	 *
	 * CONDITIONAL LOADING:
	 * - Admin components only load in wp-admin (is_admin() check)
	 * - Editor components load on both frontend and backend (needed for preview)
	 *
	 * WHY REQUIRE_ONCE:
	 * - Prevents fatal errors if files are missing
	 * - Ensures classes are loaded before instantiation
	 * - Follows WordPress plugin structure conventions
	 */
	private function __construct() {
		// Load admin interface (settings page, indexing management).
		// Only loads in WordPress admin area to reduce frontend overhead.
		if ( is_admin() ) {
			require_once ILSEJ_DIR . 'includes/ilsej-admin.php';
			ILSEJ_Admin::instance();
		}

		// Load editor integrations (TinyMCE button, Gutenberg sidebar).
		// Loads on both frontend and backend for editor functionality.
		require_once ILSEJ_DIR . 'includes/ilsej-editor.php';
		ILSEJ_Editor::instance();
	}

	/**
	 * GET PLUGIN SETTINGS WITH DEFAULTS
	 *
	 * This is the central method for retrieving plugin configuration.
	 * It ensures settings always have default values, preventing errors
	 * when accessing unset configuration options.
	 *
	 * HOW IT WORKS:
	 * 1. Defines default values for all settings
	 * 2. Retrieves saved settings from WordPress database (wp_options table)
	 * 3. Merges saved settings with defaults (saved values override defaults)
	 * 4. Validates data types (ensures post_types is always an array)
	 *
	 * SETTINGS STORED:
	 * - gcp_json_key: Google Cloud Service Account JSON credentials for Vertex AI
	 * - pinecone_api_key: API key for Pinecone vector database
	 * - pinecone_host: Pinecone host URL (e.g., 'https://index-abc123.svc.pinecone.io')
	 * - openai_api_key: API key for OpenAI (entity extraction)
	 * - anthropic_api_key: API key for Claude (entity extraction)
	 * - claude_model: Claude model id used for entity extraction
	 * - extraction_primary / extraction_fallback: which provider extracts first,
	 *   and which one runs when the first fails ('none' disables the fallback)
	 * - post_types: Array of WordPress post types to index (default: ['post', 'page'])
	 *
	 * WHY DEFAULTS ARE IMPORTANT:
	 * - Prevents undefined index errors when accessing settings
	 * - Provides sensible fallback values for new installations
	 * - Makes the code more robust and easier to maintain
	 *
	 * @return array<string, mixed> Associative array of plugin settings with all defaults applied
	 */
	public static function get_settings(): array {
		// Return cached settings if available (avoids repeated DB reads + decryption).
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		// Default configuration values.
		// These are used when settings haven't been saved yet or are missing.
		$defaults = [
			'gcp_json_key'             => '',  // Google Cloud Service Account JSON content.
			'pinecone_api_key'         => '',  // Pinecone API key from dashboard.
			'pinecone_host'            => '',  // Pinecone host URL (e.g., 'https://index-abc123.svc.pinecone.io').
			'openai_api_key'           => '',  // OpenAI API key for entity extraction.
			'anthropic_api_key'        => '',  // Anthropic API key for Claude entity extraction.
			'claude_model'             => self::DEFAULT_CLAUDE_MODEL,
			// An upgraded site has only an OpenAI key, so a Claude primary with no
			// key falls through to the OpenAI fallback until a Claude key is saved.
			'extraction_primary'       => self::DEFAULT_EXTRACTION_PRIMARY,
			'extraction_fallback'      => self::DEFAULT_EXTRACTION_FALLBACK,
			'post_types'               => [ 'post', 'page' ], // Default post types to index.
			'batch_size'               => 50,  // Posts per indexing batch (10-100).
			'enable_retry'             => true, // Enable retry logic for API calls.
			'enable_rate_limiting'     => true, // Enable rate limiting for API calls.
			'enable_category_filter'   => true, // Show category filter in editors.
			'enable_tag_filter'        => true, // Show tag filter in editors.
			'min_relevance_score'      => 0.60, // Minimum Pinecone similarity score (0-1) for suggestions.
			'excluded_categories'      => [], // Category slugs excluded from suggestions (Pinecone $nin).
			'indexed_page_ids'         => [], // Page IDs indexed in addition to post_types (selective).
			'excluded_tags'            => [], // Tag slugs excluded from suggestions (Pinecone $nin).
			'delete_data_on_uninstall' => false, // Only delete plugin data on uninstall when opted in.
		];

		// Retrieve saved settings from WordPress database.
		// Returns empty array if option doesn't exist (first-time installation).
		$settings = get_option( self::OPTION_KEY, [] );

		// Safety check: ensure settings is always an array.
		// Prevents errors if database contains corrupted data.
		if ( ! is_array( $settings ) ) {
			$settings = [];
		}

		// Merge saved settings with defaults.
		// Saved values override defaults, missing values use defaults.
		$settings = wp_parse_args( $settings, $defaults );

		// Validate post_types is always an array.
		// Prevents errors when iterating over post types in other functions.
		if ( ! is_array( $settings['post_types'] ) ) {
			$settings['post_types'] = $defaults['post_types'];
		}

		$settings = self::normalize_extraction_settings( $settings );

		// Decrypt API keys transparently.
		require_once ILSEJ_DIR . 'includes/ilsej-security.php';
		$security       = new ILSEJ_Security();
		$decrypt_failed = false;
		foreach ( self::ENCRYPTED_KEYS as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$decrypted = $security->decrypt_api_key( $settings[ $key ] );
				if ( false === $decrypted ) {
					$settings[ $key ] = '';
					$decrypt_failed   = true;
				} else {
					$settings[ $key ] = $decrypted;
				}
			}
		}
		if ( $decrypt_failed ) {
			add_action(
				'admin_notices',
				static function () {
					if ( ! current_user_can( 'manage_options' ) ) {
						return;
					}
					echo '<div class="notice notice-warning"><p><strong>AI Semantic Internal Links:</strong> API key decryption failed (site salts may have changed). Please re-save your settings on the <a href="' . esc_url( admin_url( 'admin.php?page=ilsej-settings' ) ) . '">Settings page</a>.</p></div>';
				}
			);
		}

		self::$settings_cache = $settings;
		return $settings;
	}

	/**
	 * Invalidate the static settings cache.
	 *
	 * Called after save_settings() so the next get_settings() re-reads from DB.
	 *
	 * @return void
	 */
	public static function invalidate_settings_cache(): void {
		self::$settings_cache = null;
	}

	/**
	 * Force the provider and model settings into their allowed values.
	 *
	 * Runs on every read as well as on save, so a value written outside the
	 * settings form (WP-CLI, a database edit, a model later removed from the
	 * list) can never reach the API. A fallback equal to the primary would only
	 * repeat the failed call, so it becomes 'none'.
	 *
	 * @param array<string, mixed> $settings Settings to normalize.
	 * @return array<string, mixed> The settings with valid provider and model values.
	 */
	public static function normalize_extraction_settings( array $settings ): array {
		$model = $settings['claude_model'] ?? '';
		if ( ! is_string( $model ) || ! isset( self::CLAUDE_MODELS[ $model ] ) ) {
			$settings['claude_model'] = self::DEFAULT_CLAUDE_MODEL;
		}

		$primary = $settings['extraction_primary'] ?? '';
		if ( ! is_string( $primary ) || ! isset( self::EXTRACTION_PROVIDERS[ $primary ] ) ) {
			$primary = self::DEFAULT_EXTRACTION_PRIMARY;
		}

		$fallback = $settings['extraction_fallback'] ?? '';
		if ( ! is_string( $fallback ) || ( 'none' !== $fallback && ! isset( self::EXTRACTION_PROVIDERS[ $fallback ] ) ) ) {
			$fallback = self::DEFAULT_EXTRACTION_FALLBACK;
		}
		if ( $fallback === $primary ) {
			$fallback = 'none';
		}

		$settings['extraction_primary']  = $primary;
		$settings['extraction_fallback'] = $fallback;

		return $settings;
	}

	/**
	 * Save plugin settings with automatic encryption of API keys.
	 *
	 * @param array<string, mixed> $new_settings Settings array to save.
	 * @return void
	 */
	public static function save_settings( array $new_settings ) {
		require_once ILSEJ_DIR . 'includes/ilsej-security.php';
		$security = new ILSEJ_Security();

		foreach ( self::ENCRYPTED_KEYS as $key ) {
			if ( ! empty( $new_settings[ $key ] ) ) {
				$new_settings[ $key ] = $security->encrypt_api_key( $new_settings[ $key ] );
			}
		}

		update_option( self::OPTION_KEY, $new_settings );
		self::invalidate_settings_cache();
	}
}
