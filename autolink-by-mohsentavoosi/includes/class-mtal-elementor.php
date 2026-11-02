<?php
/**
 * Elementor integration.
 *
 * Elementor renders widgets itself, so each widget's HTML is processed on
 * elementor/widget/render_content (priority 99). All widgets of one page share one counter (R15).
 *
 * @package Autolink_By_MohsenTavoosi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MTAL_Elementor {

	/** Widgets that print the post content. the_content already handles them. */
	private static $skip_widgets = array( 'theme-post-content', 'woocommerce-product-content' );

	private static $built = array();

	public static function init() {
		if ( ! MTAL_Settings::enabled() ) {
			return;
		}
		add_filter( 'elementor/widget/render_content', array( __CLASS__, 'filter_widget_content' ), 99, 2 );
	}

	public static function filter_widget_content( $content, $widget = null ) {
		if ( ! MTAL_Settings::enabled() ) {
			return $content;
		}
		if ( ! is_string( $content ) || '' === $content || MTAL_Hooks::skip_request() || self::is_editor() ) {
			return $content;
		}
		if ( is_object( $widget ) && method_exists( $widget, 'get_name' ) && in_array( $widget->get_name(), self::$skip_widgets, true ) ) {
			return $content;
		}
		$id = MTAL_Hooks::current_post_id();
		if ( ! $id ) {
			return $content;
		}
		$type = get_post_type( $id );
		if ( ! $type || ! MTAL_Settings::post_type_allowed( $type ) ) {
			return $content;
		}
		return MTAL_Hooks::process( $content, 'post', $id, $id, (string) get_permalink( $id ) );
	}

	/** True when Elementor builds this post, so its widgets do the work instead of the_content. */
	public static function is_built_with_elementor( $post_id ) {
		if ( isset( self::$built[ $post_id ] ) ) {
			return self::$built[ $post_id ];
		}
		$built = false;
		if ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				$doc   = \Elementor\Plugin::$instance->documents->get( $post_id );
				$built = ( $doc && method_exists( $doc, 'is_built_with_elementor' ) && $doc->is_built_with_elementor() );
			} catch ( \Throwable $e ) {
				$built = false;
			}
		}
		self::$built[ $post_id ] = $built;
		return $built;
	}

	/** Never add links inside the Elementor editor or its preview frame. */
	private static function is_editor() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		try {
			$p = \Elementor\Plugin::$instance;
			return ( isset( $p->editor ) && $p->editor->is_edit_mode() ) || ( isset( $p->preview ) && $p->preview->is_preview_mode() );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
