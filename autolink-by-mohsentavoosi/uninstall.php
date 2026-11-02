<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Data is removed only if "Delete all plugin data when the plugin is deleted" was ticked.
 *
 * @package Autolink_By_MohsenTavoosi
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! get_option( 'mtal_delete_on_uninstall', 0 ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mtal_keywords" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( array( 'mtal_enabled', 'mtal_zwnj_as_space', 'mtal_post_types', 'mtal_taxonomies', 'mtal_delete_on_uninstall', 'mtal_schema_version', 'mtal_para_max', 'mtal_diversity', 'mtal_unlock_capped', 'mtal_short_inside', 'mtal_front_page', 'mtal_exclude_posts', 'mtal_exclude_terms' ) as $mtal_option ) {
	delete_option( $mtal_option );
}

// Leftovers of versions before 2.2.0 (prefix "ail_"), in case the migration never ran.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ail_keywords" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( array( 'enabled', 'zwnj_as_space', 'post_types', 'taxonomies', 'delete_on_uninstall', 'schema_version', 'para_max', 'diversity', 'unlock_capped', 'short_inside', 'front_page', 'exclude_posts', 'exclude_terms' ) as $mtal_legacy_key ) {
	delete_option( 'ail_' . $mtal_legacy_key );
}
