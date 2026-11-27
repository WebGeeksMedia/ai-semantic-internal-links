<?php
/**
 * Uninstall script for AI Semantic Internal Links plugin.
 *
 * Only removes plugin data when the user has explicitly opted in
 * via Internal Linking > Settings > "Delete Data on Uninstall".
 * The Pinecone vector index is never affected.
 *
 * @package AISemanticInternalLinks
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ilsej_settings = get_option( 'ilsej_settings', [] );

if ( empty( $ilsej_settings['delete_data_on_uninstall'] ) ) {
	return;
}

// Delete plugin options.
delete_option( 'ilsej_settings' );
delete_option( 'ilsej_old_slug_cleaned' );

// Delete all plugin transients.
delete_transient( 'ilsej_index_queue' );
delete_transient( 'ilsej_index_total' );
delete_transient( 'ilsej_index_processed' );

// V2 transients: rate limiting, error tracking, and token cache.
delete_transient( 'ilsej_rate_embedding_generation' );
delete_transient( 'ilsej_rate_pinecone_query' );
delete_transient( 'ilsej_rate_pinecone_upsert' );
delete_transient( 'ilsej_last_error' );
delete_transient( 'ilsej_settings_errors' );
delete_transient( 'ilsej_batch_fail_count' );

// Dynamic transients: GCP tokens and related-posts cache.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_ilsej_gcp_token_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ilsej_gcp_token_' ) . '%',
		$wpdb->esc_like( '_transient_ilsej_related_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ilsej_related_' ) . '%',
		$wpdb->esc_like( '_transient_ilsej_public_search_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_ilsej_public_search_' ) . '%'
	)
);

// Clear all scheduled WordPress cron jobs.
wp_clear_scheduled_hook( 'ilsej_async_index_event' );
wp_clear_scheduled_hook( 'ilsej_async_delete_event' );
