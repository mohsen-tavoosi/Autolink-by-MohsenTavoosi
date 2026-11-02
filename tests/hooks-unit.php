<?php
// Usage: php tests/hooks-unit.php
// Unit test of whole-page processing in AIL_Hooks with small WordPress stand-ins (no real WordPress).
define( 'ABSPATH', __DIR__ . '/' );
define( 'AIL_TESTING', true );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Term { public $term_id; public $taxonomy = 'category'; }
function get_term( $id ) { $t = new WP_Term(); $t->term_id = $id; return $t; }
function get_term_link( $id, $tx ) { return 'https://site.test/c' . $id . '/'; }
function is_wp_error( $x ) { return false; }
$GLOBALS['opt']   = array( 'ail_enabled' => 1, 'ail_zwnj_as_space' => 1, 'ail_diversity' => 1, 'ail_front_page' => 1, 'ail_exclude_posts' => array(), 'ail_exclude_terms' => array(), 'ail_post_types' => array( 'post', 'page' ), 'ail_taxonomies' => array( 'category' ) );
$GLOBALS['front'] = false;
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opt'] ) ? $GLOBALS['opt'][ $k ] : $d; }
function home_url() { return 'https://site.test'; }
function is_front_page() { return $GLOBALS['front']; }
function get_post_type( $id ) { return 'post'; }
function get_permalink( $id ) { return 'https://site.test/p' . (int) ( is_object( $id ) ? $id->ID : $id ) . '/'; }
function wp_list_pluck( $l, $f ) { return array_map( function ( $x ) use ( $f ) { return $x[ $f ]; }, $l ); }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $k ) ); }
function esc_url_raw( $u, $p = null ) { return $u; }
function add_filter() {} function add_action() {}
class wpdb_stub { public $prefix = 'wp_'; public $rows = array(); function get_results() { return $this->rows; } }
$GLOBALS['wpdb'] = new wpdb_stub();
require __DIR__ . '/../autolink-by-mohsentavoosi/includes/class-ail-processor.php';
require __DIR__ . '/../autolink-by-mohsentavoosi/includes/class-ail-settings.php';
require __DIR__ . '/../autolink-by-mohsentavoosi/includes/class-ail-hooks.php';

$pass = 0; $fail = 0;
function ok( $name, $cond, $info = '' ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n   $info\n"; } }
function set_buffering( $on ) { $r = new ReflectionProperty( 'AIL_Hooks', 'buffering' ); $r->setAccessible( true ); $r->setValue( null, $on ); }
function records( $rows ) { $GLOBALS['wpdb']->rows = $rows; AIL_Hooks::reset(); }
$U = 'https://site.test/u/';
$rec = array( 'id' => 1, 'keywords' => json_encode( array( 'alpha', 'beta' ) ), 'target_url' => $U, 'max_links' => 1, 'para_max' => 0, 'para_mode' => 'p_br', 'post_ids' => '' );

// 1. Two widgets of one page: keyword 1 in the second widget wins (smart diversity sees the whole page).
records( array( $rec ) ); set_buffering( true );
$w1 = AIL_Hooks::process( '<p>beta here</p>', 'post', 5, 5, 'https://site.test/p5/' );
$w2 = AIL_Hooks::process( '<p>alpha here</p>', 'post', 5, 5, 'https://site.test/p5/' );
ok( 'fragments are only marked while the page is built', false !== strpos( $w1, '<!--ail-s:post:5:' ) && false === strpos( $w1, '<a ' ), $w1 );
$page = '<html><nav>alpha menu</nav>' . $w1 . '<div>' . $w2 . '</div><footer>beta</footer></html>';
$out  = AIL_Hooks::finish_buffer( $page );
ok( 'whole page: keyword 1 in the later widget gets the link', false !== strpos( $out, '<p><a href="' . $U . '">alpha</a> here</p>' ) && false !== strpos( $out, '<p>beta here</p>' ), $out );
ok( 'menu and footer are never touched', false !== strpos( $out, '<nav>alpha menu</nav>' ) && false !== strpos( $out, '<footer>beta</footer>' ), $out );
ok( 'no marker left in the page', false === strpos( $out, 'ail-s:' ) && false === strpos( $out, 'ail-e:' ), $out );

// 2. Escaped marker copy (fragment printed inside an attribute) is removed.
records( array( $rec ) ); set_buffering( true );
$w  = AIL_Hooks::process( '<p>alpha</p>', 'post', 6, 6, 'https://site.test/p6/' );
$at = htmlspecialchars( $w );
$out = AIL_Hooks::finish_buffer( '<meta content="' . $at . '">' . $w );
ok( 'escaped markers inside attributes are removed', false === strpos( $out, 'ail-s:' ) && false === strpos( $out, 'ail-e:' ), $out );

// 3. Excluded post: no marker, no link.
$GLOBALS['opt']['ail_exclude_posts'] = array( 7 );
records( array( $rec ) ); set_buffering( true );
$w = AIL_Hooks::process( '<p>alpha</p>', 'post', 7, 7, 'https://site.test/p7/' );
ok( 'excluded post is left untouched', '<p>alpha</p>' === $w, $w );
$GLOBALS['opt']['ail_exclude_posts'] = array();

// 4. Excluded term.
$GLOBALS['opt']['ail_exclude_terms'] = array( 3 );
records( array( $rec ) ); set_buffering( true );
$w = AIL_Hooks::process( '<p>alpha</p>', 'term', 3, 0, 'https://site.test/c3/' );
ok( 'excluded term description is left untouched', '<p>alpha</p>' === $w, $w );
$GLOBALS['opt']['ail_exclude_terms'] = array();

// 5. Front page switch off.
$GLOBALS['opt']['ail_front_page'] = 0; $GLOBALS['front'] = true;
records( array( $rec ) ); set_buffering( true );
$w = AIL_Hooks::process( '<p>alpha</p>', 'post', 8, 8, 'https://site.test/' );
ok( 'front page switch off: no links on the front page', '<p>alpha</p>' === $w, $w );
$GLOBALS['opt']['ail_front_page'] = 1; $GLOBALS['front'] = false;

// 6. Without the page buffer (REST, feeds) each fragment is processed at once, like 2.1.
records( array( $rec ) ); set_buffering( false );
$w = AIL_Hooks::process( '<p>beta alpha</p>', 'post', 9, 9, 'https://site.test/p9/' );
ok( 'no page buffer: fragment processed directly', '<p>beta <a href="' . $U . '">alpha</a></p>' === $w, $w );

// 7. Archive: each post keeps its own counter.
records( array( $rec ) ); set_buffering( true );
$a = AIL_Hooks::process( '<p>alpha one</p>', 'post', 11, 11, 'https://site.test/p11/' );
$b = AIL_Hooks::process( '<p>alpha two</p>', 'post', 12, 12, 'https://site.test/p12/' );
$out = AIL_Hooks::finish_buffer( $a . $b );
ok( 'archive: every post has its own limit', 2 === substr_count( $out, '<a href="' . $U . '">alpha</a>' ), $out );

// 8. Marker without a context (fragment came from a cache): context is rebuilt from the key.
records( array( $rec ) ); set_buffering( true );
$out = AIL_Hooks::finish_buffer( '<!--ail-s:post:13:900--><p>alpha</p><!--ail-e:post:13:900-->' );
ok( 'cached fragment: context rebuilt from its key', '<p><a href="' . $U . '">alpha</a></p>' === $out, $out );

// 9. Self link still blocked in whole-page mode.
records( array( array_merge( $rec, array( 'target_url' => 'https://site.test/p14/' ) ) ) ); set_buffering( true );
$w = AIL_Hooks::process( '<p>alpha</p>', 'post', 14, 14, 'https://site.test/p14/' );
$out = AIL_Hooks::finish_buffer( $w );
ok( 'page never links to itself', '<p>alpha</p>' === $out, $out );

echo "$pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
