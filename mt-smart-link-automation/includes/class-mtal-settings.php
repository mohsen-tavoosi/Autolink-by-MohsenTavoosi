<?php
/**
 * Plugin settings (options with the mtal_ prefix).
 *
 * @package MT_Smart_Link_Automation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MTAL_Settings {

	/** R17: global on/off switch, without deactivating the plugin. */
	public static function enabled() {
		return (bool) get_option( 'mtal_enabled', 1 );
	}

	/** Treat a half-space (ZWNJ) in the page text and in keywords as a normal space. On by default. */
	public static function zwnj_as_space() {
		return (bool) get_option( 'mtal_zwnj_as_space', 1 );
	}

	/** Public post types as slug => label. */
	public static function public_post_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $slug => $obj ) {
			$out[ $slug ] = $obj->labels->name;
		}
		return $out;
	}

	/** Public taxonomies as slug => label. */
	public static function public_taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $slug => $obj ) {
			$out[ $slug ] = $obj->labels->name;
		}
		return $out;
	}

	/**
	 * R18: post types that get links. Never saved yet means every public type,
	 * which is how the plugin behaved before this setting existed.
	 */
	public static function post_types() {
		$saved = get_option( 'mtal_post_types', false );
		if ( ! is_array( $saved ) ) {
			return array_keys( self::public_post_types() );
		}
		return array_values( array_map( 'sanitize_key', $saved ) );
	}

	/** R18: taxonomies whose term descriptions get links. Off until chosen. */
	public static function taxonomies() {
		$saved = get_option( 'mtal_taxonomies', array() );
		return is_array( $saved ) ? array_values( array_map( 'sanitize_key', $saved ) ) : array();
	}

	public static function post_type_allowed( $post_type ) {
		return in_array( $post_type, self::post_types(), true );
	}

	public static function taxonomy_allowed( $taxonomy ) {
		return in_array( $taxonomy, self::taxonomies(), true );
	}

	/** Smart link diversity: each record links its keywords in their saved order. On by default. */
	public static function diversity() {
		return (bool) get_option( 'mtal_diversity', 1 );
	}

	/** A long phrase that reached its limit frees its spot for shorter keywords. Off by default. */
	public static function unlock_capped() {
		return (bool) get_option( 'mtal_unlock_capped', 0 );
	}

	/** A shorter phrase with no link yet on the page may link once inside a long phrase that has no link. Off by default. */
	public static function short_inside() {
		return (bool) get_option( 'mtal_short_inside', 0 );
	}

	/** Add links on the front page. On by default. */
	public static function front_page() {
		return (bool) get_option( 'mtal_front_page', 1 );
	}

	/** Post IDs (any post type) that never get links. */
	public static function exclude_posts() {
		return self::id_list( get_option( 'mtal_exclude_posts', array() ) );
	}

	/** Term IDs (any taxonomy) whose description never gets links. */
	public static function exclude_terms() {
		return self::id_list( get_option( 'mtal_exclude_terms', array() ) );
	}

	public static function post_excluded( $post_id ) {
		return in_array( (int) $post_id, self::exclude_posts(), true );
	}

	public static function term_excluded( $term_id ) {
		return in_array( (int) $term_id, self::exclude_terms(), true );
	}

	/** Options passed to the engine. */
	public static function run_opts() {
		return array(
			'diversity'     => self::diversity(),
			'unlock_capped' => self::unlock_capped(),
			'short_inside'  => self::short_inside(),
		);
	}

	/** Positive unique integers from an array or a comma list. */
	public static function id_list( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,،]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY );
		}
		$out = array();
		foreach ( (array) $raw as $v ) {
			$v = absint( $v );
			if ( $v && ! in_array( $v, $out, true ) ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	public static function delete_on_uninstall() {
		return (bool) get_option( 'mtal_delete_on_uninstall', 0 );
	}

	/** Keep only values that exist in $allowed. */
	public static function whitelist( $values, array $allowed ) {
		$out = array();
		foreach ( (array) $values as $v ) {
			$v = sanitize_key( (string) $v );
			if ( in_array( $v, $allowed, true ) && ! in_array( $v, $out, true ) ) {
				$out[] = $v;
			}
		}
		return $out;
	}
}
