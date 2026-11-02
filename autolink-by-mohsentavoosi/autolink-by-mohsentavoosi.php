<?php
/**
 * Plugin Name:       Autolink by MohsenTavoosi
 * Plugin URI:        https://mohsentavoosi.com/wordpress-auto-internal-links
 * Description:       Adds internal links to your keywords while the page is shown. Never changes your posts or the database content. Works with Elementor, the block editor, WooCommerce and term descriptions.
 * Version:           2.2.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Mohsen Tavoosi
 * Author URI:        https://mohsentavoosi.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       autolink-by-mohsentavoosi
 * Domain Path:       /languages
 *
 * @package Autolink_By_MohsenTavoosi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MTAL_VERSION', '2.2.1' );
define( 'MTAL_DOCS_GENERAL_URL', 'https://mohsentavoosi.com/automate-internal-links' ); // General rules of automatic internal linking (any platform).
define( 'MTAL_DOCS_WP_URL', 'https://mohsentavoosi.com/wordpress-auto-internal-links' ); // WordPress guide of this plugin.
define( 'MTAL_AUTHOR_URL', 'https://mohsentavoosi.com' );
define( 'MTAL_DIVERSITY_INFO_URL', MTAL_DOCS_GENERAL_URL ); // Tutorial page for smart link diversity.
define( 'MTAL_SCHEMA_VERSION', 3 );
define( 'MTAL_PLUGIN_FILE', __FILE__ );
define( 'MTAL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MTAL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once MTAL_PLUGIN_DIR . 'includes/class-mtal-processor.php';
require_once MTAL_PLUGIN_DIR . 'includes/class-mtal-settings.php';
require_once MTAL_PLUGIN_DIR . 'includes/class-mtal-elementor.php';
require_once MTAL_PLUGIN_DIR . 'includes/class-mtal-hooks.php';
if ( is_admin() ) {
	require_once MTAL_PLUGIN_DIR . 'includes/class-mtal-admin.php';
}

/**
 * Versions before 2.2.0 were published as "Auto Internal Links" with the prefix "ail_".
 * Move the old table and options to the new prefix once. Nothing is lost: the table is
 * renamed (not copied), and an old option is removed only after its value was copied.
 */
function mtal_migrate_legacy_prefix() {
	global $wpdb;
	$old_table = $wpdb->prefix . 'ail_keywords';
	$new_table = $wpdb->prefix . 'mtal_keywords';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
	$has_old = ( $old_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old_table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
	if ( $has_old ) {
		$has_new = ( $new_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $new_table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		if ( $has_new && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new_table}" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
			$wpdb->query( "DROP TABLE {$new_table}" ); // An empty table made by a fresh start. The old one has the data. // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
			$has_new = false;
		}
		if ( ! $has_new ) {
			$wpdb->query( "RENAME TABLE {$old_table} TO {$new_table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		}
	}
	// phpcs:enable
	$keys = array( 'enabled', 'zwnj_as_space', 'post_types', 'taxonomies', 'delete_on_uninstall', 'para_max', 'diversity', 'unlock_capped', 'short_inside', 'front_page', 'exclude_posts', 'exclude_terms' );
	$none = new stdClass();
	foreach ( $keys as $key ) {
		$old = get_option( 'ail_' . $key, $none );
		if ( $none === $old ) {
			continue;
		}
		$current = get_option( 'mtal_' . $key, $none );
		if ( $none === $current ) {
			update_option( 'mtal_' . $key, $old );
		}
		delete_option( 'ail_' . $key );
	}
	delete_option( 'ail_schema_version' );
}

/**
 * Create the table. Plain CREATE TABLE IF NOT EXISTS: it never touches an existing table or its rows.
 */
function mtal_create_table() {
	global $wpdb;
	$table   = $wpdb->prefix . 'mtal_keywords';
	$charset = $wpdb->get_charset_collate();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		"CREATE TABLE IF NOT EXISTS {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			keywords    TEXT            NOT NULL,
			target_url  VARCHAR(2083)   NOT NULL,
			max_links   INT             NOT NULL DEFAULT 1,
			para_max    INT             NOT NULL DEFAULT 0,
			para_mode   VARCHAR(20)     NOT NULL DEFAULT 'p_br',
			post_ids    VARCHAR(500)    NOT NULL DEFAULT '',
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY url_idx (target_url(191))
		) {$charset}"
	);
	// phpcs:enable
	return mtal_maybe_migrate( $table );
}

/**
 * R22: migrate the old single-keyword table to the multi-keyword (JSON) layout.
 * Every step checks first, so running it twice is harmless, and the old column is
 * dropped only after every row has been copied. Returns true when the table is ready.
 */
function mtal_maybe_migrate( $table ) {
	global $wpdb;

	$has = function ( $col ) use ( $wpdb, $table ) {
		return ! empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE '{$col}'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
	};
	if ( ! $has( 'keyword' ) ) {
		return $has( 'keywords' ); // Already the new layout.
	}
	// TEXT NULL works on every MySQL, MariaDB and SQLite version.
	$add = array(
		'keywords'  => "ALTER TABLE {$table} ADD COLUMN keywords TEXT NULL",
		'para_max'  => "ALTER TABLE {$table} ADD COLUMN para_max INT NOT NULL DEFAULT 0",
		'para_mode' => "ALTER TABLE {$table} ADD COLUMN para_mode VARCHAR(20) NOT NULL DEFAULT 'p_br'",
		'post_ids'  => "ALTER TABLE {$table} ADD COLUMN post_ids VARCHAR(500) NOT NULL DEFAULT ''",
	);
	foreach ( $add as $col => $sql ) {
		if ( ! $has( $col ) ) {
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		}
		if ( ! $has( $col ) ) {
			return false; // Stop here. Nothing has been dropped.
		}
	}
	if ( empty( $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'url_idx'" ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		$wpdb->query( "DELETE FROM {$table} WHERE id NOT IN (SELECT id FROM (SELECT MIN(id) AS id FROM {$table} GROUP BY target_url) AS keep_rows)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY url_idx (target_url(191))" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
	}
	$rows = $wpdb->get_results( "SELECT id, keyword FROM {$table} WHERE keywords IS NULL OR keywords = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
	foreach ( (array) $rows as $row ) {
		if ( '' !== (string) $row->keyword ) {
			$wpdb->update( $table, array( 'keywords' => wp_json_encode( array( $row->keyword ), JSON_UNESCAPED_UNICODE ) ), array( 'id' => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		}
	}
	$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE (keywords IS NULL OR keywords = '') AND keyword <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
	if ( $left > 0 ) {
		return false; // Some rows were not copied. Keep the old column.
	}
	$global_para = (int) get_option( 'mtal_para_max', 0 );
	if ( $global_para > 0 ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET para_max = %d WHERE para_max = 0", $global_para ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
	}
	if ( ! empty( $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'keyword_idx'" ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX keyword_idx" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
	}
	$wpdb->query( "ALTER TABLE {$table} DROP COLUMN keyword" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
	delete_option( 'mtal_para_max' );
	return true;
}

register_activation_hook( __FILE__, 'mtal_activate' );
function mtal_activate() {
	mtal_migrate_legacy_prefix();
	$ready = mtal_create_table();
	add_option( 'mtal_enabled', 1 );
	add_option( 'mtal_zwnj_as_space', 1 );
	add_option( 'mtal_diversity', 1 );
	add_option( 'mtal_front_page', 1 );
	if ( $ready ) {
		update_option( 'mtal_schema_version', MTAL_SCHEMA_VERSION );
	}
}

register_deactivation_hook( __FILE__, 'mtal_deactivate' );
function mtal_deactivate() {
	// Nothing to clean. Links exist only in the page output, so they disappear by themselves.
}

/**
 * A plugin updated by replacing its files does not run the activation hook,
 * so the table and defaults are checked once per schema version.
 */
add_action( 'plugins_loaded', 'mtal_boot' );
function mtal_boot() {
	if ( (int) get_option( 'mtal_schema_version', 0 ) < MTAL_SCHEMA_VERSION ) {
		mtal_migrate_legacy_prefix(); // Before the table is created, so the old rows move first.
		$ready = mtal_create_table();
		add_option( 'mtal_zwnj_as_space', 1 );
		if ( $ready ) {
			update_option( 'mtal_schema_version', MTAL_SCHEMA_VERSION ); // Not saved when the migration stopped, so it retries.
		}
	}
	if ( is_admin() ) {
		MTAL_Admin::init();
	}
}

// Hooks that change the output are registered after all post types and taxonomies exist.
add_action( 'init', array( 'MTAL_Hooks', 'init' ), 20 );
add_action( 'init', array( 'MTAL_Elementor', 'init' ), 20 );
