<?php
/**
 * Uninstall script for AT Protocol plugin.
 *
 * Removes all plugin data when the plugin is deleted.
 *
 * @package ATProto
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete options.
delete_option( 'atproto_private_key' );
delete_option( 'atproto_public_key' );
delete_option( 'atproto_enabled_post_types' );
delete_option( 'atproto_current_rev' );
delete_option( 'atproto_root_cid' );

// Delete repository state options.
delete_option( 'atproto_repo_state' );
delete_option( 'atproto_repo_commits' );
delete_option( 'atproto_records' );
delete_option( 'atproto_mst_nodes' );
delete_option( 'atproto_mst_entries' );
delete_option( 'atproto_followers' );
delete_option( 'atproto_follower_count' );

// Delete post meta.
global $wpdb;

$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_atproto_%'"
);

// Delete comment meta.
$wpdb->query(
	"DELETE FROM {$wpdb->commentmeta} WHERE meta_key LIKE '_atproto_%'"
);

// Clear any scheduled hooks.
wp_clear_scheduled_hook( 'atproto_relay_sync' );
wp_clear_scheduled_hook( 'atproto_cleanup' );

// Flush rewrite rules.
flush_rewrite_rules();
