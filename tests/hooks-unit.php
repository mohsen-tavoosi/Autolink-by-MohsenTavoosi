<?php
// Usage: php tests/hooks-unit.php
// Unit test of whole-page processing in MTAL_Hooks with small WordPress stand-ins (no real WordPress).
define( 'ABSPATH', __DIR__ . '/' );
define( 'MTAL_TESTING', true );
define( 'ARRAY_A', 'ARRAY_A' );
class WP_Term { public $term_id; public $taxonomy = 'category'; }
function get_term( $id ) { $t = new WP_Term(); $t->term_id = $id; return $t; }
function get_term_link( $id, $tx ) { return 'https://site.test/c' . $id . '/'; }
function is_wp_error( $x ) { return false; }
$GLOBALS['opt']   = array( 'mtal_enabled' => 1, 'mtal_zwnj_as_space' => 1, 'mtal_diversity' => 1, 'mtal_front_page' => 1, 'mtal_exclude_posts' => array(), 'mtal_exclude_terms' => array(), 'mtal_post_types' => array( 'post', 'page' ), 'mtal_taxonomies' => array( 'category' ) );
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
$GLOBALS['hooks'] = array();
function add_filter( $tag, $cb = null ) { $GLOBALS['hooks'][] = $tag; return true; }
function add_action( $tag, $cb = null ) { $GLOBALS['hooks'][] = $tag; return true; }
function apply_filters( $tag, $value ) { return $value; }
function is_admin() { return false; } function wp_doing_ajax() { return false; }
function is_feed() { return false; } function is_robots() { return false; } function is_trackback() { return false; }
function wp_start_template_enhancement_output_buffer() { return false; } // Stand-in: WordPress 6.9 and later has this function.
class wpdb_stub { public $prefix = 'wp_'; public $rows = array(); function get_results() { return $this->rows; } }
$GLOBALS['wpdb'] = new wpdb_stub();
require __DIR__ . '/../mt-smart-link-automation/includes/class-mtal-processor.php';
require __DIR__ . '/../mt-smart-link-automation/includes/class-mtal-settings.php';
require __DIR__ . '/../mt-smart-link-automation/includes/class-mtal-hooks.php';

$pass = 0; $fail = 0;
function ok( $name, $cond, $info = '' ) { global $pass, $fail; if ( $cond ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n   $info\n"; } }
function set_buffering( $on ) { $r = new ReflectionProperty( 'MTAL_Hooks', 'buffering' ); $r->setAccessible( true ); $r->setValue( null, $on ); }
function records( $rows ) { $GLOBALS['wpdb']->rows = $rows; MTAL_Hooks::reset(); }
$U = 'https://site.test/u/';
$rec = array( 'id' => 1, 'keywords' => json_encode( array( 'alpha', 'beta' ) ), 'target_url' => $U, 'max_links' => 1, 'para_max' => 0, 'para_mode' => 'p_br', 'post_ids' => '' );

// 1. Two widgets of one page: keyword 1 in the second widget wins (smart diversity sees the whole page).
records( array( $rec ) ); set_buffering( true );
$w1 = MTAL_Hooks::process( '<p>beta here</p>', 'post', 5, 5, 'https://site.test/p5/' );
$w2 = MTAL_Hooks::process( '<p>alpha here</p>', 'post', 5, 5, 'https://site.test/p5/' );
ok( 'fragments are only marked while the page is built', false !== strpos( $w1, '<!--ail-s:post:5:' ) && false === strpos( $w1, '<a ' ), $w1 );
$page = '<html><nav>alpha menu</nav>' . $w1 . '<div>' . $w2 . '</div><footer>beta</footer></html>';
$out  = MTAL_Hooks::finish_buffer( $page );
ok( 'whole page: keyword 1 in the later widget gets the link', false !== strpos( $out, '<p><a href="' . $U . '">alpha</a> here</p>' ) && false !== strpos( $out, '<p>beta here</p>' ), $out );
ok( 'menu and footer are never touched', false !== strpos( $out, '<nav>alpha menu</nav>' ) && false !== strpos( $out, '<footer>beta</footer>' ), $out );
ok( 'no marker left in the page', false === strpos( $out, 'ail-s:' ) && false === strpos( $out, 'ail-e:' ), $out );

// 2. Escaped marker copy (fragment printed inside an attribute) is removed.
records( array( $rec ) ); set_buffering( true );
$w  = MTAL_Hooks::process( '<p>alpha</p>', 'post', 6, 6, 'https://site.test/p6/' );
$at = htmlspecialchars( $w );
$out = MTAL_Hooks::finish_buffer( '<meta content="' . $at . '">' . $w );
ok( 'escaped markers inside attributes are removed', false === strpos( $out, 'ail-s:' ) && false === strpos( $out, 'ail-e:' ), $out );

// 3. Excluded post: no marker, no link.
$GLOBALS['opt']['mtal_exclude_posts'] = array( 7 );
records( array( $rec ) ); set_buffering( true );
$w = MTAL_Hooks::process( '<p>alpha</p>', 'post', 7, 7, 'https://site.test/p7/' );
ok( 'excluded post is left untouched', '<p>alpha</p>' === $w, $w );
$GLOBALS['opt']['mtal_exclude_posts'] = array();

// 4. Excluded term.
$GLOBALS['opt']['mtal_exclude_terms'] = array( 3 );
records( array( $rec ) ); set_buffering( true );
$w = MTAL_Hooks::process( '<p>alpha</p>', 'term', 3, 0, 'https://site.test/c3/' );
ok( 'excluded term description is left untouched', '<p>alpha</p>' === $w, $w );
$GLOBALS['opt']['mtal_exclude_terms'] = array();

// 5. Front page switch off.
$GLOBALS['opt']['mtal_front_page'] = 0; $GLOBALS['front'] = true;
records( array( $rec ) ); set_buffering( true );
$w = MTAL_Hooks::process( '<p>alpha</p>', 'post', 8, 8, 'https://site.test/' );
ok( 'front page switch off: no links on the front page', '<p>alpha</p>' === $w, $w );
$GLOBALS['opt']['mtal_front_page'] = 1; $GLOBALS['front'] = false;

// 6. Without the page buffer (REST, feeds) each fragment is processed at once, like 2.1.
records( array( $rec ) ); set_buffering( false );
$w = MTAL_Hooks::process( '<p>beta alpha</p>', 'post', 9, 9, 'https://site.test/p9/' );
ok( 'no page buffer: fragment processed directly', '<p>beta <a href="' . $U . '">alpha</a></p>' === $w, $w );

// 7. Archive: each post keeps its own counter.
records( array( $rec ) ); set_buffering( true );
$a = MTAL_Hooks::process( '<p>alpha one</p>', 'post', 11, 11, 'https://site.test/p11/' );
$b = MTAL_Hooks::process( '<p>alpha two</p>', 'post', 12, 12, 'https://site.test/p12/' );
$out = MTAL_Hooks::finish_buffer( $a . $b );
ok( 'archive: every post has its own limit', 2 === substr_count( $out, '<a href="' . $U . '">alpha</a>' ), $out );

// 8. Marker without a context (fragment came from a cache): context is rebuilt from the key.
records( array( $rec ) ); set_buffering( true );
$out = MTAL_Hooks::finish_buffer( '<!--ail-s:post:13:900--><p>alpha</p><!--ail-e:post:13:900-->' );
ok( 'cached fragment: context rebuilt from its key', '<p><a href="' . $U . '">alpha</a></p>' === $out, $out );

// 9. Self link still blocked in whole-page mode.
records( array( array_merge( $rec, array( 'target_url' => 'https://site.test/p14/' ) ) ) ); set_buffering( true );
$w = MTAL_Hooks::process( '<p>alpha</p>', 'post', 14, 14, 'https://site.test/p14/' );
$out = MTAL_Hooks::finish_buffer( $w );
ok( 'page never links to itself', '<p>alpha</p>' === $out, $out );

// 10. No output buffer opened by the plugin (WordPress.org review, 5 Nov 2026): the core filter is used instead.
$level = ob_get_level(); $GLOBALS['hooks'] = array();
MTAL_Hooks::start_buffer();
ok( 'start_buffer registers the WordPress 6.9 filter and the started action', in_array( 'wp_template_enhancement_output_buffer', $GLOBALS['hooks'], true ) && in_array( 'wp_template_enhancement_output_buffer_started', $GLOBALS['hooks'], true ), implode( ',', $GLOBALS['hooks'] ) );
ok( 'start_buffer opens no output buffer', ob_get_level() === $level );
$files = array_merge( glob( __DIR__ . '/../mt-smart-link-automation/*.php' ), glob( __DIR__ . '/../mt-smart-link-automation/includes/*.php' ) );
$bad = array();
foreach ( $files as $f ) { if ( preg_match( '/\bob_start\s*\(/', file_get_contents( $f ) ) ) { $bad[] = basename( $f ); } }
ok( 'no ob_start() anywhere in the plugin', array() === $bad, implode( ',', $bad ) );

// 11. Fragments are marked only after WordPress started its buffer.
records( array( $rec ) ); set_buffering( false );
$w = MTAL_Hooks::process( '<p>alpha</p>', 'post', 21, 21, 'https://site.test/p21/' );
ok( 'before the buffer started: processed at once', '<p><a href="' . $U . '">alpha</a></p>' === $w, $w );
records( array( $rec ) ); MTAL_Hooks::buffer_started();
$w = MTAL_Hooks::process( '<p>alpha</p>', 'post', 22, 22, 'https://site.test/p22/' );
ok( 'after the buffer started: marked, not linked yet', false !== strpos( $w, '<!--ail-s:post:22:' ) && false === strpos( $w, '<a ' ), $w );
$out = MTAL_Hooks::finish_buffer( '<html>' . $w . '</html>' );
ok( 'the finish filter links the marked fragment and returns to direct mode', '<html><p><a href="' . $U . '">alpha</a></p></html>' === $out, $out );
$w = MTAL_Hooks::process( '<p>alpha</p>', 'post', 23, 23, 'https://site.test/p23/' );
ok( 'after the page is finished: direct mode again', false === strpos( $w, '<!--ail-s:' ), $w );

// 12. WooCommerce category description: filter, processed at once even while the page buffer is open.
records( array( $rec ) ); set_buffering( true );
$term = new WP_Term(); $term->term_id = 31; $term->taxonomy = 'category';
$w = MTAL_Hooks::filter_wc_archive_description( 'beta alpha text', $term );
ok( 'WooCommerce category description: linked at once, no marker', 'beta <a href="' . $U . '">alpha</a> text' === $w, $w );
$other = new WP_Term(); $other->term_id = 32; $other->taxonomy = 'not_allowed';
ok( 'WooCommerce category description: taxonomy not allowed is untouched', 'alpha' === MTAL_Hooks::filter_wc_archive_description( 'alpha', $other ) );
ok( 'WooCommerce category description: no term is untouched', 'alpha' === MTAL_Hooks::filter_wc_archive_description( 'alpha', null ) );
set_buffering( false );

echo "$pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
