<?php
/**
 * Output hooks. Links are added only while a page is rendered (R01). Nothing is ever saved.
 *
 * Every callback starts with the global switch (R17).
 *
 * @package Autolink_By_MohsenTavoosi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MTAL_Hooks {

	/** One state per post or term (R15), for the whole request. */
	private static $states = array();

	/** md5 of outputs we already produced, so the same text is never processed twice. */
	private static $seen = array();

	private static $prepared = null;

	private static $wc_buffer_level = null;

	/** True while the whole-page buffer is open: fragments are marked now and processed together at the end. */
	private static $buffering = false;

	/** Context per page key (post_id, url) for the marked fragments. */
	private static $contexts = array();

	/** Running number for fragment markers. */
	private static $seq = 0;

	public static function init() {
		if ( ! MTAL_Settings::enabled() ) {
			return; // Second safety net: every callback also checks the switch.
		}
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 99 );
		add_filter( 'term_description', array( __CLASS__, 'filter_term_description' ), 99, 4 );
		add_filter( 'woocommerce_short_description', array( __CLASS__, 'filter_wc_short_description' ), 99 );
		add_action( 'woocommerce_archive_description', array( __CLASS__, 'wc_archive_start' ), 5 );
		add_action( 'woocommerce_archive_description', array( __CLASS__, 'wc_archive_end' ), 99 );
		add_filter( 'wpv_filter_wpv_view_shortcode_output', array( __CLASS__, 'filter_toolset_view' ), 99 );
		add_action( 'wp', array( __CLASS__, 'reset' ) );
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 1 );
	}

	// ── Whole page ───────────────────────────────────────────────────────────

	/**
	 * Open one output buffer for the front-end page. Content fragments (post content, widgets,
	 * descriptions) are only marked while the page is built. When the page is complete, all
	 * fragments of the same post or term are processed together, so limits and keyword order
	 * see the whole page. Menus, header and footer are never touched because they are not marked.
	 */
	public static function start_buffer() {
		if ( ! MTAL_Settings::enabled() || self::skip_request() || is_feed() || is_robots() || is_trackback() || wp_doing_ajax() ) {
			return;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return;
		}
		if ( ! apply_filters( 'mtal_whole_page', true ) ) {
			return; // Escape hatch: process each fragment on its own, like version 2.1.
		}
		self::$buffering = true;
		ob_start( array( __CLASS__, 'finish_buffer' ) );
	}

	/**
	 * Output buffer callback: process all marked fragments, then remove the markers.
	 *
	 * @param string $html Full page.
	 */
	public static function finish_buffer( $html ) {
		self::$buffering = false;
		if ( ! is_string( $html ) || false === strpos( $html, 'ail-s:' ) ) {
			return $html;
		}
		try {
			$html = self::process_marked( $html );
		} catch ( \Throwable $e ) {
			$html = self::strip_markers( $html );
		}
		return self::strip_markers( $html );
	}

	/** Find marked fragments, group them by page key and run the engine once per key. */
	public static function process_marked( $html ) {
		$re = '#<!--ail-s:([a-z]+:\d+):(\d+)-->(.*?)<!--ail-e:\1:\2-->#s';
		if ( ! preg_match_all( $re, $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$groups = array();
		foreach ( $m as $hit ) {
			$groups[ $hit[1][0] ][] = array(
				'start' => $hit[0][1],
				'len'   => strlen( $hit[0][0] ),
				'inner' => self::strip_markers( $hit[3][0] ),
			);
		}
		$prep = self::prepared();
		$repl = array();
		foreach ( $groups as $key => $items ) {
			$ctx   = isset( self::$contexts[ $key ] ) ? self::$contexts[ $key ] : self::ctx_for_key( $key );
			$frags = wp_list_pluck( $items, 'inner' );
			$outs  = $frags;
			if ( $ctx && ! empty( $prep['cands'] ) ) {
				if ( ! isset( self::$states[ $key ] ) ) {
					self::$states[ $key ] = MTAL_Processor::new_state();
				}
				$outs = MTAL_Processor::run_page( $frags, $prep, $ctx, self::$states[ $key ] );
			}
			foreach ( $items as $i => $it ) {
				$repl[ $it['start'] ] = array( $it['len'], $outs[ $i ] );
			}
		}
		krsort( $repl );
		foreach ( $repl as $start => $r ) {
			$html = substr_replace( $html, $r[1], $start, $r[0] );
		}
		return $html;
	}

	/**
	 * Context for a key whose fragment came from a cache (for example Elementor element caching),
	 * so its filter did not run in this request. Exclusions are checked again here.
	 */
	private static function ctx_for_key( $key ) {
		list( $type, $id ) = explode( ':', $key );
		$id                = (int) $id;
		if ( ! MTAL_Settings::front_page() && is_front_page() ) {
			return null;
		}
		if ( 'post' === $type ) {
			$pt = get_post_type( $id );
			if ( ! $pt || ! MTAL_Settings::post_type_allowed( $pt ) || MTAL_Settings::post_excluded( $id ) ) {
				return null;
			}
			$url = (string) get_permalink( $id );
			$pid = $id;
		} else {
			$term = get_term( $id );
			if ( ! ( $term instanceof WP_Term ) || ! MTAL_Settings::taxonomy_allowed( $term->taxonomy ) || MTAL_Settings::term_excluded( $id ) ) {
				return null;
			}
			$url = self::term_url( $id, $term->taxonomy );
			$pid = 0;
		}
		return array(
			'post_id'     => $pid,
			'current_url' => $url,
			'home'        => home_url(),
			'opts'        => MTAL_Settings::run_opts(),
		);
	}

	/** Remove markers, also escaped copies (a fragment printed inside an attribute). */
	public static function strip_markers( $html ) {
		return (string) preg_replace( '~(<!--|&lt;!--|&#0?60;!--)ail-[se]:[a-z]+:\d+:\d+(-->|--&gt;|--&#0?62;)~', '', $html );
	}

	/** Forget everything cached in this request (records or settings changed). */
	public static function reset() {
		self::$states   = array();
		self::$seen     = array();
		self::$prepared = null;
		self::$contexts = array();
	}

	// ── Callbacks ────────────────────────────────────────────────────────────

	/** Gutenberg, classic editor, WPBakery, Toolset content templates and WooCommerce full description. */
	public static function filter_content( $content ) {
		if ( ! MTAL_Settings::enabled() ) {
			return $content;
		}
		if ( ! is_string( $content ) || '' === $content || self::skip_request() || doing_filter( 'get_the_excerpt' ) ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || ! MTAL_Settings::post_type_allowed( $post->post_type ) ) {
			return $content;
		}
		// Elementor pages are handled widget by widget. Processing the whole page again would count links twice.
		if ( MTAL_Elementor::is_built_with_elementor( $post->ID ) ) {
			return $content;
		}
		return self::process( $content, 'post', (int) $post->ID, (int) $post->ID, (string) get_permalink( $post ) );
	}

	/** WooCommerce short description. It shares the product counter with the full description. */
	public static function filter_wc_short_description( $text ) {
		if ( ! MTAL_Settings::enabled() ) {
			return $text;
		}
		if ( ! is_string( $text ) || '' === $text || self::skip_request() ) {
			return $text;
		}
		$post = get_post();
		if ( ! $post || ! MTAL_Settings::post_type_allowed( $post->post_type ) ) {
			return $text;
		}
		return self::process( $text, 'post', (int) $post->ID, (int) $post->ID, (string) get_permalink( $post ) );
	}

	/** Term description (category, tag, product_cat, product_tag, custom taxonomies). */
	public static function filter_term_description( $value, $term_id = 0, $taxonomy = '', $context = '' ) {
		if ( ! MTAL_Settings::enabled() ) {
			return $value;
		}
		if ( 'display' !== $context || ! is_string( $value ) || '' === $value || self::skip_request() ) {
			return $value;
		}
		if ( ! MTAL_Settings::taxonomy_allowed( $taxonomy ) ) {
			return $value;
		}
		return self::process( $value, 'term', (int) $term_id, 0, self::term_url( (int) $term_id, $taxonomy ) );
	}

	/** Toolset View output, only when it is not already inside the_content. */
	public static function filter_toolset_view( $output ) {
		if ( ! MTAL_Settings::enabled() ) {
			return $output;
		}
		if ( ! is_string( $output ) || '' === $output || self::skip_request() || doing_filter( 'the_content' ) ) {
			return $output;
		}
		$id = self::current_post_id();
		if ( ! $id ) {
			return $output;
		}
		$type = get_post_type( $id );
		if ( ! $type || ! MTAL_Settings::post_type_allowed( $type ) ) {
			return $output;
		}
		return self::process( $output, 'post', $id, $id, (string) get_permalink( $id ) );
	}

	/**
	 * WooCommerce prints the product category description with its own function that
	 * does not run the term filters, so the output of that action is captured instead.
	 */
	public static function wc_archive_start() {
		self::$wc_buffer_level = null;
		if ( ! MTAL_Settings::enabled() || self::skip_request() || ! function_exists( 'is_tax' ) || ! is_tax() ) {
			return;
		}
		$term = get_queried_object();
		if ( ! ( $term instanceof WP_Term ) || ! MTAL_Settings::taxonomy_allowed( $term->taxonomy ) ) {
			return;
		}
		ob_start();
		self::$wc_buffer_level = ob_get_level();
	}

	public static function wc_archive_end() {
		if ( null === self::$wc_buffer_level ) {
			return;
		}
		$level                 = self::$wc_buffer_level;
		self::$wc_buffer_level = null;
		if ( ob_get_level() !== $level ) {
			return; // Something else changed the buffers. Leave them alone.
		}
		$html = (string) ob_get_clean();
		$term = get_queried_object();
		if ( $term instanceof WP_Term && MTAL_Settings::enabled() ) {
			$html = self::process( $html, 'term', (int) $term->term_id, 0, self::term_url( (int) $term->term_id, $term->taxonomy ) );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Same markup the theme already printed.
	}

	// ── Shared pieces (also used by the Elementor integration) ──────────────

	/**
	 * Process one fragment.
	 *
	 * @param string $html    Fragment.
	 * @param string $type    post or term.
	 * @param int    $id      Post or term ID, the key of the shared counter state.
	 * @param int    $post_id Post ID for the "only on these posts" rule, 0 for terms.
	 * @param string $url     Permalink of the page being rendered, to avoid self links.
	 */
	public static function process( $html, $type, $id, $post_id, $url ) {
		if ( 'post' === $type && MTAL_Settings::post_excluded( $id ) ) {
			return $html;
		}
		if ( 'term' === $type && MTAL_Settings::term_excluded( $id ) ) {
			return $html;
		}
		if ( ! MTAL_Settings::front_page() && function_exists( 'is_front_page' ) && is_front_page() ) {
			return $html;
		}
		if ( isset( self::$seen[ md5( $html ) ] ) || false !== strpos( $html, '<!--ail-s:' ) ) {
			return $html;
		}
		$prep = self::prepared();
		if ( empty( $prep['cands'] ) ) {
			return $html;
		}
		$key = $type . ':' . (int) $id;
		$ctx = array(
			'post_id'     => $post_id,
			'current_url' => $url,
			'home'        => home_url(),
			'opts'        => MTAL_Settings::run_opts(),
		);
		if ( self::$buffering ) {
			if ( ! isset( self::$contexts[ $key ] ) ) {
				self::$contexts[ $key ] = $ctx;
			}
			$n = ++self::$seq;
			return '<!--ail-s:' . $key . ':' . $n . '-->' . $html . '<!--ail-e:' . $key . ':' . $n . '-->';
		}
		if ( ! isset( self::$states[ $key ] ) ) {
			self::$states[ $key ] = MTAL_Processor::new_state();
		}
		$out                       = MTAL_Processor::run( $html, $prep, $ctx, self::$states[ $key ] );
		self::$seen[ md5( $out ) ] = true;
		return $out;
	}

	public static function current_post_id() {
		$id = (int) get_the_ID();
		return $id ? $id : (int) get_queried_object_id();
	}

	/** Not for admin screens, the REST API, or anything that may save the text. */
	public static function skip_request() {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		return is_admin() && ! wp_doing_ajax();
	}

	private static function term_url( $term_id, $taxonomy ) {
		$link = get_term_link( $term_id, $taxonomy );
		return is_wp_error( $link ) ? '' : (string) $link;
	}

	private static function prepared() {
		if ( null === self::$prepared ) {
			global $wpdb;
			$table          = $wpdb->prefix . 'mtal_keywords';
			$rows           = $wpdb->get_results( "SELECT id, keywords, target_url, max_links, para_max, para_mode, post_ids FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
			self::$prepared = MTAL_Processor::prepare( is_array( $rows ) ? $rows : array(), MTAL_Settings::zwnj_as_space() );
		}
		return self::$prepared;
	}
}
