<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * ADMIN INTERFACE CLASS
 *
 * This class handles all WordPress admin area functionality:
 * - Settings page for API configuration (Pinecone, Google Vertex AI, OpenAI)
 * - Article indexing management interface
 * - AJAX handlers for batch indexing operations
 *
 * WordPress SETTINGS API:
 * This class uses WordPress's built-in Settings API, which provides:
 * - Automatic form handling and validation
 * - Secure nonce verification
 * - Settings storage in wp_options table
 * - Consistent UI with WordPress admin design
 *
 * @package AISemanticInternalLinks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ILSEJ_Admin
 *
 * Manages WordPress admin pages, settings, and AJAX batch indexing.
 */
class ILSEJ_Admin {
	/**
	 * Singleton instance.
	 *
	 * @var ILSEJ_Admin
	 */
	private static $instance = null;

	/**
	 * SINGLETON GETTER
	 * Returns the single instance of ILSEJ_Admin
	 *
	 * @return ILSEJ_Admin
	 */
	public static function instance(): ILSEJ_Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * CONSTRUCTOR - REGISTERS WordPress HOOKS
	 *
	 * This method registers all WordPress actions needed for the admin interface.
	 * WordPress hooks allow us to "hook into" WordPress's execution flow at specific points.
	 *
	 * HOOKS REGISTERED:
	 * 1. admin_menu - Adds menu items to WordPress admin sidebar
	 * 2. admin_init - Registers settings fields (WordPress Settings API)
	 * 3. admin_enqueue_scripts - Loads JavaScript/CSS only on plugin pages
	 * 4. wp_ajax_* - Handles AJAX requests from JavaScript (admin-ajax.php)
	 *
	 * WHY HOOKS:
	 * - Keeps code organized and modular
	 * - Allows WordPress to manage execution order
	 * - Enables other plugins to modify behavior if needed
	 */
	private function __construct() {
		// Register admin menu items (Settings page, Indexing page).
		add_action( 'admin_menu', [ $this, 'add_menus' ] );

		// Register settings fields using WordPress Settings API.
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		// Load JavaScript/CSS assets only on plugin admin pages.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// AJAX handlers for batch indexing operations.
		// These handle requests from the JavaScript frontend (ilsej-admin.js).
		add_action( 'wp_ajax_ilsej_start_indexing', [ $this, 'ajax_start_indexing' ] );
		add_action( 'wp_ajax_ilsej_process_batch', [ $this, 'ajax_process_batch' ] );
		add_action( 'wp_ajax_ilsej_stop_indexing', [ $this, 'ajax_stop_indexing' ] );
		add_action( 'wp_ajax_ilsej_index_stats', [ $this, 'ajax_index_stats' ] );
	}

	/**
	 * ADD ADMIN MENU ITEMS
	 *
	 * Creates the plugin's menu structure in WordPress admin sidebar.
	 * This method is called by the 'admin_menu' hook.
	 *
	 * MENU STRUCTURE:
	 * - Top-level menu: "Internal Linking" (with link icon)
	 *   - Submenu: "Settings" (API keys, post types configuration)
	 *   - Submenu: "Article Indexing" (batch indexing interface)
	 *
	 * WHY TWO SUBMENUS:
	 * - Settings: For initial configuration (one-time setup)
	 * - Article Indexing: For ongoing content management (re-indexing)
	 *
	 * CAPABILITY CHECK:
	 * - 'manage_options': Only administrators can access
	 * - Prevents unauthorized users from viewing/changing API keys
	 *
	 * ICON:
	 * - 'dashicons-admin-links': WordPress built-in icon (link symbol)
	 * - No need for custom icon files
	 *
	 * @return void
	 */
	public function add_menus() {
		// Top-level menu item in WordPress admin sidebar.
		add_menu_page(
			__( 'Internal Linking', 'ai-semantic-internal-links' ),   // Browser page title.
			__( 'Internal Linking', 'ai-semantic-internal-links' ),   // Menu label in sidebar.
			'manage_options',                                    // Required user capability (admin only).
			'ilsej-settings',                                    // Unique menu slug.
			[ $this, 'render_settings_page' ],                   // Function to render page content.
			'dashicons-admin-links',                             // WordPress icon (link symbol).
			80                                                   // Menu position (lower = appears later).
		);

		// Settings submenu (reuses same slug as parent for highlighting).
		// This ensures WordPress highlights the parent menu when on settings page.
		add_submenu_page(
			'ilsej-settings',                                    // Parent menu slug.
			__( 'Settings', 'ai-semantic-internal-links' ),            // Page title.
			__( 'Settings', 'ai-semantic-internal-links' ),            // Submenu label.
			'manage_options',                                    // Capability check.
			'ilsej-settings',                                    // Same slug as parent (for highlighting).
			[ $this, 'render_settings_page' ]                    // Render function.
		);

		// Articles Indexing submenu (batch indexing interface).
		add_submenu_page(
			'ilsej-settings',                                    // Parent menu slug.
			__( 'Articles Indexing', 'ai-semantic-internal-links' ),   // Page title.
			__( 'Articles Indexing', 'ai-semantic-internal-links' ),   // Submenu label.
			'manage_options',                                    // Capability check.
			'ilsej-indexing',                                   // Unique slug for indexing page.
			[ $this, 'render_indexing_page' ]                   // Render function.
		);
	}

	/**
	 * REGISTER SETTINGS USING WordPress SETTINGS API
	 *
	 * This method uses WordPress's built-in Settings API to create a secure,
	 * standardized settings form. The Settings API handles:
	 * - Automatic form submission processing
	 * - Nonce verification (CSRF protection)
	 * - Data sanitization via callback
	 * - Consistent WordPress admin styling
	 *
	 * HOW IT WORKS:
	 * 1. register_setting() - Registers the option group and sanitization callback
	 * 2. add_settings_section() - Creates logical groupings of fields
	 * 3. add_settings_field() - Adds individual input fields to sections
	 *
	 * SETTINGS SECTIONS:
	 * - API Configuration: External service credentials (Pinecone, Google, OpenAI)
	 * - Indexing Settings: Content selection (which post types to index)
	 *
	 * SECURITY:
	 * - All user input is sanitized via sanitize_settings() callback
	 * - Nonces prevent CSRF attacks
	 * - Capability checks ensure only admins can save
	 *
	 * This method is called by the 'admin_init' hook, which fires before
	 * any admin page is rendered, ensuring settings are registered early.
	 *
	 * @return void
	 */
	public function register_settings() {
		// Register the main settings group with sanitization callback.
		// WordPress will automatically call sanitize_settings() when form is submitted.
		register_setting(
			'ilsej_settings_group',                              // Settings group name.
			ILSEJ_Core::OPTION_KEY,                              // Option name in database.
			[
				'sanitize_callback' => [ $this, 'sanitize_settings' ], // Sanitization callback function.
			]
		);

		// API Configuration Section.
		// Groups all external API credentials together.
		add_settings_section(
			'ilsej_api_section',                                 // Section ID.
			__( 'API Configuration', 'ai-semantic-internal-links' ),   // Section title.
			'__return_null',                                      // Section description callback.
			'ilsej-settings'                                      // Page slug where section appears.
		);

		// Individual settings fields within API Configuration section.
		add_settings_field(
			'gcp_json_key',                                       // Field ID.
			__( 'Google Cloud JSON Key', 'ai-semantic-internal-links' ), // Field label.
			[ $this, 'field_json_key' ],                         // Callback to render field HTML.
			'ilsej-settings',                                     // Page slug.
			'ilsej_api_section'                                   // Section ID.
		);

		add_settings_field(
			'pinecone_api_key',
			__( 'Pinecone API Key', 'ai-semantic-internal-links' ),
			[ $this, 'field_text' ],                              // Generic text field renderer.
			'ilsej-settings',
			'ilsej_api_section',
			[
				'id'          => 'pinecone_api_key',
				'description' => 'Enter your <a target="_blank" href="https://app.pinecone.io">Pinecone API key</a>. This is used to authenticate requests to your vector database.',
			]
		);

		add_settings_field(
			'pinecone_host',
			__( 'Pinecone Host URL', 'ai-semantic-internal-links' ),
			[ $this, 'field_text' ],
			'ilsej-settings',
			'ilsej_api_section',
			[
				'id'          => 'pinecone_host',
				'description' => 'Enter the index <a href="https://docs.pinecone.io/guides/get-started/quickstart" target="_blank">host URL</a> from your Pinecone dashboard (e.g., https://your-index-host.pinecone.io). Ensure it matches your API key\'s project.',
			]
		);

		add_settings_field(
			'extraction_primary',
			__( 'Entity Extraction: Primary', 'ai-semantic-internal-links' ),
			[ $this, 'field_select' ],
			'ilsej-settings',
			'ilsej_api_section',
			[
				'id'          => 'extraction_primary',
				'options'     => ILSEJ_Core::EXTRACTION_PROVIDERS,
				'description' => __( 'The AI provider that picks anchor phrases for Bulk Auto Suggestion.', 'ai-semantic-internal-links' ),
			]
		);

		add_settings_field(
			'extraction_fallback',
			__( 'Entity Extraction: Fallback', 'ai-semantic-internal-links' ),
			[ $this, 'field_select' ],
			'ilsej-settings',
			'ilsej_api_section',
			[
				'id'          => 'extraction_fallback',
				'options'     => array_merge( ILSEJ_Core::EXTRACTION_PROVIDERS, [ 'none' => __( 'None', 'ai-semantic-internal-links' ) ] ),
				'description' => __( 'Runs when the primary fails: no key, an error, a timeout, a refusal or an unusable answer. The article text is then also sent to this provider. Choose None to send it to the primary only.', 'ai-semantic-internal-links' ),
			]
		);

		add_settings_field(
			'claude_model',
			__( 'Claude Model', 'ai-semantic-internal-links' ),
			[ $this, 'field_select' ],
			'ilsej-settings',
			'ilsej_api_section',
			[
				'id'          => 'claude_model',
				'options'     => ILSEJ_Core::CLAUDE_MODELS,
				'description' => __( 'Model used when Claude extracts. Opus is the most capable; Sonnet and Haiku cost less per article.', 'ai-semantic-internal-links' ),
			]
		);

		add_settings_field(
			'anthropic_api_key',
			__( 'Anthropic API Key', 'ai-semantic-internal-links' ),
			[ $this, 'field_text' ],
			'ilsej-settings',
			'ilsej_api_section',
			[
				'id'          => 'anthropic_api_key',
				'description' => 'Used when Claude extracts entities. Create a key in the <a target="_blank" href="https://platform.claude.com/settings/keys">Claude Console</a>.',
			]
		);

		add_settings_field(
			'openai_api_key',
			__( 'OpenAI API Key', 'ai-semantic-internal-links' ),
			[ $this, 'field_text' ],
			'ilsej-settings',
			'ilsej_api_section',
			[
				'id'          => 'openai_api_key',
				'description' => 'Used when OpenAI extracts entities. Create or find your API key in the <a target="_blank" href="https://platform.openai.com/api-keys">OpenAI Dashboard</a>.',
			]
		);

		// Indexing Settings Section.
		// Controls which content types are indexed.
		add_settings_section(
			'ilsej_indexing_section',
			__( 'Indexing Settings', 'ai-semantic-internal-links' ),
			'__return_null',
			'ilsej-settings'
		);

		add_settings_field(
			'post_types',
			__( 'Post Types to Index', 'ai-semantic-internal-links' ),
			[ $this, 'field_post_types' ],                       // Custom renderer for checkboxes.
			'ilsej-settings',
			'ilsej_indexing_section'
		);

		add_settings_field(
			'indexed_page_ids',
			__( 'Extra Indexed Page IDs', 'ai-semantic-internal-links' ),
			[ $this, 'field_indexed_page_ids' ],
			'ilsej-settings',
			'ilsej_indexing_section'
		);

		// Advanced Settings Section.
		add_settings_section(
			'ilsej_advanced_section',
			__( 'Advanced Settings', 'ai-semantic-internal-links' ),
			'__return_null',
			'ilsej-settings'
		);

		add_settings_field(
			'batch_size',
			__( 'Batch Size', 'ai-semantic-internal-links' ),
			[ $this, 'field_batch_size' ],
			'ilsej-settings',
			'ilsej_advanced_section'
		);

		add_settings_field(
			'enable_retry',
			__( 'Retry Logic', 'ai-semantic-internal-links' ),
			[ $this, 'field_enable_retry' ],
			'ilsej-settings',
			'ilsej_advanced_section'
		);

		add_settings_field(
			'enable_rate_limiting',
			__( 'Rate Limiting', 'ai-semantic-internal-links' ),
			[ $this, 'field_enable_rate_limiting' ],
			'ilsej-settings',
			'ilsej_advanced_section'
		);

		add_settings_field(
			'enable_category_filter',
			__( 'Category Filter', 'ai-semantic-internal-links' ),
			[ $this, 'field_enable_category_filter' ],
			'ilsej-settings',
			'ilsej_advanced_section'
		);

		add_settings_field(
			'enable_tag_filter',
			__( 'Tag Filter', 'ai-semantic-internal-links' ),
			[ $this, 'field_enable_tag_filter' ],
			'ilsej-settings',
			'ilsej_advanced_section'
		);

		add_settings_field(
			'min_relevance_score',
			__( 'Minimum Relevance Score', 'ai-semantic-internal-links' ),
			[ $this, 'field_min_relevance_score' ],
			'ilsej-settings',
			'ilsej_advanced_section'
		);

		add_settings_field(
			'excluded_categories',
			__( 'Excluded Categories', 'ai-semantic-internal-links' ),
			[ $this, 'field_excluded_categories' ],
			'ilsej-settings',
			'ilsej_advanced_section'
		);

		add_settings_field(
			'excluded_tags',
			__( 'Excluded Tags', 'ai-semantic-internal-links' ),
			[ $this, 'field_excluded_tags' ],
			'ilsej-settings',
			'ilsej_advanced_section'
		);

		add_settings_field(
			'delete_data_on_uninstall',
			__( 'Delete Data on Uninstall', 'ai-semantic-internal-links' ),
			[ $this, 'field_delete_data_on_uninstall' ],
			'ilsej-settings',
			'ilsej_advanced_section'
		);
	}

	/**
	 * SANITIZE SETTINGS INPUT
	 *
	 * This function is called automatically by WordPress when the settings form
	 * is submitted. It sanitizes all user input before saving to the database.
	 *
	 * SECURITY IMPORTANCE:
	 * - Prevents XSS (Cross-Site Scripting) attacks
	 * - Prevents SQL injection (though WordPress handles this, extra layer)
	 * - Validates data types and formats
	 * - Removes malicious code from user input
	 *
	 * SANITIZATION METHODS USED:
	 * - wp_kses_post(): Allows safe HTML tags for JSON (Google service account JSON)
	 * - sanitize_text_field(): Strips HTML, removes line breaks, trims whitespace
	 * - array_intersect(): Validates post types against WordPress's registered types
	 *
	 * WHY VALIDATE POST TYPES:
	 * - Prevents users from indexing non-existent post types
	 * - Ensures only public post types are selectable (security)
	 * - Prevents errors when iterating over post types later
	 *
	 * @param array<string, mixed> $input Raw user input from form submission.
	 * @return array<string, mixed> Sanitized settings array ready for database storage.
	 */
	public function sanitize_settings( $input ) {
		$output = [];
		$errors = [];

		// Note: WordPress options.php already calls wp_unslash() before update_option(),
		// so the input arrives already unslashed. Do NOT call wp_unslash() again here
		// as double-unslashing corrupts values containing backslashes.

		// 1. Sanitize Inputs.
		// For API keys: sanitize_text_field() then strip invisible Unicode characters
		// (zero-width spaces, non-breaking spaces, BOM, etc.) that can be introduced
		// when copy-pasting keys from websites, emails, or rich text documents.
		// These invisible characters cause API validation to fail while the key
		// appears correct visually in the form field.
		$output['gcp_json_key']      = isset( $input['gcp_json_key'] ) ? wp_kses_post( $input['gcp_json_key'] ) : '';
		$output['pinecone_api_key']  = isset( $input['pinecone_api_key'] ) ? preg_replace( '/[^\x21-\x7E]/', '', sanitize_text_field( $input['pinecone_api_key'] ) ) : '';
		$output['openai_api_key']    = isset( $input['openai_api_key'] ) ? preg_replace( '/[^\x21-\x7E]/', '', sanitize_text_field( $input['openai_api_key'] ) ) : '';
		$output['anthropic_api_key'] = isset( $input['anthropic_api_key'] ) ? preg_replace( '/[^\x21-\x7E]/', '', sanitize_text_field( $input['anthropic_api_key'] ) ) : '';

		// Provider and model choices: anything outside the allowed lists becomes
		// the default, and a fallback equal to the primary becomes 'none'.
		$output = array_merge(
			$output,
			array_intersect_key(
				ILSEJ_Core::normalize_extraction_settings(
					[
						'claude_model'        => isset( $input['claude_model'] ) ? sanitize_text_field( (string) $input['claude_model'] ) : '',
						'extraction_primary'  => isset( $input['extraction_primary'] ) ? sanitize_key( (string) $input['extraction_primary'] ) : '',
						'extraction_fallback' => isset( $input['extraction_fallback'] ) ? sanitize_key( (string) $input['extraction_fallback'] ) : '',
					]
				),
				array_flip( [ 'claude_model', 'extraction_primary', 'extraction_fallback' ] )
			)
		);

		$raw_host = isset( $input['pinecone_host'] ) ? sanitize_text_field( $input['pinecone_host'] ) : '';
		if ( '' !== $raw_host && ! preg_match( '/^(https:\/\/)?[a-z0-9-]+(\.[a-z0-9-]+)*\.pinecone\.io\/?$/i', $raw_host ) ) {
			$errors['pinecone_host'] = __( 'Pinecone host must be a valid *.pinecone.io URL.', 'ai-semantic-internal-links' );
			$raw_host                = '';
		}
		$output['pinecone_host'] = $raw_host;

		// 2. Perform Live Validation.
		// Each call is wrapped in try/catch so a timeout on one API doesn't
		// block the others or prevent settings from saving.
		require_once ILSEJ_DIR . 'includes/google-vertex-ai.php';
		require_once ILSEJ_DIR . 'includes/functions-internal-links.php';

		// Validate Google Cloud Key.
		if ( ! empty( $output['gcp_json_key'] ) ) {
			try {
				$v_res = ilsej_vertex_validate_key( $output['gcp_json_key'] );
				if ( true !== $v_res ) {
					$errors['gcp_json_key'] = $v_res;
				}
			} catch ( Exception $e ) {
				$errors['gcp_json_key'] = __( 'Validation timed out. Key saved — please verify manually.', 'ai-semantic-internal-links' );
			}
		}

		// Validate Pinecone.
		if ( ! empty( $output['pinecone_api_key'] ) && ! empty( $output['pinecone_host'] ) ) {
			try {
				$v_res = ilsej_pinecone_validate_key( $output['pinecone_api_key'], $output['pinecone_host'] );
				if ( true !== $v_res ) {
					if ( 'key' === $v_res['type'] || 'both' === $v_res['type'] ) {
						$errors['pinecone_api_key'] = $v_res['message'];
					}
					if ( 'host' === $v_res['type'] || 'both' === $v_res['type'] ) {
						$errors['pinecone_host'] = $v_res['message'];
					}
				}
			} catch ( Exception $e ) {
				$errors['pinecone_api_key'] = __( 'Validation timed out. Key saved — please verify manually.', 'ai-semantic-internal-links' );
			}
		}

		// Validate OpenAI.
		if ( ! empty( $output['openai_api_key'] ) ) {
			try {
				$v_res = ilsej_openai_validate_key( $output['openai_api_key'] );
				if ( true !== $v_res ) {
					$errors['openai_api_key'] = $v_res;
				}
			} catch ( Exception $e ) {
				$errors['openai_api_key'] = __( 'Validation timed out. Key saved — please verify manually.', 'ai-semantic-internal-links' );
			}
		}

		// Validate Anthropic.
		if ( ! empty( $output['anthropic_api_key'] ) ) {
			try {
				$v_res = ilsej_anthropic_validate_key( $output['anthropic_api_key'] );
				if ( true !== $v_res ) {
					$errors['anthropic_api_key'] = $v_res;
				}
			} catch ( Exception $e ) {
				$errors['anthropic_api_key'] = __( 'Validation timed out. Key saved — please verify manually.', 'ai-semantic-internal-links' );
			}
		}

		if ( ! empty( $errors ) ) {
			set_transient( 'ilsej_settings_errors', $errors, 60 );
			add_settings_error( 'ilsej_settings_group', 'ilsej_api_error', __( 'Some API keys could not be validated. Please check the errors below.', 'ai-semantic-internal-links' ), 'error' );
		} else {
			delete_transient( 'ilsej_settings_errors' );
			add_settings_error( 'ilsej_settings_group', 'ilsej_settings_saved', __( 'Settings saved.', 'ai-semantic-internal-links' ), 'updated' );
		}

		// 3. Post Types.
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$sanitized_types      = array_map( 'sanitize_key', $input['post_types'] );
			$public_post_types    = get_post_types( [ 'public' => true ], 'names' );
			$output['post_types'] = array_intersect( $sanitized_types, $public_post_types );
		} else {
			$output['post_types'] = [ 'post', 'page' ];
		}

		// 3b. Extra indexed page IDs (comma/space separated).
		if ( isset( $input['indexed_page_ids'] ) ) {
			$raw                        = is_array( $input['indexed_page_ids'] )
				? $input['indexed_page_ids']
				: preg_split( '/[\\s,]+/', (string) $input['indexed_page_ids'], -1, PREG_SPLIT_NO_EMPTY );
			$output['indexed_page_ids'] = array_values( array_filter( array_map( 'absint', (array) $raw ) ) );
		} else {
			$output['indexed_page_ids'] = [];
		}

		// 4. Advanced Settings.
		$output['batch_size']               = isset( $input['batch_size'] ) ? max( 10, min( 100, (int) $input['batch_size'] ) ) : 50;
		$output['enable_retry']             = ! empty( $input['enable_retry'] );
		$output['enable_rate_limiting']     = ! empty( $input['enable_rate_limiting'] );
		$output['enable_category_filter']   = ! empty( $input['enable_category_filter'] );
		$output['enable_tag_filter']        = ! empty( $input['enable_tag_filter'] );
		$output['min_relevance_score']      = isset( $input['min_relevance_score'] ) ? max( 0.0, min( 1.0, (float) $input['min_relevance_score'] ) ) : 0.60;
		$output['excluded_categories']      = $this->sanitize_term_slugs( $input['excluded_categories'] ?? null, 'category' );
		$output['excluded_tags']            = $this->sanitize_term_slugs( $input['excluded_tags'] ?? null, 'post_tag' );
		$output['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		// 5. Encrypt API keys before saving.
		require_once ILSEJ_DIR . 'includes/ilsej-security.php';
		$security = new ILSEJ_Security();
		foreach ( ILSEJ_Core::ENCRYPTED_KEYS as $key ) {
			if ( ! empty( $output[ $key ] ) ) {
				$output[ $key ] = $security->encrypt_api_key( $output[ $key ] );
			}
		}

		// 6. Preserve internal flags from existing settings.
		$existing = get_option( ILSEJ_Core::OPTION_KEY, [] );
		if ( ! empty( $existing['_v2_migrated'] ) ) {
			$output['_v2_migrated'] = true;
		}

		// 7. Invalidate the settings cache so subsequent get_settings() reads fresh data.
		ILSEJ_Core::invalidate_settings_cache();

		return $output;
	}

	/**
	 * Render the Google Cloud JSON key textarea field.
	 *
	 * @return void
	 */
	public function field_json_key() {
		$settings = ILSEJ_Core::get_settings();
		$errors   = get_transient( 'ilsej_settings_errors' );
		$error    = isset( $errors['gcp_json_key'] ) ? $errors['gcp_json_key'] : '';

		$placeholder = '{ "type": "service_account", "project_id": "", "private_key_id": "", "private_key": "", "client_email": "", "client_id": "", "auth_uri": "", "token_uri": "", "auth_provider_x509_cert_url": "", "client_x509_cert_url": "", "universe_domain": "" }';

		echo '<div class="ilsej-mask-wrapper">';
		echo '<textarea id="ilsej-gcp-json-key" placeholder="' . esc_attr( $placeholder ) . '" name="' . esc_attr( ILSEJ_Core::OPTION_KEY ) . '[gcp_json_key]" rows="10" cols="50" class="large-text code ilsej-blur">' . esc_textarea( $settings['gcp_json_key'] ) . '</textarea>';
		echo '<button type="button" class="ilsej-toggle-mask" title="' . esc_attr__( 'Show/Hide', 'ai-semantic-internal-links' ) . '"><span class="dashicons dashicons-visibility"></span></button>';
		echo '</div>';

		// Hidden file input and "Load from File" button - reads file client-side via JavaScript.
		echo '<div style="margin-top: 8px;">';
		echo '<input type="file" id="ilsej-json-file-input" accept=".json,application/json" style="display: none;" />';
		echo '<button type="button" id="ilsej-load-json-file" class="button button-secondary"><span class="dashicons dashicons-upload" style="vertical-align: middle; margin-right: 4px;"></span>' . esc_html__( 'Load from JSON Key File', 'ai-semantic-internal-links' ) . '</button>';
		echo '</div>';

		if ( ! empty( $error ) ) {
			echo '<p style="color: #d63638; font-weight: bold; margin-top: 5px;"><strong>' . esc_html__( 'Configuration Error:', 'ai-semantic-internal-links' ) . '</strong> ' . esc_html( $error ) . '</p>';
		}

		echo '<p class="description">' . wp_kses(
			sprintf(
				/* translators: %s: link to Google Cloud credentials docs */
				__( 'Paste the contents of your %s. This is required for generating text embeddings via Vertex AI.', 'ai-semantic-internal-links' ),
				'<a href="https://developers.google.com/workspace/guides/create-credentials#service-account" target="_blank">' . __( 'Google Cloud service account JSON key', 'ai-semantic-internal-links' ) . '</a>'
			),
			[
				'a' => [
					'href'   => [],
					'target' => [],
				],
			]
		) . '</p>';
	}

	/**
	 * Render a generic text input field.
	 *
	 * @param array<string, string> $args Field arguments including 'id' and optional 'description'.
	 * @return void
	 */
	public function field_text( $args ) {
		$settings = ILSEJ_Core::get_settings();
		$errors   = get_transient( 'ilsej_settings_errors' );
		$id       = $args['id'];
		$value    = isset( $settings[ $id ] ) ? $settings[ $id ] : '';
		$error    = isset( $errors[ $id ] ) ? $errors[ $id ] : '';

		// Check if this is a sensitive field (like an API key).
		$is_sensitive = ( strpos( $id, 'api_key' ) !== false );
		$input_type   = $is_sensitive ? 'password' : 'text';

		echo '<div class="ilsej-mask-wrapper">';
		printf(
			'<input type="%1$s" name="%2$s[%3$s]" value="%4$s" class="regular-text %5$s" />',
			esc_attr( $input_type ),
			esc_attr( ILSEJ_Core::OPTION_KEY ),
			esc_attr( $id ),
			esc_attr( $value ),
			esc_attr( ! empty( $error ) ? 'error' : '' )
		);

		if ( $is_sensitive ) {
			echo '<button type="button" class="ilsej-toggle-mask" title="' . esc_attr__( 'Show/Hide', 'ai-semantic-internal-links' ) . '"><span class="dashicons dashicons-visibility"></span></button>';
		}
		echo '</div>';

		if ( ! empty( $error ) ) {
			echo '<p style="color: #d63638; font-weight: bold; margin-top: 5px;"><strong>' . esc_html__( 'Validation Error:', 'ai-semantic-internal-links' ) . '</strong> ' . esc_html( $error ) . '</p>';
		}

		if ( isset( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Render a select field from an options list.
	 *
	 * @param array{id: string, options: array<string, string>, description?: string} $args Field arguments.
	 * @return void
	 */
	public function field_select( $args ) {
		$settings = ILSEJ_Core::get_settings();
		$id       = $args['id'];
		$current  = isset( $settings[ $id ] ) ? (string) $settings[ $id ] : '';

		printf(
			'<select id="ilsej-%1$s" name="%2$s[%1$s]">',
			esc_attr( $id ),
			esc_attr( ILSEJ_Core::OPTION_KEY )
		);
		foreach ( $args['options'] as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( $current, (string) $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		if ( isset( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}

	/**
	 * Render the post types checkbox field.
	 *
	 * @return void
	 */
	public function field_post_types() {
		$settings       = ILSEJ_Core::get_settings();
		$selected_types = isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? $settings['post_types'] : [ 'post', 'page' ];

		// Get all public post types (exclude attachment/media).
		$public_post_types = get_post_types( [ 'public' => true ], 'objects' );

		// Filter out attachment (Media) post type.
		$filtered_post_types = [];
		foreach ( $public_post_types as $post_type ) {
			if ( 'attachment' !== $post_type->name ) {
				$filtered_post_types[] = $post_type;
			}
		}

		if ( empty( $filtered_post_types ) ) {
			echo '<p>' . esc_html__( 'No public post types found.', 'ai-semantic-internal-links' ) . '</p>';
			return;
		}

		echo '<fieldset>';
		echo '<p class="description">' . esc_html__( 'Select which post types should be indexed for internal linking suggestions.', 'ai-semantic-internal-links' ) . '</p>';

		foreach ( $filtered_post_types as $post_type ) {
			$is_checked = in_array( $post_type->name, $selected_types, true );
			printf(
				'<label style="display: block; margin-bottom: 8px;"><input type="checkbox" name="%1$s[post_types][]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( ILSEJ_Core::OPTION_KEY ),
				esc_attr( $post_type->name ),
				checked( $is_checked, true, false ),
				esc_html( $post_type->label )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Render the extra indexed page IDs field.
	 *
	 * Selective page indexing: a few key pages belong in the semantic index,
	 * but indexing the whole `page` type would drag every policy and team
	 * page into Pinecone.
	 *
	 * @return void
	 */
	public function field_indexed_page_ids() {
		$settings = ILSEJ_Core::get_settings();
		$ids      = isset( $settings['indexed_page_ids'] ) && is_array( $settings['indexed_page_ids'] ) ? $settings['indexed_page_ids'] : [];
		printf(
			'<input type="text" class="regular-text" name="%1$s[indexed_page_ids]" value="%2$s" />',
			esc_attr( ILSEJ_Core::OPTION_KEY ),
			esc_attr( implode( ', ', array_map( 'strval', $ids ) ) )
		);
		echo '<p class="description">' . esc_html__( 'Comma-separated page IDs to index in addition to the post types above (e.g. pillar or resource pages). Empty means no extra pages.', 'ai-semantic-internal-links' ) . '</p>';
	}

	/**
	 * Render the batch size number field.
	 *
	 * @return void
	 */
	public function field_batch_size() {
		$settings   = ILSEJ_Core::get_settings();
		$batch_size = isset( $settings['batch_size'] ) ? (int) $settings['batch_size'] : 50;
		printf(
			'<input type="number" name="%1$s[batch_size]" value="%2$s" min="10" max="100" class="small-text" />',
			esc_attr( ILSEJ_Core::OPTION_KEY ),
			esc_attr( (string) $batch_size )
		);
		echo '<p class="description">' . esc_html__( 'Posts to process per batch (10-100). Higher values are faster but use more memory. Default: 50.', 'ai-semantic-internal-links' ) . '</p>';
	}

	/**
	 * Render the enable retry checkbox field.
	 *
	 * @return void
	 */
	public function field_enable_retry() {
		$settings = ILSEJ_Core::get_settings();
		$enabled  = isset( $settings['enable_retry'] ) ? (bool) $settings['enable_retry'] : true;
		printf(
			'<label><input type="checkbox" name="%1$s[enable_retry]" value="1" %2$s /> %3$s</label>',
			esc_attr( ILSEJ_Core::OPTION_KEY ),
			checked( $enabled, true, false ),
			esc_html__( 'Automatically retry failed API calls with exponential backoff (5 attempts, recommended).', 'ai-semantic-internal-links' )
		);
	}

	/**
	 * Render the enable rate limiting checkbox field.
	 *
	 * @return void
	 */
	public function field_enable_rate_limiting() {
		$settings = ILSEJ_Core::get_settings();
		$enabled  = isset( $settings['enable_rate_limiting'] ) ? (bool) $settings['enable_rate_limiting'] : true;
		printf(
			'<label><input type="checkbox" name="%1$s[enable_rate_limiting]" value="1" %2$s /> %3$s</label>',
			esc_attr( ILSEJ_Core::OPTION_KEY ),
			checked( $enabled, true, false ),
			esc_html__( 'Throttle API calls to prevent hitting external rate limits (recommended).', 'ai-semantic-internal-links' )
		);
	}

	/**
	 * Render the enable category filter checkbox field.
	 *
	 * @return void
	 */
	public function field_enable_category_filter() {
		$settings = ILSEJ_Core::get_settings();
		$enabled  = isset( $settings['enable_category_filter'] ) ? (bool) $settings['enable_category_filter'] : true;
		printf(
			'<label><input type="checkbox" name="%1$s[enable_category_filter]" value="1" %2$s /> %3$s</label>',
			esc_attr( ILSEJ_Core::OPTION_KEY ),
			checked( $enabled, true, false ),
			esc_html__( 'Show category filter in the editor sidebar and modals for link suggestions.', 'ai-semantic-internal-links' )
		);
	}

	/**
	 * Render the enable tag filter checkbox field.
	 *
	 * @return void
	 */
	public function field_enable_tag_filter() {
		$settings = ILSEJ_Core::get_settings();
		$enabled  = isset( $settings['enable_tag_filter'] ) ? (bool) $settings['enable_tag_filter'] : true;
		printf(
			'<label><input type="checkbox" name="%1$s[enable_tag_filter]" value="1" %2$s /> %3$s</label>',
			esc_attr( ILSEJ_Core::OPTION_KEY ),
			checked( $enabled, true, false ),
			esc_html__( 'Show tag filter in the editor sidebar and modals for link suggestions (requires re-indexing).', 'ai-semantic-internal-links' )
		);
	}

	/**
	 * Render the minimum relevance score field.
	 *
	 * @return void
	 */
	public function field_min_relevance_score() {
		$settings = ILSEJ_Core::get_settings();
		$value    = isset( $settings['min_relevance_score'] ) ? (float) $settings['min_relevance_score'] : 0.60;
		printf(
			'<input type="number" name="%1$s[min_relevance_score]" value="%2$s" min="0" max="1" step="0.05" style="width:80px;" /> <span class="description">%3$s</span>',
			esc_attr( ILSEJ_Core::OPTION_KEY ),
			esc_attr( number_format( $value, 2 ) ),
			esc_html__( 'Suggestions with a Pinecone similarity score below this value will be filtered out. Range: 0.00–1.00 (recommended: 0.55–0.70).', 'ai-semantic-internal-links' )
		);
	}

	/**
	 * Render the excluded-categories checkbox list.
	 *
	 * @return void
	 */
	public function field_excluded_categories() {
		$this->render_term_checkbox_list(
			'category',
			'excluded_categories',
			$this->get_selected_term_slugs( 'excluded_categories' ),
			__( 'Posts in these categories will not appear as link suggestions in the editor and are filtered out of Pinecone results. Recommended for press-release / news category slugs.', 'ai-semantic-internal-links' )
		);
	}

	/**
	 * Render the excluded-tags checkbox list.
	 *
	 * @return void
	 */
	public function field_excluded_tags() {
		$this->render_term_checkbox_list(
			'post_tag',
			'excluded_tags',
			$this->get_selected_term_slugs( 'excluded_tags' ),
			__( 'Posts with these tags will not appear as link suggestions in the editor and are filtered out of Pinecone results. Recommended for news / sponsored-post tag slugs.', 'ai-semantic-internal-links' )
		);
	}

	/**
	 * Read a settings field as a list of term slug strings (filtered).
	 *
	 * @param string $key Settings key.
	 * @return list<string>
	 */
	protected function get_selected_term_slugs( string $key ): array {
		$settings = ILSEJ_Core::get_settings();
		$raw      = $settings[ $key ] ?? [];
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$out[] = $value;
			}
		}
		return $out;
	}

	/**
	 * Render a scrollable checkbox list of taxonomy term slugs.
	 *
	 * @param string             $taxonomy    Taxonomy name (category or post_tag).
	 * @param string             $field_name  Settings field key.
	 * @param array<int, string> $selected    Currently selected slugs.
	 * @param string             $description Description shown below the list.
	 * @return void
	 */
	protected function render_term_checkbox_list( string $taxonomy, string $field_name, array $selected, string $description ) {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			]
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			echo '<p class="description">' . esc_html__( 'No terms registered yet.', 'ai-semantic-internal-links' ) . '</p>';
			return;
		}

		$option_key  = ILSEJ_Core::OPTION_KEY;
		$input_name  = sprintf( '%s[%s][]', $option_key, $field_name );
		$selected_lc = array_map( 'strtolower', $selected );

		echo '<fieldset>';
		echo '<div style="max-height:180px;overflow:auto;border:1px solid #ddd;padding:8px;background:#fff;border-radius:3px;">';
		foreach ( $terms as $term ) {
			$slug    = $term->slug;
			$is_chk  = in_array( strtolower( $slug ), $selected_lc, true );
			$display = sprintf( '%s (%s)', $term->name, $slug );
			printf(
				'<label style="display:block;padding:2px 0;"><input type="checkbox" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( $input_name ),
				esc_attr( $slug ),
				checked( $is_chk, true, false ),
				esc_html( $display )
			);
		}
		echo '</div>';
		echo '<p class="description">' . esc_html( $description ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Sanitize a list of term slugs against the live taxonomy.
	 *
	 * @param mixed  $raw      Raw input from the settings form.
	 * @param string $taxonomy Taxonomy to validate against.
	 * @return list<string> Valid slug list (may be empty).
	 */
	protected function sanitize_term_slugs( $raw, string $taxonomy ): array {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return [];
		}
		$sanitized = [];
		foreach ( $raw as $slug ) {
			if ( ! is_string( $slug ) ) {
				continue;
			}
			$clean = sanitize_key( $slug );
			if ( '' !== $clean ) {
				$sanitized[] = $clean;
			}
		}
		if ( empty( $sanitized ) ) {
			return [];
		}
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'fields'     => 'id=>slug',
			]
		);
		if ( is_wp_error( $terms ) ) {
			return [];
		}
		$valid = array_values( $terms );
		return array_values( array_intersect( $sanitized, $valid ) );
	}

	/**
	 * Render the delete data on uninstall checkbox field.
	 *
	 * @return void
	 */
	public function field_delete_data_on_uninstall() {
		$settings = ILSEJ_Core::get_settings();
		$enabled  = ! empty( $settings['delete_data_on_uninstall'] );
		printf(
			'<label><input type="checkbox" name="%1$s[delete_data_on_uninstall]" value="1" %2$s /> %3$s</label>',
			esc_attr( ILSEJ_Core::OPTION_KEY ),
			checked( $enabled, true, false ),
			esc_html__( 'Remove all plugin settings, transients, and cron jobs when the plugin is deleted.', 'ai-semantic-internal-links' )
		);
		echo '<p class="description">' . esc_html__( 'Leave unchecked if you plan to reinstall the plugin later. Your Pinecone index is not affected.', 'ai-semantic-internal-links' ) . '</p>';
		echo '<p id="ilsej-delete-warning" class="description" style="color: #d63638; font-weight: 600; display: none;">' . esc_html__( 'Warning: this is irreversible. All the data will be permanently deleted.', 'ai-semantic-internal-links' ) . '</p>';
		?>
		<script>
		(function() {
			var c = document.querySelector('input[name="<?php echo esc_js( ILSEJ_Core::OPTION_KEY ); ?>[delete_data_on_uninstall]"]');
			var w = document.getElementById('ilsej-delete-warning');
			if (!c || !w) return;
			function t() { w.style.display = c.checked ? '' : 'none'; }
			t();
			c.addEventListener('change', t);
		})();
		</script>
		<?php
	}

	/**
	 * Render the plugin settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		echo '<div class="wrap"><h1>' . esc_html__( 'Internal Linking AI Settings', 'ai-semantic-internal-links' ) . '</h1>';
		settings_errors( 'ilsej_settings_group' );
		echo '<form method="post" action="options.php">';
		settings_fields( 'ilsej_settings_group' );
		do_settings_sections( 'ilsej-settings' );
		submit_button();
		echo '</form>';

		// Notes Section.
		echo '<div style="margin-top: 40px; border-top: 1px solid #ccc; padding-top: 20px;">';
		echo '<h2 style="margin-top: 0;">' . esc_html__( 'Troubleshooting & Optimization', 'ai-semantic-internal-links' ) . '</h2>';
		echo '<p style="font-size: 14px; line-height: 1.6;">' . esc_html__( 'If the indexing or suggestion processes time out, you may need to increase your server\'s PHP execution time limit (max_execution_time). A value of 60–90 seconds is usually sufficient for most sites.', 'ai-semantic-internal-links' ) . '</p>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Render the articles indexing page.
	 *
	 * @return void
	 */
	public function render_indexing_page() {
		$settings = ILSEJ_Core::get_settings();
		$missing  = [];
		if ( empty( $settings['pinecone_api_key'] ) ) {
			$missing[] = 'Pinecone API Key';
		}
		if ( empty( $settings['pinecone_host'] ) ) {
			$missing[] = 'Pinecone Host URL';
		}
		if ( empty( $settings['gcp_json_key'] ) ) {
			$missing[] = 'Google Cloud JSON Key';
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Articles Indexing', 'ai-semantic-internal-links' ) . '</h1>';

		if ( ! empty( $missing ) ) {
			echo '<div class="notice notice-warning"><p>' . sprintf(
				/* translators: 1: list of missing credentials, 2: opening <a> tag, 3: closing </a> tag */
				esc_html__( 'The following credentials are required for indexing: %1$s. Please configure them on the %2$sSettings%3$s page before proceeding.', 'ai-semantic-internal-links' ),
				'<strong>' . esc_html( implode( ', ', $missing ) ) . '</strong>',
				'<a href="' . esc_url( admin_url( 'admin.php?page=ilsej-settings' ) ) . '">',
				'</a>'
			) . '</p></div>';
			return;
		}

		// Fetch index stats for status display.
		require_once ILSEJ_DIR . 'includes/ilsej-indexer.php';
		$vector_count = ILSEJ_Indexer::get_index_stats();
		$total_posts  = ILSEJ_Indexer::get_total_post_count();

		if ( false !== $vector_count ) {
			$all_indexed = $vector_count >= $total_posts;
			echo '<div id="ilsej-index-status" style="margin-bottom:15px;padding:10px 15px;border-left:4px solid ' . ( $all_indexed ? '#46b450' : '#ffb900' ) . ';background:#fff;">';
			echo '<strong>' . esc_html__( 'Index Status:', 'ai-semantic-internal-links' ) . '</strong> ';
			echo '<span id="ilsej-status-text">';
			echo esc_html(
				sprintf(
					/* translators: 1: indexed count, 2: total posts count */
					__( '%1$d of %2$d posts indexed', 'ai-semantic-internal-links' ),
					$vector_count,
					$total_posts
				)
			);
			echo '</span></div>';
		}

		echo '<p>' . esc_html__( 'Click the button below to synchronize your published content with the Pinecone vector database. This process runs in batches to ensure compatibility with shared hosting environments.', 'ai-semantic-internal-links' ) . '</p>';

		$btn_label = ( false !== $vector_count && $vector_count >= $total_posts )
			? __( 'Re-index All', 'ai-semantic-internal-links' )
			: __( 'Start Indexing', 'ai-semantic-internal-links' );

		echo '<button id="ilsej-start-indexing" class="button button-primary">' . esc_html( $btn_label ) . '</button> ';
		echo '<button id="ilsej-pause-indexing" class="button" style="display:none;">' . esc_html__( 'Pause', 'ai-semantic-internal-links' ) . '</button> ';
		echo '<button id="ilsej-stop-indexing" class="button button-secondary" style="display:none;">' . esc_html__( 'Stop', 'ai-semantic-internal-links' ) . '</button>';
		echo '<div id="ilsej-progress" style="margin-top:20px;max-width:400px;display:none;">'
			. '<div style="background:#e1e1e1;border-radius:3px;overflow:hidden;">
                <div id="ilsej-progress-bar" style="background:#0073aa;height:20px;width:0%;"></div>
              </div>
              <p id="ilsej-progress-text"></p>
        </div>';
		echo '</div>';
	}

	/**
	 * Enqueue admin scripts and styles on plugin pages.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false !== strpos( $hook, 'ilsej-indexing' ) || false !== strpos( $hook, 'ilsej-settings' ) ) {
			// Load CSS for masking/blurring.
			wp_enqueue_style( 'ilsej-admin-css', ILSEJ_URL . 'assets/css/ilsej-admin.css', [], (string) filemtime( ILSEJ_DIR . 'assets/css/ilsej-admin.css' ) );

			// Load JS.
			wp_enqueue_script( 'ilsej-admin', ILSEJ_URL . 'assets/js/ilsej-admin.js', [ 'jquery' ], (string) filemtime( ILSEJ_DIR . 'assets/js/ilsej-admin.js' ), true );
			wp_localize_script(
				'ilsej-admin',
				'ilsejAdmin',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'ilsej_indexing' ),
				]
			);
		}
	}

	/**
	 * AJAX HANDLERS FOR BATCH INDEXING
	 *
	 * These functions handle AJAX requests from the JavaScript frontend (ilsej-admin.js).
	 * They enable the batch indexing interface to work without page refreshes.
	 *
	 * HOW AJAX WORKS IN WordPress:
	 * 1. JavaScript sends POST request to admin-ajax.php
	 * 2. WordPress routes request to registered action hook (wp_ajax_*)
	 * 3. Function processes request and returns JSON response
	 * 4. JavaScript receives response and updates UI
	 *
	 * SECURITY:
	 * - check_ajax_referer(): Verifies nonce (prevents CSRF attacks)
	 * - current_user_can(): Checks user permissions (admin only)
	 * - All functions follow same security pattern
	 *
	 * BATCH INDEXING FLOW:
	 * 1. ajax_start_indexing() - Initializes queue of posts to index
	 * 2. ajax_process_batch() - Processes next batch (called repeatedly via JavaScript)
	 * 3. ajax_toggle_pause() - Pauses/resumes indexing
	 * 4. ajax_stop_indexing() - Stops and clears queue
	 */

	/**
	 * AJAX: START INDEXING PROCESS
	 *
	 * Called when user clicks "Start Indexing" button. Initializes the queue
	 * of all published posts that need to be indexed.
	 *
	 * WHAT IT DOES:
	 * - Gets all published posts of selected post types
	 * - Stores post IDs in WordPress transient (temporary database storage)
	 * - JavaScript then calls ajax_process_batch() repeatedly to process queue
	 *
	 * WHY TRANSIENTS:
	 * - Temporary storage that expires automatically
	 * - Survives page refreshes (unlike JavaScript variables)
	 * - Can be cleared if indexing is stopped
	 *
	 * @return void
	 */
	public function ajax_start_indexing() {
		// Verify nonce (security token) to prevent CSRF attacks.
		check_ajax_referer( 'ilsej_indexing', 'nonce' );

		// Check user has admin permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'ai-semantic-internal-links' ) );
		}

		// Load indexer class and initialize queue.
		require_once ILSEJ_DIR . 'includes/ilsej-indexer.php';

		// Clear any previous recorded errors and failure counter.
		delete_transient( 'ilsej_last_error' );
		delete_transient( 'ilsej_batch_fail_count' );

		ILSEJ_Indexer::init_queue();

		// Send success response to JavaScript.
		wp_send_json_success();
	}

	/**
	 * AJAX: PROCESS NEXT BATCH OF POSTS
	 *
	 * This is called repeatedly by JavaScript to process the indexing queue
	 * in small batches. Processing in batches prevents server timeouts and
	 * allows progress tracking.
	 *
	 * HOW IT WORKS:
	 * 1. Retrieves next batch of post IDs from queue (stored in transient)
	 * 2. Processes each post: extracts content, generates embedding, upserts to Pinecone
	 * 3. Updates progress counter
	 * 4. Returns progress data to JavaScript (percentage, processed count, etc.)
	 *
	 * BATCH SIZE:
	 * - Defined in ILSEJ_Indexer::BATCH_SIZE (currently 2 posts per batch)
	 * - Small batches prevent PHP execution time limits
	 * - JavaScript automatically calls this again until queue is empty
	 *
	 * RESPONSE DATA:
	 * - finished: Boolean indicating if all posts are processed
	 * - progress: Percentage complete (0-100)
	 * - processed: Number of posts processed so far
	 * - total: Total number of posts to process
	 *
	 * @return void
	 */
	public function ajax_process_batch() {
		// Security checks.
		check_ajax_referer( 'ilsej_indexing', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'ai-semantic-internal-links' ) );
		}

		// Process next batch and get response data.
		require_once ILSEJ_DIR . 'includes/ilsej-indexer.php';
		$response = ILSEJ_Indexer::process_next_batch();

		// Check if an error was recorded during this batch.
		$last_error = get_transient( 'ilsej_last_error' );
		if ( $last_error ) {
			$response['error'] = $last_error;
			// We usually want to clear it so it doesn't persist forever if next batches succeed.
			delete_transient( 'ilsej_last_error' );
		}

		// Send response to JavaScript (includes progress, finished status, etc.).
		wp_send_json( $response );
	}

	/**
	 * AJAX handler to stop the indexing process.
	 *
	 * @return void
	 */
	public function ajax_stop_indexing() {
		check_ajax_referer( 'ilsej_indexing', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'ai-semantic-internal-links' ) );
		}
		require_once ILSEJ_DIR . 'includes/ilsej-indexer.php';
		ILSEJ_Indexer::stop();
		wp_send_json_success();
	}

	/**
	 * AJAX: Refresh index stats from Pinecone.
	 *
	 * Called after indexing completes to update the status display
	 * with the latest vector count from Pinecone.
	 *
	 * @return void
	 */
	public function ajax_index_stats() {
		check_ajax_referer( 'ilsej_indexing', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied', 'ai-semantic-internal-links' ) );
		}

		require_once ILSEJ_DIR . 'includes/ilsej-indexer.php';

		// Clear cached stats so we get a fresh count from Pinecone.
		delete_transient( 'ilsej_index_vector_count' );

		$vector_count = ILSEJ_Indexer::get_index_stats();
		$total_posts  = ILSEJ_Indexer::get_total_post_count();

		wp_send_json_success(
			[
				'indexed' => false !== $vector_count ? $vector_count : 0,
				'total'   => $total_posts,
			]
		);
	}
}
