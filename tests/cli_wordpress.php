<?php
// Usage: php tests/cli_wordpress.php /path/to/wordpress   (throw-away site with the plugin active)
$wp = isset( $argv[1] ) ? $argv[1] : '';
$_SERVER['HTTP_HOST'] = '127.0.0.1:8088'; $_SERVER['REQUEST_URI'] = '/';
require rtrim( $wp, '/' ) . '/wp-load.php';
$res = array();
function ok( $name, $cond, $detail = '' ) { global $res; $res[] = (bool) $cond; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . ( $cond ? '' : '   -> ' . substr( print_r( $detail, true ), 0, 300 ) ) . "\n"; }
global $wpdb;
$t = $wpdb->prefix . 'ail_keywords';
$Z = "\u{200C}";

// 1. Upgrade from the old single-keyword table (R22), by replacing files only (no activation hook).
$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
$wpdb->query( "CREATE TABLE {$t} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, keyword VARCHAR(255) NOT NULL, target_url VARCHAR(2083) NOT NULL, max_links INT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY keyword_idx (keyword(191)))" );
$wpdb->insert( $t, array( 'keyword' => 'dscr loan', 'target_url' => '/a', 'max_links' => 2 ) );
$wpdb->insert( $t, array( 'keyword' => 'لیزینگ', 'target_url' => '/b', 'max_links' => 1 ) );
$wpdb->insert( $t, array( 'keyword' => 'dup', 'target_url' => '/a', 'max_links' => 1 ) );
update_option( 'ail_para_max', 2 );
delete_option( 'ail_schema_version' );
ail_boot();
$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$t}" );
$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id", ARRAY_A );
ok( 'R22 old column removed and new columns added', ! in_array( 'keyword', $cols, true ) && in_array( 'keywords', $cols, true ) && in_array( 'para_mode', $cols, true ) && in_array( 'post_ids', $cols, true ), $cols );
ok( 'R22 keywords converted to JSON, Persian kept readable', 2 === count( $rows ) && '["dscr loan"]' === $rows[0]['keywords'] && false !== strpos( $rows[1]['keywords'], 'لیزینگ' ), $rows );
ok( 'R22 duplicate URL removed (lowest id kept), old global para_max copied', '/a' === $rows[0]['target_url'] && 2 === (int) $rows[0]['para_max'] && 'p_br' === $rows[0]['para_mode'], $rows );
ok( 'R22 old option removed and schema version saved', false === get_option( 'ail_para_max', false ) && 2 === (int) get_option( 'ail_schema_version' ) );
$before = $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
delete_option( 'ail_schema_version' ); ail_boot();
ok( 'R22 running the upgrade twice changes nothing', $before === $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ) );
$wpdb->query( "DELETE FROM {$t}" );

// 2. Rendering details.
$wpdb->insert( $t, array( 'keywords' => wp_json_encode( array( 'درب اتوماتیک' ), JSON_UNESCAPED_UNICODE ), 'target_url' => '/door', 'max_links' => 1, 'para_max' => 0, 'para_mode' => 'p_br', 'post_ids' => '' ) );
update_option( 'ail_post_types', array( 'post', 'page', 'product' ) );
AIL_Hooks::init(); AIL_Hooks::reset();
$b = get_page_by_path( 'post-b', OBJECT, 'post' );
$GLOBALS['post'] = $b; setup_postdata( $b );
$one = apply_filters( 'the_content', $b->post_content );
ok( 'the_content adds the link', 1 === substr_count( $one, '<a href="/door">' ), $one );
$again = apply_filters( 'the_content', $one );
ok( 'output processed a second time is left alone (no double count)', $again === $one, $again );
$short = apply_filters( 'woocommerce_short_description', '<p>درب اتوماتیک</p>' );
ok( 'WooCommerce short description shares the post counter (max 1 already used)', false === strpos( $short, '<a ' ), $short );
AIL_Hooks::reset();
$short = apply_filters( 'woocommerce_short_description', '<p>درب اتوماتیک</p>' );
ok( 'WooCommerce short description is linked when the counter is free', 1 === substr_count( $short, '<a href="/door">' ), $short );
AIL_Hooks::reset();
add_filter( 'the_content', function ( $c ) { return apply_filters( 'wpv_filter_wpv_view_shortcode_output', $c ); }, 5 );
$inside = apply_filters( 'the_content', '<p>درب اتوماتیک</p>' );
ok( 'Toolset view inside the_content is not processed twice', 1 === substr_count( $inside, '<a href="/door">' ), $inside );
remove_all_filters( 'the_content', 5 );
AIL_Hooks::reset();
$view = apply_filters( 'wpv_filter_wpv_view_shortcode_output', '<p>درب اتوماتیک</p>' );
ok( 'Toolset view outside the_content is linked', 1 === substr_count( $view, '<a href="/door">' ), $view );
AIL_Hooks::reset();
$GLOBALS['current_screen'] = new class { public function in_admin( $c = null ) { return true; } };
$adm = apply_filters( 'the_content', '<p>درب اتوماتیک</p>' );
ok( 'admin screens are left alone (nothing is ever saved with links)', false === strpos( $adm, '<a ' ), $adm );
$GLOBALS['current_screen'] = null;
AIL_Hooks::reset();

// 3. Persian slug: the permalink is percent-encoded, the record may be typed decoded (R12).
$pid = wp_insert_post( array( 'post_title' => 'درب', 'post_name' => 'درب-خودکار', 'post_status' => 'publish', 'post_content' => '<p>درب اتوماتیک</p>' ) );
$p = get_post( $pid ); $GLOBALS['post'] = $p; setup_postdata( $p );
$wpdb->query( "DELETE FROM {$t}" );
$wpdb->insert( $t, array( 'keywords' => '["درب اتوماتیک"]', 'target_url' => urldecode( get_permalink( $p ) ), 'max_links' => 1, 'para_max' => 0, 'para_mode' => 'p_br', 'post_ids' => '' ) );
AIL_Hooks::reset();
$self = apply_filters( 'the_content', $p->post_content );
ok( 'R12 percent-encoded permalink equals the decoded URL typed in the record', false === strpos( $self, '<a ' ), array( get_permalink( $p ), $self ) );
wp_delete_post( $pid, true );

// 4. Uninstall (R: data is kept unless the box was ticked).
$wpdb->query( "DELETE FROM {$t}" );
define( 'WP_UNINSTALL_PLUGIN', 'autolink-by-mohsentavoosi/autolink-by-mohsentavoosi.php' );
update_option( 'ail_delete_on_uninstall', 0 );
include AIL_PLUGIN_DIR . 'uninstall.php';
ok( 'uninstall keeps the table when the box is not ticked', (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$t}'" ) );
update_option( 'ail_delete_on_uninstall', 1 );
include AIL_PLUGIN_DIR . 'uninstall.php';
ok( 'uninstall removes table and options when the box is ticked', ! $wpdb->get_var( "SHOW TABLES LIKE '{$t}'" ) && false === get_option( 'ail_zwnj_as_space', false ) );
ail_create_table(); add_option( 'ail_zwnj_as_space', 1 ); update_option( 'ail_schema_version', 2 ); update_option( 'ail_enabled', 1 );

$fail = count( array_filter( $res, function ( $x ) { return ! $x; } ) );
echo ( count( $res ) - $fail ) . '/' . count( $res ) . " passed\n";
exit( $fail ? 1 : 0 );
