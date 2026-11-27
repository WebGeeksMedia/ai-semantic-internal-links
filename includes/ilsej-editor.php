<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * EDITOR INTEGRATION CLASS
 *
 * This class handles integration with both WordPress editors:
 * - Classic Editor (TinyMCE): Adds toolbar button for link suggestions
 * - Gutenberg Editor: Adds sidebar panel and toolbar modal for link suggestions
 *
 * EDITOR SUPPORT:
 * The plugin works in both editors because the WordPress community is still
 * divided between Classic and Gutenberg. This ensures all users can benefit.
 *
 * FUNCTIONALITY PROVIDED:
 * 1. Manual Anchor Text Mode: User selects text, gets suggestions for that phrase
 * 2. Bulk Auto Suggestion: AI extracts key phrases and suggests links automatically
 * 3. Filtering: Category, post type, and freshness filters for refined results
 *
 * AJAX INTEGRATION:
 * - JavaScript sends requests to WordPress admin-ajax.php
 * - PHP processes requests and returns JSON with suggestions
 * - JavaScript displays results in editor-specific UI
 *
 * SECURITY:
 * - Nonce verification on all AJAX requests
 * - Capability checks (edit_posts permission)
 * - Input sanitization before processing
 *
 * @package AISemanticInternalLinks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ILSEJ_Editor
 *
 * Integrates with TinyMCE and Gutenberg editors for link suggestions.
 */
class ILSEJ_Editor {
	/**
	 * Singleton instance.
	 *
	 * @var ILSEJ_Editor
	 */
	private static $instance = null;

	/**
	 * SINGLETON GETTER
	 * Returns the single instance of ILSEJ_Editor
	 *
	 * @return ILSEJ_Editor
	 */
	public static function instance(): ILSEJ_Editor {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * CONSTRUCTOR - REGISTERS EDITOR INTEGRATIONS
	 *
	 * This method registers all WordPress hooks needed for editor functionality.
	 * It supports both Classic Editor (TinyMCE) and Gutenberg (Block Editor).
	 *
	 * HOOKS REGISTERED:
	 * 1. init: Registers TinyMCE plugin and button
	 * 2. admin_enqueue_scripts: Loads JavaScript for Classic Editor
	 * 3. enqueue_block_editor_assets: Loads JavaScript for Gutenberg
	 * 4. wp_ajax_*: Handles AJAX requests from both editors
	 *
	 * INTELLIGENT LOADING:
	 * - Gutenberg assets only load in block editor
	 * - Classic Editor assets only load in classic editor
	 * - Prevents conflicts and reduces unnecessary script loading
	 */
	private function __construct() {
		// Register TinyMCE plugin and button (Classic Editor).
		add_action( 'init', [ $this, 'register_tinymce_integration' ], 1 );

		// Load JavaScript for Classic Editor (TinyMCE).
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// AJAX handlers for link suggestions.
		add_action( 'wp_ajax_ilsej_get_suggestions', [ $this, 'ajax_get_suggestions' ] );
		add_action( 'wp_ajax_ilsej_get_all_editor_filters', [ $this, 'ilsej_get_all_editor_filters_callback' ] );

		// Load JavaScript for Gutenberg (Block Editor).
		add_action( 'enqueue_block_editor_assets', [ $this, 'ilsej_enqueue_gutenberg_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'ilsej_enqueue_classic_assets' ] );

		// Strip editor-only highlight marks — three safety nets:
		// 1. content_save_pre: strips marks during normal post save.
		// 2. wp_insert_post_data: catches autosave revisions (preview) that may bypass content_save_pre.
		// 3. the_content: output-side fallback — guarantees marks never render on the frontend.
		add_filter( 'content_save_pre', [ $this, 'strip_highlight_marks' ] );
		add_filter( 'wp_insert_post_data', [ $this, 'strip_highlight_marks_from_post_data' ] );
		add_filter( 'the_content', [ $this, 'strip_highlight_marks' ], 1 );
	}

	/**
	 * Strip ilsej-highlight marks from post content string.
	 *
	 * The plugin adds temporary <mark> tags to highlight suggested keywords
	 * in the editor. These are visual aids only and must never be persisted
	 * to the database or appear on the frontend.
	 *
	 * Used by content_save_pre (save-side) and the_content (output-side).
	 *
	 * @param string $content Post content being saved or rendered.
	 * @return string Content with highlight marks stripped.
	 */
	public function strip_highlight_marks( string $content ): string {
		if ( false === strpos( $content, 'ilsej-highlight' ) ) {
			return $content;
		}
		return (string) preg_replace( '/<mark[^>]*class="ilsej-highlight"[^>]*>(.*?)<\/mark>/i', '$1', $content );
	}

	/**
	 * Strip ilsej-highlight marks from post data array before database write.
	 *
	 * Catches autosave revisions (used for preview) that may bypass content_save_pre.
	 * Uses a flexible regex that handles both slashed and unslashed content.
	 *
	 * @param array<string, mixed> $data Post data array.
	 * @return array<string, mixed> Post data with highlight marks stripped from content.
	 */
	public function strip_highlight_marks_from_post_data( array $data ): array {
		if ( ! empty( $data['post_content'] ) && is_string( $data['post_content'] ) && false !== strpos( $data['post_content'], 'ilsej-highlight' ) ) {
			// Handle both slashed (wp_insert_post_data receives slashed data) and unslashed content.
			$data['post_content'] = (string) preg_replace( '/<mark[^>]*ilsej-highlight[^>]*>(.*?)<\/mark>/i', '$1', $data['post_content'] );
		}
		return $data;
	}

	/**
	 * Enqueue Gutenberg editor assets.
	 *
	 * @return void
	 */
	public function ilsej_enqueue_gutenberg_assets() {
		wp_enqueue_script(
			'ilsej-gutenberg-js',
			ILSEJ_URL . 'assets/js/ilsej-gutenberg.js',
			[ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-rich-text', 'wp-block-editor', 'wp-compose' ],
			(string) filemtime( ILSEJ_DIR . 'assets/js/ilsej-gutenberg.js' ),
			true
		);

		// Get current post ID for Gutenberg.
		$post_id = 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading post ID from URL for script localization only.
		if ( isset( $_GET['post'] ) ) {
			$post_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( isset( $_GET['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = absint( wp_unslash( $_GET['post_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$settings = ILSEJ_Core::get_settings();

		wp_localize_script(
			'ilsej-gutenberg-js',
			'ILSEJ',
			[
				'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
				'nonce'                => wp_create_nonce( 'ilsej_suggest' ),
				'postId'               => $post_id,
				'enableCategoryFilter' => ! empty( $settings['enable_category_filter'] ),
				'enableTagFilter'      => ! empty( $settings['enable_tag_filter'] ),
			]
		);
	}

	/**
	 * Enqueue Classic Editor assets.
	 *
	 * @return void
	 */
	public function ilsej_enqueue_classic_assets() {
		// Only load on post edit screens if NOT using Gutenberg.
		$screen = get_current_screen();
		if ( $screen && $screen->is_block_editor() ) {
			return; // Handled by function above.
		}
	}


	/**
	 * AJAX HANDLER: GET EDITOR FILTERS (CATEGORIES AND POST TYPES)
	 *
	 * This function provides filter options to the editor interfaces (both
	 * Classic and Gutenberg). It returns lists of categories and post types
	 * that users can select to filter link suggestions.
	 *
	 * WHAT IT RETURNS:
	 * - Categories: All WordPress categories (for category-based filtering)
	 * - Post Types: Only post types configured for indexing in plugin settings (for post type filtering)
	 *
	 * WHY EXCLUDE ATTACHMENT:
	 * - Media files aren't suitable for internal linking
	 * - Reduces clutter in filter options
	 * - Improves user experience
	 *
	 * DATA FORMAT:
	 * - Each item has 'text' (display name) and 'value' (slug/name)
	 * - Includes "All" option for each filter type
	 * - JavaScript uses this to populate filter checkboxes/dropdowns
	 *
	 * USED BY:
	 * - TinyMCE editor (ilsej-tinymce.js)
	 * - Gutenberg editor (ilsej-gutenberg.js)
	 * - Classic editor (ilsej-editor.js)
	 *
	 * SECURITY:
	 * - check_ajax_referer(): Verifies nonce
	 * - No sensitive data exposed (only public taxonomies and post types)
	 *
	 * @return void Sends JSON response with categories and post types
	 */
	public function ilsej_get_all_editor_filters_callback() {
		// Security checks.
		check_ajax_referer( 'ilsej_suggest', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permission denied', 'webgeeksmedia-ai-semantic-internal-links' ) );
		}

		// Get Categories.
		$categories = get_categories(
			[
				'orderby'    => 'name',
				'order'      => 'ASC',
				'hide_empty' => false,
			]
		);

		$category_options = [];
		// Default "All" option.
		$category_options[] = [
			'text'  => 'All',
			'value' => 'all',
		];

		foreach ( $categories as $category ) {
			$category_options[] = [
				'text'  => $category->name,
				'value' => $category->slug,
			];
		}

		// Get Tags.
		$tags = get_tags(
			[
				'orderby'    => 'name',
				'order'      => 'ASC',
				'hide_empty' => false,
			]
		);

		$tag_options = [];
		// Default "All" option.
		$tag_options[] = [
			'text'  => 'All',
			'value' => 'all',
		];

		if ( ! empty( $tags ) && is_array( $tags ) ) {
			foreach ( $tags as $tag ) {
				$tag_options[] = [
					'text'  => $tag->name,
					'value' => $tag->slug,
				];
			}
		}

		// Get Post Types - Only show post types that are configured for indexing in settings.
		$settings           = ILSEJ_Core::get_settings();
		$allowed_post_types = isset( $settings['post_types'] ) && is_array( $settings['post_types'] )
			? $settings['post_types']
			: [ 'post', 'page' ]; // Default fallback.

		// Get all public post types to access their labels.
		$public_post_types = get_post_types( [ 'public' => true ], 'objects' );
		$post_type_options = [];
		// Default "All" option.
		$post_type_options[] = [
			'text'  => 'All',
			'value' => 'all',
		];

		// Only include post types that are configured for indexing.
		foreach ( $public_post_types as $post_type ) {
			// Only include if it's in the allowed list and not attachment.
			if ( in_array( $post_type->name, $allowed_post_types, true ) && 'attachment' !== $post_type->name ) {
				$post_type_options[] = [
					'text'  => $post_type->label,
					'value' => $post_type->name,
				];
			}
		}

		// Return categories, tags, post types, and filter settings.
		wp_send_json_success(
			[
				'categories' => $category_options,
				'tags'       => $tag_options,
				'post_types' => $post_type_options,
				'settings'   => [
					'enable_category_filter' => ! empty( $settings['enable_category_filter'] ),
					'enable_tag_filter'      => ! empty( $settings['enable_tag_filter'] ),
				],
			]
		);
	}

	/**
	 * Hook TinyMCE filters only when rich editing is on.
	 *
	 * @return void
	 */
	public function register_tinymce_integration() {
		if ( get_user_option( 'rich_editing' ) !== 'true' ) {
			return;
		}

		add_filter( 'mce_external_plugins', [ $this, 'add_tinymce_plugin' ] );
		add_filter( 'mce_buttons', [ $this, 'register_button' ] );
	}

	/**
	 * Register the TinyMCE JavaScript plugin.
	 *
	 * @param array<string, string> $plugins Registered TinyMCE plugins.
	 * @return array<string, string> Modified plugins array.
	 */
	public function add_tinymce_plugin( $plugins ) {
		$plugins['ilsej'] = ILSEJ_URL . 'assets/js/ilsej-tinymce.js?ver=' . (string) filemtime( ILSEJ_DIR . 'assets/js/ilsej-tinymce.js' );
		return $plugins;
	}

	/**
	 * Register the TinyMCE toolbar button.
	 *
	 * @param array<int, string> $buttons Registered TinyMCE buttons.
	 * @return array<int, string> Modified buttons array.
	 */
	public function register_button( $buttons ) {
		array_push( $buttons, 'ilsejSuggestLinks' );
		return $buttons;
	}

	/**
	 * Enqueue editor scripts on post edit screens.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		// Only enqueue on post editor pages.
		if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			// Get current post ID.
			$post_id = 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading post ID from URL for script localization only.
			if ( isset( $_GET['post'] ) ) {
				$post_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			} elseif ( isset( $_GET['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$post_id = absint( wp_unslash( $_GET['post_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}

			wp_enqueue_script( 'ilsej-suggestions', ILSEJ_URL . 'assets/js/ilsej-editor.js', [ 'jquery' ], (string) filemtime( ILSEJ_DIR . 'assets/js/ilsej-editor.js' ), true );

			$settings = ILSEJ_Core::get_settings();

			wp_localize_script(
				'ilsej-suggestions',
				'ILSEJ',
				[
					'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
					'nonce'                => wp_create_nonce( 'ilsej_suggest' ),
					'postId'               => $post_id,
					'enableCategoryFilter' => ! empty( $settings['enable_category_filter'] ),
					'enableTagFilter'      => ! empty( $settings['enable_tag_filter'] ),
				]
			);
		}
	}

	/* ----------- AJAX ----------- */
	/**
	 * AJAX HANDLER: GET INTERNAL LINK SUGGESTIONS
	 *
	 * This is the main AJAX endpoint that processes link suggestion requests
	 * from both Classic and Gutenberg editors. It handles two modes:
	 *
	 * MODES:
	 * 1. 'anchor' mode: User-selected phrase (manual anchor text)
	 *    - Converts phrase to vector → searches Pinecone → returns matches
	 * 2. 'bulk' mode: AI-extracted phrases (automatic suggestions)
	 *    - Uses the primary AI provider (fallback on failure) to extract key phrases → converts each to vector → searches → returns matches
	 *
	 * PROCESSING FLOW:
	 * 1. Security checks (nonce verification, capability check)
	 * 2. Get content from AJAX request (selected text or full article)
	 * 3. Apply WordPress content filters (process blocks, shortcodes)
	 * 4. Set up Pinecone connection settings
	 * 5. Process filters (category, post type, publish year)
	 * 6. Get current post ID (to exclude from suggestions)
	 * 7. Call appropriate function based on mode:
	 *    - ilsej_internal_link_by_kw(): For anchor mode (keyword-based)
	 *    - ilsej_internal_links_by_content(): For bulk mode (content-based with AI extraction)
	 * 8. Return JSON response with suggestions
	 *
	 * FILTERING:
	 * - Category filter: Only suggest posts from selected categories
	 * - Post type filter: Only suggest posts of selected types
	 * - Publish year filter: Only suggest posts from selected years (freshness)
	 * - Negative filter: Excludes current post from suggestions (prevents self-linking)
	 *
	 * RESPONSE FORMAT:
	 * - Success: JSON array of suggestion objects
	 * - Each suggestion contains: metadata (title, URL, category, date), similarity score
	 * - JavaScript displays these in editor-specific UI
	 *
	 * SECURITY:
	 * - check_ajax_referer(): Verifies nonce (CSRF protection)
	 * - current_user_can('edit_posts'): Ensures user can edit posts
	 * - wp_kses_post(): Sanitizes content input
	 *
	 * @return void Sends JSON response (success with data or error message)
	 */
	public function ajax_get_suggestions() {
		$GLOBALS['ilsej_perf_data'] = [
			'extraction_time'     => 0,
			// Set by ilsej_extract_entities(); stays '' in anchor mode, where no extraction runs.
			'extraction_provider' => '',
			'vertex_time'         => 0,
			'vertex_method'       => '',
			'pinecone_time'       => 0,
			'pinecone_calls'      => 0,
		];

		check_ajax_referer( 'ilsej_suggest', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permission denied', 'webgeeksmedia-ai-semantic-internal-links' ) );
		}

		$content = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';
		$mode    = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'bulk';
		if ( empty( $content ) ) {
			wp_send_json_error( __( 'No content provided', 'webgeeksmedia-ai-semantic-internal-links' ) );
		}
		$content = do_blocks( $content );
		require_once ILSEJ_DIR . 'includes/functions-internal-links.php';
		$settings = ILSEJ_Core::get_settings();

		// Sanitize filters array from POST data.
		$filters = [];
		if ( ! empty( $_POST['filters'] ) && is_array( $_POST['filters'] ) ) {
			$raw_filters = map_deep( wp_unslash( $_POST['filters'] ), 'sanitize_text_field' );
			foreach ( $raw_filters as $key => $value ) {
				$sanitized_key = sanitize_key( $key );
				if ( is_array( $value ) ) {
					$filters[ $sanitized_key ] = array_map( 'sanitize_text_field', $value );
				} else {
					$filters[ $sanitized_key ] = sanitize_text_field( $value );
				}
			}
		}

		// Get current post ID to exclude from suggestions.
		$current_post_id = 0;
		if ( isset( $_POST['post_id'] ) ) {
			$current_post_id = absint( wp_unslash( $_POST['post_id'] ) );
		} elseif ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current_post_id = absint( wp_unslash( $_GET['post'] ) );
		}

		// Set up negative filters to exclude current post.
		$negative_filters = [];
		if ( $current_post_id > 0 ) {
			$negative_filters['ID'] = [ (string) $current_post_id ];
		}

		if ( 'anchor' === $mode ) {
			$suggestions = ilsej_internal_link_by_kw( $content, 5, $filters, $negative_filters );
		} else {
			$suggestions = ilsej_internal_links_by_content( $content, $filters, $negative_filters );
		}

		// Filter out suggestions whose target URL is already linked in the post content.
		if ( ! empty( $suggestions ) ) {
			preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $content, $link_matches );
			if ( ! empty( $link_matches[1] ) ) {
				$existing_urls = array_map( 'trailingslashit', array_map( 'strtolower', $link_matches[1] ) );
				$suggestions   = array_values(
					array_filter(
						$suggestions,
						function ( $item ) use ( $existing_urls ) {
							$url = strtolower( trailingslashit( $item['api']['metadata']['url'] ?? '' ) );
							return ! in_array( $url, $existing_urls, true );
						}
					)
				);
			}
		}

		wp_send_json_success(
			[
				'suggestions' => $suggestions,
				'perf'        => $GLOBALS['ilsej_perf_data'],
			]
		);
	}
}
