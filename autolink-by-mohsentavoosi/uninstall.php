<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Data is removed only if "Delete all plugin data when the plugin is deleted" was ticked.
 *
 * @package Auto_Internal_Links
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! get_option( 'ail_delete_on_uninstall', 0 ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ail_keywords" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( array( 'ail_enabled', 'ail_zwnj_as_space', 'ail_post_types', 'ail_taxonomies', 'ail_delete_on_uninstall', 'ail_schema_version', 'ail_para_max', 'ail_diversity', 'ail_unlock_capped', 'ail_short_inside', 'ail_front_page', 'ail_exclude_posts', 'ail_exclude_terms' ) as $option ) {
	delete_option( $option );
}
