<?php
/**
 * Consistency check: every form action and AJAX action used by the admin screen has a registered hook,
 * and every nonce that is checked is also created. Runs without WordPress.
 *
 * @package Autolink_By_MohsenTavoosi
 */

$root  = dirname( __DIR__ ) . '/autolink-by-mohsentavoosi';
$admin = file_get_contents( $root . '/includes/class-mtal-admin.php' );
$js    = file_get_contents( $root . '/assets/js/admin.js' );

$fail = 0;
$pass = 0;
$check = function ( $ok, $label ) use ( &$fail, &$pass ) {
	if ( $ok ) {
		++$pass;
		echo "PASS $label\n";
	} else {
		++$fail;
		echo "FAIL $label\n";
	}
};

preg_match_all( '/name="action" value="([a-z_]+)"/', $admin, $m );
$form_actions = array_unique( $m[1] );
preg_match_all( "/add_action\( 'admin_post_([a-z_]+)'/", $admin, $m );
$post_hooks = array_unique( $m[1] );
preg_match_all( "/add_action\( 'wp_ajax_([a-z_]+)'/", $admin, $m );
$ajax_hooks = array_unique( $m[1] );
preg_match_all( '/action=([a-z_]+)/', $js, $m );
$js_actions = array_unique( $m[1] );

$check( count( $form_actions ) >= 5, 'found the form actions: ' . implode( ', ', $form_actions ) );
foreach ( $form_actions as $a ) {
	$check( in_array( $a, $post_hooks, true ), "form action $a has an admin_post hook" );
}
foreach ( $post_hooks as $a ) {
	$check( in_array( $a, $form_actions, true ), "admin_post hook $a is used by a form" );
}
$check( count( $js_actions ) >= 1, 'found the script actions: ' . implode( ', ', $js_actions ) );
foreach ( $js_actions as $a ) {
	$check( in_array( $a, $ajax_hooks, true ), "script action $a has a wp_ajax hook" );
}
foreach ( array_merge( $post_hooks, $ajax_hooks ) as $a ) {
	$check( 0 === strpos( $a, 'mtal_' ), "hook name $a uses the mtal_ prefix" );
}

preg_match_all( "/check_admin_referer\( '([a-z_]+)'/", $admin, $m );
$checked = array_unique( array_merge( $m[1], array() ) );
preg_match_all( "/check_ajax_referer\( '([a-z_]+)'/", $admin, $m );
$checked = array_unique( array_merge( $checked, $m[1] ) );
preg_match_all( "/wp_nonce_field\( '([a-z_]+)'/", $admin, $m );
$created = $m[1];
preg_match_all( "/wp_create_nonce\( '([a-z_]+)'/", $admin, $m );
$created = array_unique( array_merge( $created, $m[1] ) );
foreach ( $checked as $n ) {
	$check( in_array( $n, $created, true ), "nonce $n is created where it is checked" );
}

echo "\n" . $pass . '/' . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
