<?php
// Usage: php tests/admin-smoke.php
// Renders the settings screen and runs the search endpoint with small WordPress stand-ins, to catch PHP errors.
define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'MTAL_VERSION', '2.2.2' ); define( 'MTAL_PLUGIN_URL', '/' ); define( 'MTAL_PLUGIN_FILE', __FILE__ );
define( 'MTAL_DOCS_GENERAL_URL', 'https://mohsentavoosi.com/p/automate-internal-links' ); define( 'MTAL_DOCS_WP_URL', 'https://mohsentavoosi.com/p/wordpress-auto-internal-links' ); define( 'MTAL_AUTHOR_URL', 'https://mohsentavoosi.com' );
define( 'MTAL_DIVERSITY_INFO_URL', MTAL_DOCS_GENERAL_URL );
error_reporting( E_ALL ); set_error_handler( function ( $n, $s, $f, $l ) { echo "PHPERR [$n] $s at $l\n"; return true; } );
$GLOBALS['opt'] = array( 'mtal_exclude_posts' => array( 21 ), 'mtal_exclude_terms' => array( 3 ), 'mtal_taxonomies' => array( 'category' ) );
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $k ] : $d; }
foreach ( array( '__', 'esc_html__', 'esc_attr__', 'esc_html', 'esc_attr', 'esc_url', 'esc_textarea', 'sanitize_text_field', 'wp_unslash' ) as $f ) { eval( "function $f( \$s ) { return is_string( \$s ) ? \$s : \$s; }" ); }
function esc_html_e( $s ) { echo $s; } function esc_attr_e( $s ) { echo $s; } function _n( $a, $b, $n ) { return $n == 1 ? $a : $b; }
function checked( $a, $b = true ) { echo $a == $b ? ' checked' : ''; } function wp_nonce_field() {} function submit_button() { echo '<button>save</button>'; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; } function add_query_arg( $a, $u ) { return $u; } function current_user_can() { return true; }
function absint( $v ) { return abs( (int) $v ); } function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); }
function wp_list_pluck( $l, $f ) { return array_map( function ( $x ) use ( $f ) { return is_array( $x ) ? $x[ $f ] : $x->$f; }, $l ); }
class O { public $labels; function __construct( $n ) { $this->labels = (object) array( 'name' => $n, 'singular_name' => $n ); } }
function get_post_types() { return array( 'post' => new O( 'Post' ), 'page' => new O( 'Page' ) ); }
function get_taxonomies() { return array( 'category' => new O( 'Category' ) ); }
function get_post_type_object( $t ) { return new O( ucfirst( $t ) ); } function get_taxonomy( $t ) { return new O( 'Category' ); }
class WP_Term { public $term_id = 3; public $name = 'Doors'; public $taxonomy = 'category'; }
class WP_Post { public $ID; public $post_title; public $post_type = 'page'; function __construct( $i, $t ) { $this->ID = $i; $this->post_title = $t; } }
function get_post( $id ) { return new WP_Post( $id, 'About us' ); } function get_term( $id ) { return new WP_Term(); }
function get_posts( $a ) { return array( new WP_Post( 21, 'About us' ), new WP_Post( 22, 'About team' ) ); } function get_terms( $a ) { return array( new WP_Term() ); }
function check_ajax_referer() { return true; } function wp_send_json_success( $d ) { echo json_encode( $d, JSON_UNESCAPED_UNICODE ), "\n"; throw new Exception( 'sent' ); } function wp_send_json_error() { throw new Exception( 'err' ); }
class wpdb_stub { public $prefix = 'wp_'; function get_results() { return array(); } } $GLOBALS['wpdb'] = new wpdb_stub();
require __DIR__ . '/../mt-smart-link-automation/includes/class-mtal-processor.php';
require __DIR__ . '/../mt-smart-link-automation/includes/class-mtal-settings.php';
require __DIR__ . '/../mt-smart-link-automation/includes/class-mtal-admin.php';
ob_start(); MTAL_Admin::render_page(); $h = ob_get_clean();
$checks = array(
	'diversity tick on by default' => preg_match( '/name="mtal_diversity" value="1"\s+checked/', $h ),
	'unlock tick off by default'   => preg_match( '/name="mtal_unlock_capped" value="1" >/', $h ),
	'short tick off by default'    => preg_match( '/name="mtal_short_inside" value="1" >/', $h ),
	'front page tick on'           => preg_match( '/name="mtal_front_page" value="1"\s+checked/', $h ),
	'credit line with both guides' => false !== strpos( $h, 'href="https://mohsentavoosi.com/p/wordpress-auto-internal-links"' ) && false !== strpos( $h, 'class="ail-credit"' ),
	'info link to tutorial'        => false !== strpos( $h, 'href="https://mohsentavoosi.com/p/automate-internal-links" target="_blank"' ),
	'excluded post chip'           => false !== strpos( $h, 'value="p:21"' ) && false !== strpos( $h, 'About us' ),
	'excluded term chip'           => false !== strpos( $h, 'value="t:3"' ),
	'every new tick has a description' => false !== strpos( $h, 'On (recommended): inside each record' ) && false !== strpos( $h, 'Off (default): a longer keyword always keeps its place' ) && false !== strpos( $h, 'Off (default): a shorter keyword gets a link only' ) && false !== strpos( $h, 'Off: the home page of the site' ) && false !== strpos( $h, 'These never get automatic links' ),
);
foreach ( $checks as $k => $v ) { echo ( $v ? 'PASS ' : 'FAIL ' ) . $k . "\n"; }
$_GET = array( 'q' => 'about', 'kind' => 'all' );
try { MTAL_Admin::ajax_search(); } catch ( Exception $e ) {}
$_GET = array( 'q' => 'about', 'kind' => 'posts' );
try { MTAL_Admin::ajax_search(); } catch ( Exception $e ) {}
