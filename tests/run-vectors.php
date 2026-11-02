<?php
// Usage: php tests/run-vectors.php
// Runs the shared vectors, the plugin's own vectors and the 2.2 feature vectors against the real engine.
// Case formats: html/expected_html, fragments/expected_fragments (one by one, shared state),
// page_fragments/expected_fragments (processed together as one page), posts (archive: one state per post).
// ctx.opts sets diversity, unlock_capped, short_inside (defaults: true, false, false).
define( 'MTAL_TESTING', true );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
function esc_url_raw( $u, $p = null ) { return $u; }
require __DIR__ . '/../autolink-by-mohsentavoosi/includes/class-mtal-processor.php';

$files = array( __DIR__ . '/shared-vectors.json', __DIR__ . '/plugin-vectors.json', __DIR__ . '/feature-vectors.json' );
$total = 0; $fail = 0;
$base  = array( 'current_url' => 'https://site.test/post/', 'post_id' => 10, 'home' => 'https://site.test', 'zwnj_as_space' => true );
foreach ( $files as $f ) {
	$d = json_decode( file_get_contents( $f ), true );
	echo '== ' . basename( $f ) . "\n";
	foreach ( $d['cases'] as $c ) {
		if ( ! isset( $c['records'] ) ) { continue; } // Not an engine case.
		$ctx = array_merge( $base, isset( $c['ctx'] ) ? $c['ctx'] : array() );
		unset( $ctx['zwnj_is_letter'] );
		$prep = MTAL_Processor::prepare( $c['records'], (bool) $ctx['zwnj_as_space'] );
		if ( isset( $c['posts'] ) ) {
			$got = array(); $want = array();
			foreach ( $c['posts'] as $p ) {
				$state = MTAL_Processor::new_state();
				$got[]  = MTAL_Processor::run( $p['html'], $prep, array_merge( $ctx, $p['ctx'] ), $state );
				$want[] = $p['expected_html'];
			}
		} elseif ( isset( $c['page_fragments'] ) ) {
			$state = MTAL_Processor::new_state();
			$got   = MTAL_Processor::run_page( $c['page_fragments'], $prep, $ctx, $state );
			$want  = $c['expected_fragments'];
		} elseif ( isset( $c['fragments'] ) ) {
			$state = MTAL_Processor::new_state(); $got = array();
			foreach ( $c['fragments'] as $x ) {
				if ( ! empty( $c['new_state_per_fragment'] ) ) { $state = MTAL_Processor::new_state(); }
				$got[] = MTAL_Processor::run( $x, $prep, $ctx, $state );
			}
			$want = $c['expected_fragments'];
		} else {
			$state = MTAL_Processor::new_state();
			$got   = MTAL_Processor::run( $c['html'], $prep, $ctx, $state );
			$want  = $c['expected_html'];
		}
		$ok = $got === $want;
		$total++;
		echo ( $ok ? 'PASS ' : 'FAIL ' ) . $c['id'] . "\n";
		if ( ! $ok ) { $fail++; echo '   got:  ' . json_encode( $got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n   want: " . json_encode( $want, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n"; }
	}
}
echo ( $total - $fail ) . "/$total passed\n";
exit( $fail ? 1 : 0 );
