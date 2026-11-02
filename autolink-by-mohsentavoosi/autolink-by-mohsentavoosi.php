<?php
/**
 * Plugin Name:       Autolink by MohsenTavoosi
 * Plugin URI:        https://mohsentavoosi.com/wordpress-auto-internal-links
 * Description:       Adds internal links to your keywords while the page is shown. Never changes your posts or the database content. Works with Elementor, the block editor, WooCommerce and term descriptions.
 * Version:           2.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Mohsen Tavoosi
 * Author URI:        https://mohsentavoosi.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       autolink-by-mohsentavoosi
 * Domain Path:       /languages
 *
 * @package Auto_Internal_Links
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AIL_VERSION', '2.2.0' );
define( 'AIL_DOCS_GENERAL_URL', 'https://mohsentavoosi.com/automate-internal-links' ); // General rules of automatic internal linking (any platform).
define( 'AIL_DOCS_WP_URL', 'https://mohsentavoosi.com/wordpress-auto-internal-links' ); // WordPress guide of this plugin.
define( 'AIL_AUTHOR_URL', 'https://mohsentavoosi.com' );
define( 'AIL_DIVERSITY_INFO_URL', AIL_DOCS_GENERAL_URL ); // Tutorial page for smart link diversity.
define( 'AIL_SCHEMA_VERSION', 2 );
define( 'AIL_PLUGIN_FILE', __FILE__ );
define( 'AIL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AIL_PLUGIN_DIR . 'includes/class-ail-processor.php';
require_once AIL_PLUGIN_DIR . 'includes/class-ail-settings.php';
require_once AIL_PLUGIN_DIR . 'includes/class-ail-elementor.php';
require_once AIL_PLUGIN_DIR . 'includes/class-ail-hooks.php';
if ( is_admin() ) {
	require_once AIL_PLUGIN_DIR . 'includes/class-ail-admin.php';
}

/**
 * Create the table. Plain CREATE TABLE IF NOT EXISTS: it never touches an existing table or its rows.
 */
function ail_create_table() {
	global $wpdb;
	$table   = $wpdb->prefix . 'ail_keywords';
	$charset = $wpdb->get_charset_collate();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
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
	return ail_maybe_migrate( $table );
}

/**
 * R22: migrate the old single-keyword table to the multi-keyword (JSON) layout.
 * Every step checks first, so running it twice is harmless, and the old column is
 * dropped only after every row has been copied. Returns true when the table is ready.
 */
function ail_maybe_migrate( $table ) {
	global $wpdb;

	$has = function ( $col ) use ( $wpdb, $table ) {
		return ! empty( $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE '{$col}'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( ! $has( $col ) ) {
			return false; // Stop here. Nothing has been dropped.
		}
	}
	if ( empty( $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'url_idx'" ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table} WHERE id NOT IN (SELECT id FROM (SELECT MIN(id) AS id FROM {$table} GROUP BY target_url) AS keep_rows)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY url_idx (target_url(191))" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$rows = $wpdb->get_results( "SELECT id, keyword FROM {$table} WHERE keywords IS NULL OR keywords = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( (array) $rows as $row ) {
		if ( '' !== (string) $row->keyword ) {
			$wpdb->update( $table, array( 'keywords' => wp_json_encode( array( $row->keyword ), JSON_UNESCAPED_UNICODE ) ), array( 'id' => $row->id ) );
		}
	}
	$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE (keywords IS NULL OR keywords = '') AND keyword <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $left > 0 ) {
		return false; // Some rows were not copied. Keep the old column.
	}
	$global_para = (int) get_option( 'ail_para_max', 0 );
	if ( $global_para > 0 ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET para_max = %d WHERE para_max = 0", $global_para ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	if ( ! empty( $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'keyword_idx'" ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX keyword_idx" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$wpdb->query( "ALTER TABLE {$table} DROP COLUMN keyword" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	delete_option( 'ail_para_max' );
	return true;
}

register_activation_hook( __FILE__, 'ail_activate' );
function ail_activate() {
	$ready = ail_create_table();
	add_option( 'ail_enabled', 1 );
	add_option( 'ail_zwnj_as_space', 1 );
	add_option( 'ail_diversity', 1 );
	add_option( 'ail_front_page', 1 );
	if ( $ready ) {
		update_option( 'ail_schema_version', AIL_SCHEMA_VERSION );
	}
}

register_deactivation_hook( __FILE__, 'ail_deactivate' );
function ail_deactivate() {
	// Nothing to clean. Links exist only in the page output, so they disappear by themselves.
}

/**
 * A plugin updated by replacing its files does not run the activation hook,
 * so the table and defaults are checked once per schema version.
 */
add_action( 'plugins_loaded', 'ail_boot' );
function ail_boot() {
	load_plugin_textdomain( 'autolink-by-mohsentavoosi', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( (int) get_option( 'ail_schema_version', 0 ) < AIL_SCHEMA_VERSION ) {
		$ready = ail_create_table();
		add_option( 'ail_zwnj_as_space', 1 );
		if ( $ready ) {
			update_option( 'ail_schema_version', AIL_SCHEMA_VERSION ); // Not saved when the migration stopped, so it retries.
		}
	}
	if ( is_admin() ) {
		AIL_Admin::init();
	}
}

// Hooks that change the output are registered after all post types and taxonomies exist.
add_action( 'init', array( 'AIL_Hooks', 'init' ), 20 );
add_action( 'init', array( 'AIL_Elementor', 'init' ), 20 );
