<?php
/**
 * Autolink by MohsenTavoosi engine.
 *
 * Pure engine: it never reads options or the database. Everything arrives as
 * parameters, so the shared test vectors can run it outside WordPress.
 * It never writes anything: it only turns an HTML string into another HTML string (R01).
 *
 * @package Auto_Internal_Links
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'AIL_TESTING' ) ) {
	exit;
}

require_once __DIR__ . '/class-ail-run.php';

class AIL_Processor {

	const ZWNJ = "\xE2\x80\x8C";

	/** Fresh per-context state (one per post or term, R15). */
	public static function new_state() {
		return array( 'page' => array(), 'kw' => array(), 'phrase' => array(), 'para' => array() );
	}

	/**
	 * R21: only http(s) or a site-relative path starting with a single slash.
	 */
	public static function is_safe_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || strlen( $url ) > 2083 ) {
			return false;
		}
		if ( preg_match( '/[\x00-\x1F\x7F\s<>"\\\\]/u', $url ) ) {
			return false;
		}
		if ( preg_match( '#^https?://[^/?\#]+#i', $url ) ) {
			return true;
		}
		return '/' === $url[0] && ( 1 === strlen( $url ) || '/' !== $url[1] );
	}

	/**
	 * Normalise text for comparison: entities, nbsp, optional ZWNJ as space, case, spaces.
	 *
	 * @param bool $decode Decode HTML entities first (keywords yes, DOM text no).
	 */
	public static function norm_text( $s, $zwnj_as_space, $decode = true ) {
		$s = (string) $s;
		if ( $decode ) {
			$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		$s = str_replace( "\xC2\xA0", ' ', $s );
		if ( $zwnj_as_space ) {
			$s = str_replace( self::ZWNJ, ' ', $s );
		}
		$t = preg_replace( '/\s+/u', ' ', $s );
		if ( null !== $t ) {
			$s = $t;
		}
		return mb_strtolower( trim( $s ), 'UTF-8' );
	}

	/** Normalise a URL for equality checks (R11, R12). */
	public static function norm_url( $u, $home = '' ) {
		$u = trim( html_entity_decode( (string) $u, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $u ) {
			return '';
		}
		$u = explode( '#', $u, 2 )[0];
		if ( '' !== $u && '/' === $u[0] && '//' !== substr( $u, 0, 2 ) && '' !== $home ) {
			$u = rtrim( $home, '/' ) . $u;
		}
		$u = rawurldecode( $u );
		$u = preg_replace( '#^https?://#i', '', $u );
		return rtrim( mb_strtolower( (string) $u, 'UTF-8' ), '/' );
	}

	/**
	 * One PCRE pattern per keyword.
	 * R05 whole word (letters, digits and ZWNJ never touch the match),
	 * R06 case insensitive, R20 nbsp aware, and the ZWNJ switch:
	 * when on, a space and a half-space inside a keyword are the same thing.
	 */
	public static function build_pattern( $keyword, $zwnj_as_space ) {
		$keyword = html_entity_decode( (string) $keyword, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$split   = $zwnj_as_space ? '/[\s\x{00A0}\x{200C}]+/u' : '/[\s\x{00A0}]+/u';
		$parts   = preg_split( $split, trim( $keyword ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $parts ) {
			return null;
		}
		$quoted = array();
		foreach ( $parts as $p ) {
			$quoted[] = preg_quote( $p, '/' );
		}
		$sep    = $zwnj_as_space ? '[\s\x{00A0}\x{200C}]+' : '[\s\x{00A0}]+';
		$letter = '[\p{L}\p{N}\x{200C}]';
		return '/(?<!' . $letter . ')' . implode( $sep, $quoted ) . '(?!' . $letter . ')/iu';
	}

	/**
	 * Turn raw records into rules and a sorted candidate list.
	 * R07 longest keyword first, R24 ties broken by record id then keyword order.
	 *
	 * @param array $rows Records (arrays or objects): id, keywords, target_url, max_links, para_max, para_mode, post_ids.
	 */
	public static function prepare( array $rows, $zwnj_as_space ) {
		$rules = array();
		$cands = array();
		foreach ( $rows as $row ) {
			$row = (array) $row;
			$id  = (int) ( isset( $row['id'] ) ? $row['id'] : 0 );
			$url = trim( html_entity_decode( (string) ( isset( $row['target_url'] ) ? $row['target_url'] : '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); // R21: escaped exactly once on output.
			if ( ! self::is_safe_url( $url ) ) {
				continue;
			}
			$kws = isset( $row['keywords'] ) ? $row['keywords'] : array();
			if ( is_string( $kws ) ) {
				$d   = json_decode( $kws, true );
				$kws = is_array( $d ) ? $d : array( $kws );
			}
			$mode = ( isset( $row['para_mode'] ) && in_array( $row['para_mode'], array( 'heading', 'headings' ), true ) ) ? 'heading' : 'p_br';
			$pids = isset( $row['post_ids'] ) ? $row['post_ids'] : array();
			if ( is_string( $pids ) ) {
				$pids = preg_split( '/[\s,،]+/u', $pids, -1, PREG_SPLIT_NO_EMPTY );
			}
			$pids = array_values( array_filter( array_map( 'intval', (array) $pids ) ) );
			$max  = isset( $row['max_links'] ) ? (int) $row['max_links'] : 1;
			$rule = array(
				'id'         => $id,
				'target_url' => $url,
				'max_links'  => $max > 0 ? $max : 1,
				'para_max'   => isset( $row['para_max'] ) ? max( 0, (int) $row['para_max'] ) : 0,
				'para_mode'  => $mode,
				'post_ids'   => $pids,
				'norm'       => array(),
			);
			foreach ( array_values( (array) $kws ) as $i => $kw ) {
				if ( ! is_scalar( $kw ) ) {
					continue;
				}
				$n = self::norm_text( $kw, $zwnj_as_space, true );
				if ( '' === $n ) {
					continue;
				}
				$rx = self::build_pattern( $kw, $zwnj_as_space );
				if ( ! $rx ) {
					continue;
				}
				if ( ! isset( $rule['norm'][ $n ] ) ) {
					$rule['norm'][ $n ] = $i; // Keyword index, for smart link diversity.
				}
				$cands[] = array( mb_strlen( $n, 'UTF-8' ), $id, $i, $rx, $n );
			}
			if ( $rule['norm'] ) {
				$rules[ $id ] = $rule;
			}
		}
		usort(
			$cands,
			function ( $a, $b ) {
				if ( $a[0] !== $b[0] ) {
					return $b[0] > $a[0] ? 1 : -1;
				}
				if ( $a[1] !== $b[1] ) {
					return $a[1] < $b[1] ? -1 : 1;
				}
				return $a[2] < $b[2] ? -1 : ( $a[2] > $b[2] ? 1 : 0 );
			}
		);
		return array(
			'rules' => $rules,
			'cands' => $cands,
			'zwnj'  => (bool) $zwnj_as_space,
		);
	}

	/** Default options for a run. Smart link diversity is on, the two unlock options are off. */
	public static function default_opts() {
		return array(
			'diversity'     => true,
			'unlock_capped' => false,
			'short_inside'  => false,
		);
	}

	/**
	 * Process one fragment. Kept for callers that have a single fragment.
	 *
	 * @param string $html  Fragment to process.
	 * @param array  $prep  Result of prepare().
	 * @param array  $ctx   post_id, current_url, home, opts.
	 * @param array  $state Result of new_state(), shared by every fragment of one post or term (R15).
	 */
	public static function run( $html, array $prep, array $ctx, array &$state ) {
		$out = self::run_page( array( (string) $html ), $prep, $ctx, $state );
		return $out[0];
	}

	/**
	 * Process all content fragments of one page together, so limits and keyword order see the whole page.
	 *
	 * @param string[] $fragments Fragments in page order.
	 * @param array    $prep      Result of prepare().
	 * @param array    $ctx       post_id, current_url, home, opts.
	 * @param array    $state     Result of new_state().
	 * @return string[] Processed fragments, same keys and order.
	 */
	public static function run_page( array $fragments, array $prep, array $ctx, array &$state ) {
		$fragments = array_map( 'strval', array_values( $fragments ) );
		if ( ! $fragments || empty( $prep['cands'] ) ) {
			return $fragments;
		}
		$home   = isset( $ctx['home'] ) ? (string) $ctx['home'] : '';
		$opts   = array_merge( self::default_opts(), isset( $ctx['opts'] ) && is_array( $ctx['opts'] ) ? $ctx['opts'] : array() );
		$active = array();
		$post   = isset( $ctx['post_id'] ) ? (int) $ctx['post_id'] : 0;
		$cur    = self::norm_url( isset( $ctx['current_url'] ) ? $ctx['current_url'] : '', $home );
		foreach ( $prep['rules'] as $id => $r ) {
			if ( $r['post_ids'] && ! in_array( $post, $r['post_ids'], true ) ) {
				continue; // R13.
			}
			if ( '' !== $cur && self::norm_url( $r['target_url'], $home ) === $cur ) {
				continue; // R12.
			}
			$active[ $id ] = $r;
		}
		if ( ! $active ) {
			return $fragments;
		}
		$cands = array();
		foreach ( $prep['cands'] as $c ) {
			if ( isset( $active[ $c[1] ] ) ) {
				$cands[] = $c;
			}
		}

		// Nothing to do? Return the original bytes untouched (no DOM round trip).
		$plain = html_entity_decode( (string) preg_replace( '/<[^>]*>/', ' ', implode( ' ', $fragments ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ); // Tags count as separators.
		$hit   = false;
		foreach ( $cands as $c ) {
			if ( preg_match( $c[3], $plain ) ) {
				$hit = true;
				break;
			}
		}
		if ( ! $hit ) {
			return $fragments;
		}

		$zwnj = ! empty( $prep['zwnj'] );
		$try  = $state;
		$run  = new AIL_Run( $active, $cands, $try, $zwnj, $home, $opts );
		$out  = $run->execute( $fragments );
		if ( null !== $out ) {
			$state = $try;
			return $out;
		}
		// A fragment could not be wrapped safely: process them one by one with the shared state.
		$out = array();
		foreach ( $fragments as $f ) {
			$run   = new AIL_Run( $active, $cands, $state, $zwnj, $home, $opts );
			$one   = '' === trim( $f ) ? null : $run->execute( array( $f ) );
			$out[] = null === $one ? $f : $one[0];
		}
		return $out;
	}
}
