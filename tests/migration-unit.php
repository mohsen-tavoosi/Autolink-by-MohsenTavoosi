<?php
// Usage: php tests/migration-unit.php
// Checks the move from the old "ail_" prefix (table and options) to "mtal_", with small WordPress stand-ins.
define( 'ABSPATH', __DIR__ . '/' );
error_reporting( E_ALL );
set_error_handler( function ( $n, $s, $f, $l ) { echo "PHPERR [$n] $s at $l\n"; return true; } );
$GLOBALS['opts']    = array();
$GLOBALS['actions'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function add_option( $k, $v ) { if ( ! array_key_exists( $k, $GLOBALS['opts'] ) ) { $GLOBALS['opts'][ $k ] = $v; } return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; } function plugin_dir_url( $f ) { return '/'; }
function register_activation_hook() {} function register_deactivation_hook() {} function add_action() {} function is_admin() { return false; }
class Fake_Db {
	public $prefix = 'wp_'; public $tables = array(); public $log = array();
	function esc_like( $s ) { return $s; }
	function prepare( $q, $a ) { return str_replace( '%s', "'" . $a . "'", $q ); }
	function get_var( $q ) {
		if ( preg_match( "/SHOW TABLES LIKE '(.*)'/", $q, $m ) ) { return isset( $this->tables[ $m[1] ] ) ? $m[1] : null; }
		if ( preg_match( '/SELECT COUNT\(\*\) FROM (\S+)/', $q, $m ) ) { return $this->tables[ $m[1] ] ?? 0; }
		return null;
	}
	function query( $q ) {
		$this->log[] = $q;
		if ( preg_match( '/^DROP TABLE (\S+)/', $q, $m ) ) { unset( $this->tables[ $m[1] ] ); }
		if ( preg_match( '/^RENAME TABLE (\S+) TO (\S+)/', $q, $m ) ) { $this->tables[ $m[2] ] = $this->tables[ $m[1] ]; unset( $this->tables[ $m[1] ] ); }
		return true;
	}
}
require __DIR__ . '/../autolink-by-mohsentavoosi/autolink-by-mohsentavoosi.php';
$ok = 0; $bad = 0;
function check( $name, $cond ) { global $ok, $bad; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n"; $cond ? ++$ok : ++$bad; }

// 1. Old install: table with 5 rows, old options.
$GLOBALS['wpdb'] = new Fake_Db(); $GLOBALS['wpdb']->tables['wp_ail_keywords'] = 5;
$GLOBALS['opts'] = array( 'ail_enabled' => 1, 'ail_diversity' => 0, 'ail_exclude_posts' => array( 7 ), 'ail_schema_version' => 2 );
mtal_migrate_legacy_prefix();
check( 'old table renamed with its rows', isset( $wpdb->tables['wp_mtal_keywords'] ) && 5 === $wpdb->tables['wp_mtal_keywords'] && ! isset( $wpdb->tables['wp_ail_keywords'] ) );
check( 'options copied (value 0 kept)', 1 === $opts['mtal_enabled'] && 0 === $opts['mtal_diversity'] && array( 7 ) === $opts['mtal_exclude_posts'] );
check( 'old options removed', ! isset( $opts['ail_enabled'] ) && ! isset( $opts['ail_schema_version'] ) );

// 2. Old rows win over an empty new table made by a fresh start.
$GLOBALS['wpdb'] = new Fake_Db(); $wpdb = $GLOBALS['wpdb']; $wpdb->tables['wp_ail_keywords'] = 3; $wpdb->tables['wp_mtal_keywords'] = 0;
mtal_migrate_legacy_prefix();
check( 'empty new table replaced by old data', 3 === $wpdb->tables['wp_mtal_keywords'] && ! isset( $wpdb->tables['wp_ail_keywords'] ) );

// 3. New table already has rows: old table is left alone.
$GLOBALS['wpdb'] = new Fake_Db(); $wpdb = $GLOBALS['wpdb']; $wpdb->tables['wp_ail_keywords'] = 3; $wpdb->tables['wp_mtal_keywords'] = 9;
mtal_migrate_legacy_prefix();
check( 'filled new table is never overwritten', 9 === $wpdb->tables['wp_mtal_keywords'] && 3 === $wpdb->tables['wp_ail_keywords'] );

// 4. New value wins over the old one; fresh install does nothing.
$GLOBALS['wpdb'] = new Fake_Db(); $wpdb = $GLOBALS['wpdb'];
$GLOBALS['opts'] = array( 'ail_front_page' => 0, 'mtal_front_page' => 1 );
mtal_migrate_legacy_prefix();
check( 'existing new option is kept', 1 === $opts['mtal_front_page'] && ! isset( $opts['ail_front_page'] ) );
$GLOBALS['opts'] = array(); $before = count( $wpdb->log );
mtal_migrate_legacy_prefix();
check( 'fresh install changes nothing', array() === $opts && count( $wpdb->log ) === $before );

echo "$ok/" . ( $ok + $bad ) . " passed\n";
exit( $bad ? 1 : 0 );
