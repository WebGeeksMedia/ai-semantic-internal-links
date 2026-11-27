<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * INDEXER CLASS - VECTOR EMBEDDING AND PINECONE OPERATIONS
 *
 * This is the core class responsible for converting WordPress posts into vector
 * embeddings and storing them in Pinecone. It handles the entire indexing pipeline:
 *
 * INDEXING PIPELINE:
 * 1. Content Extraction: Extracts important content from posts (title, meta, H2s, etc.)
 * 2. Vector Generation: Converts text to 768-dimensional vectors using Google Vertex AI
 * 3. Metadata Preparation: Gathers post metadata (URL, category, publish date, etc.)
 * 4. Pinecone Upsert: Stores vector + metadata in Pinecone vector database
 *
 * AUTOMATIC INDEXING:
 * - Hooks into WordPress post lifecycle events (publish, update, delete)
 * - Uses WordPress Cron for asynchronous processing (prevents blocking)
 * - Automatically updates vectors when posts change
 *
 * BATCH INDEXING:
 * - Processes multiple posts in small batches (prevents timeouts)
 * - Tracks progress via WordPress transients
 * - Supports pause/resume functionality
 *
 * VECTOR DIMENSIONS:
 * - Uses 768-dimensional vectors (Google Vertex AI text-embedding-005 model)
 * - Each dimension represents a semantic feature of the content
 * - Similar content produces similar vectors (cosine similarity)
 *
 * @package AISemanticInternalLinks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ILSEJ_DIR . 'includes/google-vertex-ai.php';
require_once ILSEJ_DIR . 'includes/functions-internal-links.php';

/**
 * Class ILSEJ_Indexer
 *
 * Handles vector embedding generation and Pinecone indexing operations
 * for WordPress posts, including batch processing and cron-based async indexing.
 */
class ILSEJ_Indexer {

	/** Default number of posts to process per batch (prevents PHP timeout). */
	const DEFAULT_BATCH_SIZE = 50;

	/**
	 * Retry handler instance for API calls.
	 *
	 * @var ILSEJ_Retry_Handler|null
	 */
	private static $retry_handler = null;

	/**
	 * Rate limiter instance for API call throttling.
	 *
	 * @var ILSEJ_Rate_Limiter|null
	 */
	private static $rate_limiter = null;



	/** WordPress Cron hook name for async indexing. */
	const CRON_INDEX_HOOK = 'ilsej_async_index_event';

	/** WordPress Cron hook name for async deletion. */
	const CRON_DELETE_HOOK = 'ilsej_async_delete_event';

	/**
	 * REGISTER WordPress HOOKS FOR AUTOMATIC INDEXING
	 *
	 * This method registers all WordPress action hooks needed for automatic
	 * indexing when posts are published, updated, or deleted.
	 *
	 * HOOKS REGISTERED:
	 * 1. transition_post_status: Fires when post status changes (draft → publish, etc.)
	 * 2. save_post: Backup hook for when post is updated but status doesn't change
	 * 3. Cron hooks: Handle asynchronous indexing/deletion to prevent blocking
	 *
	 * WHY ASYNCHRONOUS (CRON):
	 * - Embedding generation can take 1-3 seconds per post
	 * - Blocking WordPress would cause slow page loads
	 * - Cron allows processing in background without user waiting
	 *
	 * WHY TWO HOOKS (transition_post_status + save_post):
	 * - transition_post_status: Catches status changes (new publish, unpublish)
	 * - save_post: Catches updates to already-published posts (backup)
	 * - Some WordPress setups don't fire transition_post_status on updates
	 *
	 * CALLED FROM: Main plugin file (webgeeksmedia-ai-semantic-internal-links.php) on 'plugins_loaded' hook
	 *
	 * @return void
	 */
	public static function register_hooks() {
		// Handle post status transitions (draft → publish, publish → trash, etc.).
		add_action( 'transition_post_status', [ __CLASS__, 'handle_post_transition' ], 10, 3 );

		// Backup hook for post updates (when status doesn't change).
		// Some WordPress configurations don't fire transition_post_status on updates.
		add_action( 'save_post', [ __CLASS__, 'handle_save_post' ], 10, 3 );

		// Detect post_type changes away from indexed types — transition_post_status
		// does not flag this case (status stays 'publish'), so without this hook the
		// vector is never removed and becomes a permanent orphan in Pinecone.
		add_action( 'pre_post_update', [ __CLASS__, 'handle_pre_post_update' ], 10, 2 );

		// Register WordPress Cron handlers for asynchronous processing.
		add_action( self::CRON_INDEX_HOOK, [ __CLASS__, 'cron_handle_index' ] );
		add_action( self::CRON_DELETE_HOOK, [ __CLASS__, 'cron_handle_delete' ] );
	}

	/**
	 * Initialize retry handler and rate limiter.
	 *
	 * Called before batch operations and cron jobs to ensure
	 * the reliability and rate limiting layers are available.
	 *
	 * @return void
	 */
	public static function init() {
		if ( null === self::$retry_handler ) {
			require_once ILSEJ_DIR . 'includes/ilsej-retry-handler.php';
			self::$retry_handler = new ILSEJ_Retry_Handler();
		}
		if ( null === self::$rate_limiter ) {
			require_once ILSEJ_DIR . 'includes/ilsej-rate-limiter.php';
			self::$rate_limiter = new ILSEJ_Rate_Limiter();
		}
	}

	/**
	 * Get the configured batch size from settings.
	 *
	 * @return int Batch size clamped between 10 and 100.
	 */
	private static function get_batch_size(): int {
		$settings   = ILSEJ_Core::get_settings();
		$batch_size = isset( $settings['batch_size'] ) ? (int) $settings['batch_size'] : self::DEFAULT_BATCH_SIZE;
		return max( 10, min( 100, $batch_size ) );
	}

	/**
	 * Handle save_post hook as backup for when transition_post_status doesn't fire.
	 * This catches updates to published posts where status doesn't change.
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post    The post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 * @return void
	 */
	public static function handle_save_post( $post_id, $post, $update ) {
		// Only process on updates (not new posts - those are handled by transition_post_status).
		if ( ! $update ) {
			return;
		}

		// Skip autosaves and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only process published posts.
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		// Allowed post types, or an operator-listed extra page.
		if ( ! self::is_indexable( (int) $post_id, $post->post_type ) ) {
			return;
		}

		// Check for essential settings before scheduling.
		$settings = ILSEJ_Core::get_settings();
		if ( empty( $settings['pinecone_api_key'] ) || empty( $settings['pinecone_host'] ) || empty( $settings['gcp_json_key'] ) ) {
			return;
		}

		// Unschedule any existing indexing event for this post.
		$timestamp = wp_next_scheduled( self::CRON_INDEX_HOOK, [ $post_id ] );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_INDEX_HOOK, [ $post_id ] );
		}

		// Schedule Indexing (Update) in 10 seconds.
		$scheduled = wp_schedule_single_event( time() + 10, self::CRON_INDEX_HOOK, [ $post_id ] );
		if ( false === $scheduled ) {
			error_log( 'ILSEJ: Failed to schedule index event via save_post for post ID ' . $post_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		} else {
			error_log( 'ILSEJ: Scheduled index event via save_post for post ID ' . $post_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Clear stale related-posts transients so they rebuild with the new vector.
		self::clear_related_transients( $post_id );
	}

	/**
	 * Detect post_type changes that move a published post out of the indexed set.
	 *
	 * `transition_post_status` only fires the delete branch when post_status changes
	 * away from 'publish'. If only the post_type changes (e.g., 'post' → 'glossary_term')
	 * while status stays 'publish', no hook in the existing flow schedules a deletion,
	 * leaving the Pinecone vector as a permanent orphan. This handler closes that gap.
	 *
	 * @param int                  $post_id   The post ID being updated.
	 * @param array<string, mixed> $post_data Sanitized post data about to be written.
	 * @return void
	 */
	public static function handle_pre_post_update( $post_id, $post_data ) {
		$old_post = get_post( $post_id );
		if ( ! $old_post instanceof WP_Post ) {
			return;
		}

		$new_type           = isset( $post_data['post_type'] ) ? (string) $post_data['post_type'] : $old_post->post_type;
		$was_indexable_type = self::is_indexable( (int) $old_post->ID, $old_post->post_type );
		$is_indexable_type  = self::is_indexable( (int) $old_post->ID, $new_type );
		$was_published      = 'publish' === $old_post->post_status;

		// Only act when the post is leaving the indexed-type set while published.
		if ( ! $was_indexable_type || $is_indexable_type || ! $was_published ) {
			return;
		}

		$settings = ILSEJ_Core::get_settings();
		if ( empty( $settings['pinecone_api_key'] ) || empty( $settings['pinecone_host'] ) ) {
			return;
		}

		// Drop any pending index job — re-indexing a now-non-indexable post would fail.
		$index_timestamp = wp_next_scheduled( self::CRON_INDEX_HOOK, [ $post_id ] );
		if ( false !== $index_timestamp ) {
			wp_unschedule_event( $index_timestamp, self::CRON_INDEX_HOOK, [ $post_id ] );
		}

		// Schedule deletion (idempotent — unschedule any prior delete event first).
		$delete_timestamp = wp_next_scheduled( self::CRON_DELETE_HOOK, [ $post_id ] );
		if ( false !== $delete_timestamp ) {
			wp_unschedule_event( $delete_timestamp, self::CRON_DELETE_HOOK, [ $post_id ] );
		}
		wp_schedule_single_event( time() + 10, self::CRON_DELETE_HOOK, [ $post_id ] );
	}

	/**
	 * Get allowed post types from settings.
	 *
	 * @return array<int, string> List of allowed post type slugs.
	 */
	private static function get_allowed_post_types(): array {
		$settings = ILSEJ_Core::get_settings();
		return isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? $settings['post_types'] : [ 'post', 'page' ];
	}

	/**
	 * Get extra page IDs the operator opted into the index.
	 *
	 * Selective by design: a few key pages belong in the semantic
	 * index, but enabling the whole `page` type would drag every policy,
	 * team, and author page into Pinecone.
	 *
	 * @return array<int, int> Published-or-not page IDs, as saved.
	 */
	private static function get_extra_indexed_ids(): array {
		$settings = ILSEJ_Core::get_settings();
		$ids      = isset( $settings['indexed_page_ids'] ) && is_array( $settings['indexed_page_ids'] )
			? $settings['indexed_page_ids']
			: [];
		return array_values( array_filter( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Whether a post belongs in the index: its type is allowed, or its ID
	 * is on the operator's extra-pages list.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	private static function is_indexable( int $post_id, string $post_type ): bool {
		if ( in_array( $post_type, self::get_allowed_post_types(), true ) ) {
			return true;
		}
		return in_array( $post_id, self::get_extra_indexed_ids(), true );
	}

	/**
	 * HANDLE POST STATUS TRANSITIONS
	 *
	 * This function is called automatically by WordPress when a post's status changes.
	 * It determines whether to index, update, or delete the post from Pinecone
	 * based on the status transition.
	 *
	 * IMPORTANT: This function bails early if required settings (Pinecone/GCP keys) are missing.
	 *
	 * STATUS TRANSITIONS HANDLED:
	 * 1. New Post Published (draft → publish): Schedule indexing
	 * 2. Post Unpublished (publish → draft/trash): Schedule deletion from Pinecone
	 * 3. Post Updated (publish → publish): Schedule re-indexing (update vector)
	 *
	 * WHY SCHEDULE VIA CRON (NOT IMMEDIATE):
	 * - Embedding generation takes 1-3 seconds (would slow down post save)
	 * - Prevents blocking WordPress admin interface
	 * - Allows processing in background without user waiting
	 * - WordPress Cron handles execution automatically
	 *
	 * DUPLICATE PREVENTION:
	 * - Checks for existing scheduled cron job before scheduling new one
	 * - Unschedules old job to prevent duplicate processing
	 * - Ensures only one indexing job per post at a time
	 *
	 * POST TYPE FILTERING:
	 * - Only processes post types selected in plugin settings
	 * - Skips posts that aren't configured for indexing
	 * - Respects user's post type selection
	 *
	 * @param string  $new_status New post status (e.g., 'publish', 'draft', 'trash').
	 * @param string  $old_status Previous post status (e.g., 'draft', 'publish').
	 * @param WP_Post $post       WordPress post object.
	 * @return void
	 */
	public static function handle_post_transition( $new_status, $old_status, $post ) {
		// Allowed post types, or an operator-listed extra page.
		if ( ! self::is_indexable( (int) $post->ID, $post->post_type ) ) {
			return;
		}

		// 1. & 3. New Post Published OR Existing Published Post Updated.
		// (publish -> publish means an update happened).
		if ( 'publish' === $new_status ) {
			// Check for essential settings before scheduling.
			$settings = ILSEJ_Core::get_settings();
			if ( empty( $settings['pinecone_api_key'] ) || empty( $settings['pinecone_host'] ) || empty( $settings['gcp_json_key'] ) ) {
				return;
			}

			// Unschedule any existing indexing event for this post to avoid duplicates.
			$timestamp = wp_next_scheduled( self::CRON_INDEX_HOOK, [ $post->ID ] );
			if ( false !== $timestamp ) {
				wp_unschedule_event( $timestamp, self::CRON_INDEX_HOOK, [ $post->ID ] );
			}

			// Schedule Indexing (Update/Add) in 10 seconds.
			$scheduled = wp_schedule_single_event( time() + 10, self::CRON_INDEX_HOOK, [ $post->ID ] );
			if ( false === $scheduled ) {
				error_log( 'ILSEJ: Failed to schedule index event for post ID ' . $post->ID ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				error_log( 'ILSEJ: Scheduled index event for post ID ' . $post->ID . ' (status: ' . $old_status . ' -> ' . $new_status . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			// Clear stale related-posts transients so they rebuild with the new vector.
			self::clear_related_transients( $post->ID );
		} elseif ( 'publish' === $old_status && 'publish' !== $new_status ) {
			// 2. Article status changed from Publish to something else (Draft, Trash, Private).
			// Unschedule any existing indexing event.
			$timestamp = wp_next_scheduled( self::CRON_INDEX_HOOK, [ $post->ID ] );
			if ( false !== $timestamp ) {
				wp_unschedule_event( $timestamp, self::CRON_INDEX_HOOK, [ $post->ID ] );
			}

			// Schedule Deletion in 10 seconds.
			$delete_timestamp = wp_next_scheduled( self::CRON_DELETE_HOOK, [ $post->ID ] );
			if ( false !== $delete_timestamp ) {
				wp_unschedule_event( $delete_timestamp, self::CRON_DELETE_HOOK, [ $post->ID ] );
			}

			$scheduled = wp_schedule_single_event( time() + 10, self::CRON_DELETE_HOOK, [ $post->ID ] );
			if ( false === $scheduled ) {
				error_log( 'ILSEJ: Failed to schedule delete event for post ID ' . $post->ID ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/**
	 * WordPress CRON HANDLER: INDEX POST
	 *
	 * This function is called by WordPress Cron when a post needs to be indexed.
	 * It's scheduled asynchronously by handle_post_transition() or handle_save_post()
	 * to prevent blocking the WordPress admin interface.
	 *
	 * HOW WordPress CRON WORKS:
	 * - WordPress checks for scheduled cron jobs on every page load
	 * - If a job's scheduled time has passed, WordPress executes it
	 * - Jobs are executed in the background (doesn't block page rendering)
	 * - This is "pseudo-cron" (not true system cron, but works for most sites)
	 *
	 * WHY ASYNCHRONOUS:
	 * - Embedding generation via Google Vertex AI takes 1-3 seconds
	 * - Immediate processing would slow down post save operations
	 * - User doesn't need to wait for indexing to complete
	 *
	 * EXECUTION FLOW:
	 * 1. Retrieves plugin settings (API keys, database name)
	 * 2. Gets Google Cloud JSON key path (creates file if needed)
	 * 3. Calls index_post() to perform actual indexing
	 *
	 * @param int $post_id WordPress post ID to index.
	 * @return void
	 */
	public static function cron_handle_index( $post_id ) {
		$settings      = ILSEJ_Core::get_settings();
		$pinecone_key  = $settings['pinecone_api_key'] ?? '';
		$pinecone_host = $settings['pinecone_host'] ?? '';

		if ( empty( $pinecone_key ) || empty( $pinecone_host ) ) {
			error_log( 'ILSEJ Index Cron: Missing Pinecone API key or host URL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		// Handle Google JSON Key logic.
		$json_key = self::get_gcp_json_key_path( $settings );

		try {
			self::index_post( $post_id, $pinecone_key, $pinecone_host, $json_key );
		} catch ( Exception $e ) {
			error_log( 'ILSEJ Index Cron Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			set_transient( 'ilsej_last_error', $e->getMessage(), HOUR_IN_SECONDS );
		}
	}

	/**
	 * CRON HANDLER: Deletes the post from Pinecone.
	 *
	 * This function is called by WordPress Cron when a post is unpublished,
	 * trashed, or drafted. It removes the vector from Pinecone.
	 *
	 * @param int $post_id WordPress post ID to delete from Pinecone.
	 * @return void
	 */
	public static function cron_handle_delete( $post_id ) {
		$settings      = ILSEJ_Core::get_settings();
		$pinecone_key  = $settings['pinecone_api_key'] ?? '';
		$pinecone_host = $settings['pinecone_host'] ?? '';

		if ( empty( $pinecone_key ) || empty( $pinecone_host ) ) {
			error_log( 'ILSEJ Delete Cron: Missing Pinecone API key or host URL.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}

		try {
			self::delete_post_vector( (string) $post_id, $pinecone_host, $pinecone_key );
			error_log( 'ILSEJ: Successfully deleted vector for post ID ' . $post_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		} catch ( Exception $e ) {
			error_log( 'ILSEJ Delete Cron Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			set_transient( 'ilsej_last_error', $e->getMessage(), HOUR_IN_SECONDS );
		}
	}

	/**
	 * Helper to get GCP JSON Key content from settings.
	 *
	 * Returns the JSON key content directly from plugin settings,
	 * eliminating the need to create and manage a file on disk.
	 *
	 * @param array<string, mixed> $settings Plugin settings array.
	 * @return string The JSON key content, or empty string if not set.
	 */
	private static function get_gcp_json_key_path( $settings ) {
		// Return JSON content directly from settings (no file needed).
		return isset( $settings['gcp_json_key'] ) ? $settings['gcp_json_key'] : '';
	}

	/*
	 * ==========================================================
	 * EXISTING BATCH LOGIC
	 * ==========================================================
	 */

	/**
	 * INITIALIZE BATCH INDEXING QUEUE
	 *
	 * Called when user clicks "Start Indexing" in admin interface. This function
	 * builds a queue of all published posts that need to be indexed and stores
	 * it in WordPress transients for batch processing.
	 *
	 * WHAT IT DOES:
	 * 1. Queries WordPress for all published posts of selected post types
	 * 2. Stores post IDs in WordPress transient (temporary database storage)
	 * 3. Initializes progress counters (total posts, processed count)
	 *
	 * WHY TRANSIENTS:
	 * - Temporary storage that can be cleared if indexing stops
	 * - Survives page refreshes (unlike JavaScript variables)
	 * - Can be accessed by multiple PHP requests (batch processing)
	 *
	 * POST TYPE FILTERING:
	 * - Only includes post types selected in plugin settings
	 * - Respects user's configuration
	 *
	 * PROGRESS TRACKING:
	 * - Stores total count for progress calculation
	 * - Initializes processed count to 0
	 * - JavaScript reads these values to show progress bar
	 *
	 * CALLED FROM: ajax_start_indexing() in ILSEJ_Admin class
	 *
	 * @return void
	 */
	public static function init_queue() {
		$allowed_types = self::get_allowed_post_types();
		// Fetch all IDs at once. 'fields' => 'ids' keeps memory low (int array only).
		$post_ids = get_posts(
			[
				'post_type'   => $allowed_types,
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		);
		// Operator-listed extra pages join the queue.
		foreach ( self::get_extra_indexed_ids() as $extra_id ) {
			if ( 'publish' === get_post_status( $extra_id ) && ! in_array( $extra_id, $post_ids, true ) ) {
				$post_ids[] = $extra_id;
			}
		}
		// Use longer expiration (24 hours) to prevent transients expiring during long indexing processes.
		set_transient( 'ilsej_index_queue', $post_ids, DAY_IN_SECONDS );
		set_transient( 'ilsej_index_total', count( $post_ids ), DAY_IN_SECONDS );
		set_transient( 'ilsej_index_processed', 0, DAY_IN_SECONDS );
	}

	/**
	 * PROCESS NEXT BATCH OF POSTS FOR INDEXING
	 *
	 * This function processes a small batch of posts from the indexing queue.
	 * It's called repeatedly by JavaScript (via AJAX) until all posts are processed.
	 *
	 * BATCH PROCESSING BENEFITS:
	 * - Prevents PHP execution time limits (30-60 seconds default)
	 * - Allows progress tracking and user feedback
	 * - Enables pause/resume functionality
	 * - Prevents server overload
	 *
	 * PROCESSING FLOW:
	 * 1. Check if indexing is paused (returns current progress if paused)
	 * 2. Retrieve queue from WordPress transient
	 * 3. Extract next batch of post IDs (BATCH_SIZE posts)
	 * 4. Process each post: extract content → generate embedding → upsert to Pinecone
	 * 5. Update progress counters
	 * 6. Return progress data to JavaScript
	 *
	 * RESPONSE DATA:
	 * - finished: Boolean indicating if queue is empty
	 * - paused: Boolean indicating if indexing is paused
	 * - progress: Percentage complete (0-100)
	 * - processed: Number of posts processed so far
	 * - total: Total number of posts in queue
	 *
	 * ERROR HANDLING:
	 * - Individual post failures don't stop batch processing
	 * - Errors are logged to WordPress error log
	 * - Processing continues with next post
	 *
	 * CALLED FROM: ajax_process_batch() in ILSEJ_Admin class
	 *
	 * @return array<string, mixed> Progress data for JavaScript frontend.
	 * @throws Exception If an API call fails during indexing.
	 */
	public static function process_next_batch(): array {
		$queue = get_transient( 'ilsej_index_queue' );
		if ( empty( $queue ) || ! is_array( $queue ) ) {
			return [ 'finished' => true ];
		}

		$settings      = ILSEJ_Core::get_settings();
		$pinecone_key  = $settings['pinecone_api_key'];
		$pinecone_host = $settings['pinecone_host'];
		$json_key      = self::get_gcp_json_key_path( $settings );

		// Extract project ID from JSON key.
		$project_id = 'project';
		if ( ! empty( $json_key ) ) {
			$json_arr = json_decode( $json_key, true );
			if ( null !== $json_arr && isset( $json_arr['project_id'] ) ) {
				$project_id = $json_arr['project_id'];
			}
		}

		// Get the next batch from the queue.
		$batch_size     = self::get_batch_size();
		$batch_post_ids = array_splice( $queue, 0, $batch_size );

		// Update the queue in transients.
		set_transient( 'ilsej_index_queue', $queue, DAY_IN_SECONDS );

		$batch_docs      = [];
		$batch_metadatas = [];
		$valid_post_ids  = [];

		// Step 1: Prepare content and metadata for all posts in the batch.
		foreach ( $batch_post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			$title   = get_the_title( $post );
			$content = self::extract_important_copy( $post->post_content, $post_id );

			if ( empty( $content ) ) {
				// Should at least have a title, but skip if somehow completely empty.
				continue;
			}

			$batch_docs[] = [
				'content' => $content,
				'title'   => $title,
			];

			$publish_date      = (string) get_post_time( 'Y-m-d', true, $post_id );
			$batch_metadatas[] = [
				'title'        => $title,
				'url'          => get_permalink( $post_id ),
				'publish_date' => $publish_date,
				'category'     => self::get_primary_cat( $post_id ),
				'tags'         => self::get_post_tags( $post_id ),
				'publish_year' => substr( $publish_date, 0, 4 ),
				'post_type'    => $post->post_type,
			];

			$valid_post_ids[] = $post_id;
		}

		if ( ! empty( $batch_docs ) ) {
			// Initialize retry and rate limiting handlers.
			self::init();
			$settings = ILSEJ_Core::get_settings();

			$start_time = microtime( true );

			try {
				// Rate limit the embedding generation call (non-blocking for AJAX).
				if ( ! empty( $settings['enable_rate_limiting'] ) && self::$rate_limiter ) {
					$wait = self::$rate_limiter->check_rate_limit( 'embedding_generation' );
					if ( $wait > 0 ) {
						throw new Exception( 'rate_limited:' . $wait );
					}
				}

				// Step 2: Batch generate embeddings from Vertex AI (with retry).
				if ( ! empty( $settings['enable_retry'] ) && self::$retry_handler ) {
					$embeddings = self::$retry_handler->execute_with_retry(
						function () use ( $batch_docs, $project_id, $json_key ) {
							return ilsej_vertex_embed_documents_batch( $batch_docs, $project_id, $json_key );
						},
						[ 'timeout', 'network', 'rate limit', 'connection' ]
					);
				} else {
					$embeddings = ilsej_vertex_embed_documents_batch( $batch_docs, $project_id, $json_key );
				}

				if ( count( $embeddings ) !== count( $batch_docs ) ) {
					throw new Exception( 'Batch processing mismatch: expected ' . count( $batch_docs ) . ' embeddings, but received ' . count( $embeddings ) . '.' );
				}

				// Step 3: Prepare vectors for Pinecone batch upsert.
				$vectors_to_upsert = [];
				foreach ( $embeddings as $index => $vector ) {
					if ( null === $vector ) {
						error_log( "ILSEJ: Embedding failed for post ID {$valid_post_ids[$index]}." ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						continue;
					}

					$vectors_to_upsert[] = [
						'id'       => (string) $valid_post_ids[ $index ],
						'values'   => $vector,
						'metadata' => $batch_metadatas[ $index ],
					];
				}

				// Rate limit the Pinecone upsert call (non-blocking for AJAX).
				if ( ! empty( $settings['enable_rate_limiting'] ) && self::$rate_limiter ) {
					$wait = self::$rate_limiter->check_rate_limit( 'pinecone_upsert' );
					if ( $wait > 0 ) {
						throw new Exception( 'rate_limited:' . $wait );
					}
				}

				// Step 4: Atomic Batch Upsert to Pinecone (with retry).
				if ( ! empty( $vectors_to_upsert ) ) {
					if ( ! empty( $settings['enable_retry'] ) && self::$retry_handler ) {
						self::$retry_handler->execute_with_retry(
							function () use ( $vectors_to_upsert, $pinecone_key, $pinecone_host ) {
								return ilsej_pinecone_upsert_batch( $vectors_to_upsert, $pinecone_key, $pinecone_host );
							},
							[ 'timeout', 'network', 'connection' ]
						);
					} else {
						ilsej_pinecone_upsert_batch( $vectors_to_upsert, $pinecone_key, $pinecone_host );
					}
				}

				// Log performance metrics.
				$elapsed = microtime( true ) - $start_time;
				$count   = count( $batch_post_ids );
				$speed   = $elapsed > 0 ? round( $count / $elapsed, 2 ) : 0;
				error_log( sprintf( 'ILSEJ: Batch complete - %d posts in %.2fs (%.2f posts/sec).', $count, $elapsed, $speed ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			} catch ( Exception $e ) {
				// Rate limited: put posts back in queue and tell JS to wait.
				if ( 0 === strpos( $e->getMessage(), 'rate_limited:' ) ) {
					$wait_seconds = (int) str_replace( 'rate_limited:', '', $e->getMessage() );
					// Re-add unprocessed posts to front of queue.
					$queue = array_merge( $batch_post_ids, $queue );
					set_transient( 'ilsej_index_queue', $queue, DAY_IN_SECONDS );

					$total     = (int) get_transient( 'ilsej_index_total' );
					$processed = (int) get_transient( 'ilsej_index_processed' );
					$progress  = $total > 0 ? round( ( $processed / $total ) * 100 ) : 0;

					return [
						'finished'    => false,
						'paused'      => false,
						'progress'    => $progress,
						'processed'   => $processed,
						'total'       => $total,
						'retry_after' => min( $wait_seconds, 30 ),
					];
				}

				error_log( 'ILSEJ Batch Indexing Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.Security.EscapeOutput.ExceptionNotEscaped

				// Track consecutive failures to prevent infinite retry loops.
				$fail_count = (int) get_transient( 'ilsej_batch_fail_count' );
				++$fail_count;
				set_transient( 'ilsej_batch_fail_count', $fail_count, HOUR_IN_SECONDS );

				if ( $fail_count >= 3 ) {
					// Too many consecutive failures — skip this batch to avoid infinite loop.
					error_log( 'ILSEJ: Skipping batch after ' . $fail_count . ' consecutive failures. Post IDs: ' . implode( ', ', $batch_post_ids ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					delete_transient( 'ilsej_batch_fail_count' );

					// Count skipped posts as processed so progress moves forward.
					$processed  = (int) get_transient( 'ilsej_index_processed' );
					$processed += count( $batch_post_ids );
					set_transient( 'ilsej_index_processed', $processed, DAY_IN_SECONDS );
					set_transient( 'ilsej_last_error', $e->getMessage() . ' (batch skipped after ' . $fail_count . ' failures)', HOUR_IN_SECONDS );
				} else {
					// Re-queue failed posts for retry on next batch call.
					$queue = array_merge( $batch_post_ids, $queue );
					set_transient( 'ilsej_index_queue', $queue, DAY_IN_SECONDS );
					set_transient( 'ilsej_last_error', $e->getMessage(), HOUR_IN_SECONDS );
				}

				$total     = (int) get_transient( 'ilsej_index_total' );
				$processed = (int) get_transient( 'ilsej_index_processed' );
				$progress  = $total > 0 ? round( ( $processed / $total ) * 100 ) : 0;

				return [
					'finished'  => false,
					'paused'    => false,
					'progress'  => $progress,
					'processed' => $processed,
					'total'     => $total,
				];
			}
		}

		// Update processed count (only reached on success).
		$processed  = get_transient( 'ilsej_index_processed' );
		$processed += count( $batch_post_ids );
		set_transient( 'ilsej_index_processed', $processed, DAY_IN_SECONDS );

		// Reset consecutive failure counter on success.
		delete_transient( 'ilsej_batch_fail_count' );

		// Final progress calculation.
		$total = (int) get_transient( 'ilsej_index_total' );
		if ( 0 === $total ) {
			$total = count( $queue ) + $processed;
			set_transient( 'ilsej_index_total', $total, DAY_IN_SECONDS );
		}

		$progress = $total > 0 ? round( ( $processed / $total ) * 100 ) : 100;

		return [
			'finished'  => empty( $queue ),
			'paused'    => false,
			'progress'  => $progress,
			'processed' => $processed,
			'total'     => $total,
		];
	}


	/**
	 * EXTRACT IMPORTANT CONTENT FOR VECTOR EMBEDDING
	 *
	 * This function intelligently extracts the most semantically important parts
	 * of a WordPress post to create a focused vector representation. Instead of
	 * embedding the entire post content (which can be diluted with boilerplate),
	 * it extracts key sections that best represent the article's topic.
	 *
	 * WHY EXTRACT INSTEAD OF FULL CONTENT:
	 * - Full content includes navigation, ads, footer text (noise)
	 * - Focused content produces more accurate semantic vectors
	 * - Reduces embedding costs (shorter text = cheaper API calls)
	 * - Improves search relevance (vectors represent core topics, not fluff)
	 *
	 * CONTENT EXTRACTED (IN ORDER):
	 * 1. Title: The main topic of the article
	 * 2. Yoast Meta Description: SEO summary (if available)
	 * 3. Excerpt: WordPress post excerpt
	 * 4. First Paragraph: Opening context (only if no H2 precedes it)
	 * 5. H2 Headings + First Paragraph After Each: Main topic sections
	 * 6. Last Sentence: Conclusion or key takeaway
	 *
	 * DOM PARSING:
	 * - Uses PHP's DOMDocument to parse HTML structure
	 * - XPath queries to find specific elements (H2s, paragraphs)
	 * - Handles UTF-8 encoding properly for international content
	 * - Recursive traversal to determine document order
	 *
	 * DUPLICATE PREVENTION:
	 * - Checks if H2 comes before first paragraph (avoids duplicate first para)
	 * - Each section added only once
	 *
	 * PUNCTUATION HANDLING:
	 * - Ensures each extracted part ends with punctuation (. ! ? : ;)
	 * - Prevents awkward sentence breaks in concatenated text
	 *
	 * @param string $post_content Raw WordPress post content (HTML).
	 * @param int    $post_id WordPress post ID (for retrieving title, meta, excerpt).
	 * @return string Concatenated important content, ready for embedding.
	 */
	private static function extract_important_copy( $post_content, $post_id ): string {
		$parts = [];

		// 1. Title.
		$title = get_the_title( $post_id );
		if ( ! empty( $title ) ) {
			$parts[] = trim( $title );
		}

		// 2. Yoast meta description if exists.
		$yoast_meta = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		if ( ! empty( $yoast_meta ) ) {
			$parts[] = trim( $yoast_meta );
		}

		// 3. Excerpt.
		$excerpt = get_the_excerpt( $post_id );
		if ( ! empty( $excerpt ) ) {
			$parts[] = trim( $excerpt );
		}

		// Process content with DOM.
		if ( ! empty( $post_content ) ) {
			// Render blocks and shortcodes for text extraction.
			// Using do_blocks + do_shortcode instead of the full 'the_content' filter
			// chain avoids expensive image/embed/lazy-load processing that gets
			// stripped by wp_strip_all_tags() anyway.
			$processed_content = do_blocks( $post_content );
			$processed_content = do_shortcode( $processed_content );
			$processed_content = wpautop( $processed_content );

			// Strip all text once for later use (more efficient).
			$all_text = trim( wp_strip_all_tags( $processed_content ) );

			// Load HTML into DOMDocument with proper UTF-8 encoding.
			$previous_libxml_state = libxml_use_internal_errors( true );
			$dom                   = new DOMDocument( '1.0', 'UTF-8' );

			// Proper UTF-8 handling with HTML5 DOCTYPE.
			$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'
					. $processed_content . '</body></html>';

			// Load with proper encoding flags.
			@$dom->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DOMDocument emits warnings for valid HTML5 tags.
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_libxml_state );

			$xpath = new DOMXPath( $dom );

			// 4. First paragraph (only if no H2 precedes it).
			// Helper function to recursively find first occurrence of node in document order.
			$find_first_node = function ( $target_nodes, $root ) use ( &$find_first_node ) {
				if ( ! $root || empty( $target_nodes ) ) {
					return null;
				}

				// Check direct children first.
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOMDocument API property.
				foreach ( $root->childNodes as $child ) {
					if ( XML_ELEMENT_NODE === $child->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						// Check if this is one of our target nodes.
						foreach ( $target_nodes as $target ) {
							if ( $child === $target ) {
								return $child;
							}
						}

						// Recursively check children.
						$found = $find_first_node( $target_nodes, $child );
						if ( $found ) {
							return $found;
						}
					}
				}
				return null;
			};

			$first_para = $xpath->query( '//body/p[1]' );
			$first_h2   = $xpath->query( '//body//h2[1]' );

			if ( false !== $first_para && $first_para->length > 0 ) {
				$first_para_text = trim( self::get_node_text( $first_para->item( 0 ) ) );
				if ( ! empty( $first_para_text ) ) {
					$add_first_para = true;

					// If there's an H2, check if it comes before the first paragraph.
					if ( false !== $first_h2 && $first_h2->length > 0 ) {
						$body_list = $xpath->query( '//body' );
						$body      = ( false !== $body_list ) ? $body_list->item( 0 ) : null;
						if ( $body ) {
							$first_para_node = $first_para->item( 0 );
							$first_h2_node   = $first_h2->item( 0 );

							// Find which comes first in document order.
							$first_found = $find_first_node( [ $first_h2_node, $first_para_node ], $body );

							// If H2 comes first, skip the first paragraph.
							if ( $first_found === $first_h2_node ) {
								$add_first_para = false;
							}
						}
					}

					if ( $add_first_para ) {
						$parts[] = $first_para_text;
					}
				}
			}

			// 5. H2 tags and first paragraph after each H2.
			$h2_elements = $xpath->query( '//body//h2' );

			if ( false !== $h2_elements ) {
				foreach ( $h2_elements as $h2 ) {
					if ( ! $h2 instanceof DOMElement ) {
						continue;
					}

					// Add H2 text.
					$h2_text = trim( self::get_node_text( $h2 ) );
					if ( ! empty( $h2_text ) ) {
						$parts[] = $h2_text;
					}

					// Find the next paragraph sibling.
					$next_sibling = $h2->nextSibling; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOMDocument API.
					while ( null !== $next_sibling ) {
						if ( XML_ELEMENT_NODE === $next_sibling->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							if ( 'p' === strtolower( $next_sibling->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
								$para_text = trim( self::get_node_text( $next_sibling ) );
								if ( ! empty( $para_text ) ) {
									$parts[] = $para_text;
								}
								break; // Only first paragraph after H2.
							} elseif ( 'h2' === strtolower( $next_sibling->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
								// Another H2 found, stop looking.
								break;
							}
						}
						$next_sibling = $next_sibling->nextSibling; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					}
				}
			}

			// 6. Last sentence of content.
			if ( ! empty( $all_text ) ) {
				// Split by sentence endings with UTF-8 flag.
				$sentences = preg_split( '/(?<=[.!?])\s+/u', $all_text, -1, PREG_SPLIT_NO_EMPTY );
				if ( is_array( $sentences ) ) {
					$last_sentence = trim( end( $sentences ) );
					if ( ! empty( $last_sentence ) ) {
						$parts[] = $last_sentence;
					}
				} else {
					// Fallback: get last 150 characters using mb_substr for multibyte safety.
					$parts[] = trim( mb_substr( $all_text, -150 ) );
				}
			}
		}

		// Concatenate all parts with proper periods.
		return self::format_parts_with_periods( $parts );
	}

	/**
	 * Helper to safely extract text from DOMNode.
	 *
	 * @param DOMNode|DOMNameSpaceNode|null $node The DOM node to extract text from.
	 * @return string The stripped text content of the node.
	 */
	private static function get_node_text( $node ): string {
		if ( ! $node instanceof DOMNode || null === $node->ownerDocument ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return '';
		}
		$html = $node->ownerDocument->saveHTML( $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP DOMDocument API.
		return wp_strip_all_tags( false !== $html ? $html : '' );
	}

	/**
	 * Format parts ensuring proper punctuation.
	 *
	 * @param array<int, string> $parts Array of text parts to format.
	 * @return string Formatted string with proper punctuation.
	 */
	private static function format_parts_with_periods( array $parts ): string {
		$result = [];

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( empty( $part ) ) {
				continue;
			}

			// Ensure period at end if not present (using mb_substr for multibyte safety).
			$last_char = mb_substr( $part, -1 );
			if ( ! in_array( $last_char, [ '.', '!', '?', ':', ';' ], true ) ) {
				$part .= '.';
			}

			$result[] = $part;
		}

		return implode( ' ', $result );
	}

	/**
	 * INDEX A SINGLE POST INTO PINECONE
	 *
	 * This is the main indexing function that converts a WordPress post into
	 * a vector embedding and stores it in Pinecone. This function is called:
	 * - During batch indexing (process_next_batch)
	 * - When a post is published/updated (via WordPress Cron)
	 * - Manually via admin interface
	 *
	 * INDEXING PROCESS:
	 * 1. Validate post is published (skip drafts, private posts)
	 * 2. Extract important content (title, meta, H2s, etc.)
	 * 3. Generate 768-dimensional vector using Google Vertex AI
	 * 4. Prepare metadata (URL, category, publish date, post type)
	 * 5. Upsert to Pinecone (create or update existing vector)
	 *
	 * VECTOR ID:
	 * - Uses WordPress post ID as Pinecone vector ID
	 * - Enables easy lookup and updates (same ID = update, new ID = create)
	 * - Allows filtering out current post from suggestions
	 *
	 * METADATA STORED:
	 * - title: Post title (for display in suggestions)
	 * - url: Permalink (for creating links)
	 * - publish_date: Publication date (for freshness filtering)
	 * - category: Primary category slug (for category filtering)
	 * - publish_year: Year published (for age-based filtering)
	 * - post_type: WordPress post type (for post type filtering)
	 *
	 * ERROR HANDLING:
	 * - Silently logs errors to WordPress error log
	 * - Doesn't throw exceptions (prevents breaking WordPress)
	 * - Continues processing other posts if one fails
	 *
	 * @param int    $post_id      WordPress post ID to index.
	 * @param string $pinecone_key Pinecone API key.
	 * @param string $host         Pinecone host URL.
	 * @param string $json_key     Google Cloud JSON key content (from settings).
	 * @return void
	 *
	 * @throws Exception If vector embedding generation or Pinecone upsert fails.
	 */
	public static function index_post( int $post_id, string $pinecone_key, string $host, string $json_key ) {
		try {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				return;
			}
			$title = get_the_title( $post );

			// Extract important content instead of full content.
			$content = self::extract_important_copy( $post->post_content, $post_id );

			$project_id = 'project';
			// Extract project ID from JSON key if available.
			if ( ! empty( $json_key ) ) {
				$json_arr = json_decode( $json_key, true );
				if ( null !== $json_arr && isset( $json_arr['project_id'] ) ) {
					$project_id = $json_arr['project_id'];
				}
			}

			// Initialize retry and rate limiting handlers.
			self::init();
			$settings = ILSEJ_Core::get_settings();

			// Rate limit the embedding generation (cron context — safe to sleep briefly).
			if ( ! empty( $settings['enable_rate_limiting'] ) && self::$rate_limiter ) {
				$wait = self::$rate_limiter->check_rate_limit( 'embedding_generation' );
				if ( $wait > 0 ) {
					sleep( min( $wait, 10 ) );
				}
			}

			// Generate Embedding (with retry if enabled).
			if ( ! empty( $settings['enable_retry'] ) && self::$retry_handler ) {
				$vector = self::$retry_handler->execute_with_retry(
					function () use ( $content, $title, $project_id, $json_key ) {
						return ilsej_vertex_embed_article( $content, $title, $project_id, $json_key );
					},
					[ 'timeout', 'network', 'rate limit', 'connection' ]
				);
			} else {
				$vector = ilsej_vertex_embed_article( $content, $title, $project_id, $json_key );
			}

			if ( ! $vector ) {
				throw new Exception( 'Could not generate vector embedding for post ID ' . $post_id . '.' );
			}

			$metadata = [
				'title'        => $title,
				'url'          => get_permalink( $post_id ),
				'publish_date' => get_post_time( 'Y-m-d', true, $post_id ),
				'category'     => self::get_primary_cat( $post_id ),
				'tags'         => self::get_post_tags( $post_id ),
				'publish_year' => get_post_time( 'Y', true, $post_id ),
				'post_type'    => $post->post_type,
			];

			// Rate limit the Pinecone upsert (cron context — safe to sleep briefly).
			if ( ! empty( $settings['enable_rate_limiting'] ) && self::$rate_limiter ) {
				$wait = self::$rate_limiter->check_rate_limit( 'pinecone_upsert' );
				if ( $wait > 0 ) {
					sleep( min( $wait, 10 ) );
				}
			}

			// Upsert to Pinecone (with retry if enabled).
			if ( ! empty( $settings['enable_retry'] ) && self::$retry_handler ) {
				self::$retry_handler->execute_with_retry(
					function () use ( $post_id, $vector, $metadata, $pinecone_key, $host ) {
						return ilsej_pinecone_upsert( (string) $post_id, $vector, $metadata, $pinecone_key, $host );
					},
					[ 'timeout', 'network', 'connection' ]
				);
			} else {
				ilsej_pinecone_upsert( (string) $post_id, $vector, $metadata, $pinecone_key, $host );
			}
		} catch ( Exception $e ) {
			$error_msg = $e->getMessage();
			error_log( 'ILSEJ index error: ' . $error_msg ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.Security.EscapeOutput.ExceptionNotEscaped
			set_transient( 'ilsej_last_error', $error_msg, HOUR_IN_SECONDS );
		}
	}

	/**
	 * DELETE Vector from Pinecone.
	 *
	 * Uses WordPress HTTP API (wp_remote_post) for consistency with WordPress
	 * coding standards and better compatibility with various hosting environments.
	 *
	 * @param string $post_id The post ID to delete from Pinecone.
	 * @param string $host_url The Pinecone host URL.
	 * @param string $api_key  The Pinecone API key.
	 * @return void
	 *
	 * @throws Exception If connection error or deletion failure occurs.
	 */
	private static function delete_post_vector( string $post_id, string $host_url, string $api_key ) {
		$url = rtrim( $host_url, '/' ) . '/vectors/delete';

		$body = wp_json_encode( [ 'ids' => [ $post_id ] ] );
		if ( false === $body ) {
			throw new Exception( 'Failed to encode request body for Pinecone deletion.' );
		}

		$response = wp_remote_post(
			$url,
			[
				'headers' => [
					'Api-Key'      => $api_key,
					'Content-Type' => 'application/json',
				],
				'body'    => $body,
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( 'Connection error while deleting from Pinecone: ' . $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( $http_code >= 400 ) {
			throw new Exception( 'Pinecone deletion failed (Status ' . (int) $http_code . '): ' . wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Get the primary category slug for a post.
	 *
	 * @param int $post_id The WordPress post ID.
	 * @return string The primary category slug, or empty string if none found.
	 */
	private static function get_primary_cat( int $post_id ): string {
		$cats = get_the_category( $post_id );
		if ( ! empty( $cats ) ) {
			return $cats[0]->slug;
		}
		return '';
	}

	/**
	 * Get tag slugs for a post.
	 *
	 * @param int $post_id The WordPress post ID.
	 * @return list<string> Array of tag slugs, or empty array if none found.
	 */
	private static function get_post_tags( int $post_id ): array {
		$tags = get_the_tags( $post_id );
		if ( ! empty( $tags ) && is_array( $tags ) ) {
			return array_values(
				array_map(
					function ( $tag ) {
						return $tag->slug;
					},
					$tags
				)
			);
		}
		return [];
	}

	/**
	 * Clear related-posts transients for a post.
	 *
	 * Called when a post is scheduled for re-indexing so that stale
	 * semantic recommendations are rebuilt from the new vector.
	 *
	 * @param int $post_id The WordPress post ID.
	 * @return void
	 */
	private static function clear_related_transients( int $post_id ): void {
		$post_types = self::get_allowed_post_types();
		if ( self::get_extra_indexed_ids() && ! in_array( 'page', $post_types, true ) ) {
			$post_types[] = 'page';
		}
		foreach ( $post_types as $type ) {
			delete_transient( 'ilsej_related_' . $post_id . '_' . $type );
		}
	}

	/**
	 * Get the total number of vectors stored in the Pinecone index.
	 *
	 * Calls Pinecone's describe_index_stats endpoint and returns the
	 * total vector count. Result is cached for 5 minutes to avoid
	 * excessive API calls on page refreshes.
	 *
	 * @return int|false Total vector count, or false on failure.
	 */
	public static function get_index_stats() {
		$cached = get_transient( 'ilsej_index_vector_count' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$settings      = ILSEJ_Core::get_settings();
		$pinecone_key  = $settings['pinecone_api_key'] ?? '';
		$pinecone_host = $settings['pinecone_host'] ?? '';

		if ( empty( $pinecone_key ) || empty( $pinecone_host ) ) {
			return false;
		}

		$host = rtrim( $pinecone_host, '/' );
		if ( 0 !== strpos( $host, 'http' ) ) {
			$host = 'https://' . $host;
		}

		$response = wp_remote_post(
			$host . '/describe_index_stats',
			[
				'headers' => [
					'Api-Key' => $pinecone_key,
					'Accept'  => 'application/json',
				],
				'timeout' => 10,
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return false;
		}

		$total = isset( $body['totalVectorCount'] ) ? (int) $body['totalVectorCount'] : 0;

		set_transient( 'ilsej_index_vector_count', $total, 5 * MINUTE_IN_SECONDS );

		return $total;
	}

	/**
	 * Get the total number of published posts for allowed post types.
	 *
	 * @return int Total published post count.
	 */
	public static function get_total_post_count(): int {
		$allowed_types = self::get_allowed_post_types();
		$count         = 0;
		foreach ( $allowed_types as $type ) {
			$counts = wp_count_posts( $type );
			if ( isset( $counts->publish ) ) {
				$count += (int) $counts->publish;
			}
		}
		foreach ( self::get_extra_indexed_ids() as $extra_id ) {
			if ( 'publish' === get_post_status( $extra_id ) && ! in_array( get_post_type( $extra_id ), $allowed_types, true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Stop the indexing process and clean up transients.
	 *
	 * Deletes all indexing-related transients to reset the queue state.
	 *
	 * @return void
	 */
	public static function stop() {
		delete_transient( 'ilsej_index_queue' );
		delete_transient( 'ilsej_index_total' );
		delete_transient( 'ilsej_index_processed' );
		delete_transient( 'ilsej_batch_fail_count' );
	}
}
