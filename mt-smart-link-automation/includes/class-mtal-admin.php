<?php
/**
 * Admin screen: settings, records, import and export.
 *
 * @package MT_Smart_Link_Automation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MTAL_Admin {

	const MAX_KEYWORDS    = 20;
	const MAX_IMPORT_SIZE = 1048576; // 1 MB.

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_mtal_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_mtal_save_keyword', array( __CLASS__, 'handle_save_keyword' ) );
		add_action( 'admin_post_mtal_delete_keyword', array( __CLASS__, 'handle_delete_keyword' ) );
		add_action( 'admin_post_mtal_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_mtal_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'wp_ajax_mtal_search', array( __CLASS__, 'ajax_search' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MTAL_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	public static function row_meta( $links, $file ) {
		if ( plugin_basename( MTAL_PLUGIN_FILE ) !== $file ) {
			return $links;
		}
		$links[] = '<a href="' . esc_url( MTAL_DOCS_WP_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'WordPress guide', 'mt-smart-link-automation' ) . '</a>';
		$links[] = '<a href="' . esc_url( MTAL_DOCS_GENERAL_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'General rules', 'mt-smart-link-automation' ) . '</a>';
		return $links;
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'mt-smart-link-automation' ) . '</a>' );
		return $links;
	}

	public static function add_menu() {
		add_menu_page(
			__( 'MT Smart Link Automation', 'mt-smart-link-automation' ),
			__( 'Internal Links', 'mt-smart-link-automation' ),
			'manage_options',
			'mt-smart-link-automation',
			array( __CLASS__, 'render_page' ),
			'dashicons-admin-links',
			80
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_mt-smart-link-automation' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ail-admin', MTAL_PLUGIN_URL . 'assets/css/admin.css', array(), MTAL_VERSION );
		wp_enqueue_script( 'ail-admin', MTAL_PLUGIN_URL . 'assets/js/admin.js', array(), MTAL_VERSION, true );
		wp_localize_script(
			'ail-admin',
			'mtalAdmin',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'mtal_search' ),
				'noResults' => __( 'Nothing found.', 'mt-smart-link-automation' ),
				'remove'    => __( 'Remove', 'mt-smart-link-automation' ),
			)
		);
	}

	// ── Form handlers ────────────────────────────────────────────────────────

	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'mt-smart-link-automation' ), 403 );
		}
		check_admin_referer( 'mtal_settings_save' );

		update_option( 'mtal_enabled', isset( $_POST['mtal_enabled'] ) ? 1 : 0 );
		update_option( 'mtal_zwnj_as_space', isset( $_POST['mtal_zwnj_as_space'] ) ? 1 : 0 );
		update_option( 'mtal_delete_on_uninstall', isset( $_POST['mtal_delete_on_uninstall'] ) ? 1 : 0, false );
		update_option( 'mtal_diversity', isset( $_POST['mtal_diversity'] ) ? 1 : 0 );
		update_option( 'mtal_unlock_capped', isset( $_POST['mtal_unlock_capped'] ) ? 1 : 0 );
		update_option( 'mtal_short_inside', isset( $_POST['mtal_short_inside'] ) ? 1 : 0 );
		update_option( 'mtal_front_page', isset( $_POST['mtal_front_page'] ) ? 1 : 0 );
		$excl                        = isset( $_POST['mtal_exclude'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['mtal_exclude'] ) ) : array();
		list( $ex_posts, $ex_terms ) = self::split_picks( $excl );
		update_option( 'mtal_exclude_posts', $ex_posts );
		update_option( 'mtal_exclude_terms', $ex_terms );

		$types = isset( $_POST['mtal_post_types'] ) ? (array) wp_unslash( $_POST['mtal_post_types'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Whitelisted below.
		$taxes = isset( $_POST['mtal_taxonomies'] ) ? (array) wp_unslash( $_POST['mtal_taxonomies'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Whitelisted below.
		update_option( 'mtal_post_types', MTAL_Settings::whitelist( $types, array_keys( MTAL_Settings::public_post_types() ) ) );
		update_option( 'mtal_taxonomies', MTAL_Settings::whitelist( $taxes, array_keys( MTAL_Settings::public_taxonomies() ) ) );

		MTAL_Hooks::reset();
		self::redirect( 'settings_saved' );
	}

	public static function handle_save_keyword() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'mt-smart-link-automation' ), 403 );
		}
		check_admin_referer( 'mtal_keyword_save' );

		global $wpdb;
		$table = $wpdb->prefix . 'mtal_keywords';
		$zwnj  = MTAL_Settings::zwnj_as_space();

		$id         = isset( $_POST['kw_id'] ) ? absint( $_POST['kw_id'] ) : 0;
		$raw_kws    = isset( $_POST['kw_keywords'] ) ? sanitize_textarea_field( wp_unslash( $_POST['kw_keywords'] ) ) : '';
		$target_url = isset( $_POST['kw_url'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['kw_url'] ) ) ) : '';
		$max_links  = isset( $_POST['kw_max'] ) ? max( 1, absint( $_POST['kw_max'] ) ) : 1;
		$para_max   = isset( $_POST['kw_para_max'] ) ? absint( $_POST['kw_para_max'] ) : 0;
		$para_mode  = ( isset( $_POST['kw_para_mode'] ) && 'heading' === $_POST['kw_para_mode'] ) ? 'heading' : 'p_br';
		$post_ids   = self::clean_post_ids( isset( $_POST['kw_post_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['kw_post_ids'] ) ) : '' );

		$keywords = self::parse_keywords( $raw_kws, $zwnj );

		if ( empty( $keywords ) || '' === $target_url ) {
			self::redirect( 'kw_error' );
		}
		if ( count( $keywords ) > self::MAX_KEYWORDS ) {
			self::redirect( 'kw_too_many' );
		}
		if ( ! MTAL_Processor::is_safe_url( $target_url ) ) {
			self::redirect( 'bad_url' ); // R21.
		}

		// R03: one URL, one record.
		if ( $id ) {
			$dup = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE target_url = %s AND id != %d LIMIT 1", $target_url, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		} else {
			$dup = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE target_url = %s LIMIT 1", $target_url ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		}
		if ( $dup ) {
			self::redirect( 'dup_url' );
		}

		// R04: one keyword, one record. With the half-space switch on, "a b" and "a‌b" are the same keyword.
		$owners = self::keyword_owners( $zwnj, $id );
		foreach ( $keywords as $kw ) {
			$n = MTAL_Processor::norm_text( $kw, $zwnj );
			if ( isset( $owners[ $n ] ) ) {
				self::redirect(
					'dup_keyword',
					array(
						'dup_kw'  => rawurlencode( $kw ),
						'dup_rec' => $owners[ $n ],
					)
				);
			}
		}

		$data   = array(
			'keywords'   => wp_json_encode( array_values( $keywords ), JSON_UNESCAPED_UNICODE ),
			'target_url' => $target_url,
			'max_links'  => $max_links,
			'para_max'   => $para_max,
			'para_mode'  => $para_mode,
			'post_ids'   => $post_ids,
		);
		$format = array( '%s', '%s', '%d', '%d', '%s', '%s' );

		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		} else {
			$wpdb->insert( $table, $data, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		}

		MTAL_Hooks::reset();
		self::redirect( 'kw_saved' );
	}

	public static function handle_delete_keyword() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'mt-smart-link-automation' ), 403 );
		}
		check_admin_referer( 'mtal_delete_keyword' );

		global $wpdb;
		$id = isset( $_POST['kw_id'] ) ? absint( $_POST['kw_id'] ) : 0;
		if ( $id ) {
			$wpdb->delete( $wpdb->prefix . 'mtal_keywords', array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
			MTAL_Hooks::reset();
		}
		self::redirect( 'kw_deleted' );
	}

	// ── Export ───────────────────────────────────────────────────────────────

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'mt-smart-link-automation' ), 403 );
		}
		check_admin_referer( 'mtal_export' );

		global $wpdb;
		$rows = $wpdb->get_results( "SELECT keywords, target_url, max_links, para_max, para_mode, post_ids FROM {$wpdb->prefix}mtal_keywords ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		$rows = is_array( $rows ) ? $rows : array();

		foreach ( $rows as &$row ) {
			$kws              = json_decode( $row['keywords'], true );
			$row['keywords']  = is_array( $kws ) ? $kws : array( $row['keywords'] );
			$row['max_links'] = (int) $row['max_links'];
			$row['para_max']  = (int) $row['para_max'];
		}
		unset( $row );

		$export = array(
			'plugin'      => 'mt-smart-link-automation',
			'version'     => MTAL_VERSION,
			'exported_at' => gmdate( 'Y-m-d H:i:s' ),
			'settings'    => array(
				'mtal_enabled'       => (int) MTAL_Settings::enabled(),
				'mtal_zwnj_as_space' => (int) MTAL_Settings::zwnj_as_space(),
				'mtal_post_types'    => MTAL_Settings::post_types(),
				'mtal_taxonomies'    => MTAL_Settings::taxonomies(),
				'mtal_diversity'     => (int) MTAL_Settings::diversity(),
				'mtal_unlock_capped' => (int) MTAL_Settings::unlock_capped(),
				'mtal_short_inside'  => (int) MTAL_Settings::short_inside(),
				'mtal_front_page'    => (int) MTAL_Settings::front_page(),
				'mtal_exclude_posts' => MTAL_Settings::exclude_posts(),
				'mtal_exclude_terms' => MTAL_Settings::exclude_terms(),
			),
			'records'     => $rows,
		);

		$json = wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mt-smart-link-automation-export-' . gmdate( 'Y-m-d-His' ) . '.json"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	// ── Import ───────────────────────────────────────────────────────────────

	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'mt-smart-link-automation' ), 403 );
		}
		check_admin_referer( 'mtal_import' );

		$tmp = isset( $_FILES['mtal_import_file']['tmp_name'] ) ? $_FILES['mtal_import_file']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			self::redirect( 'import_no_file' );
		}
		$size = isset( $_FILES['mtal_import_file']['size'] ) ? (int) $_FILES['mtal_import_file']['size'] : 0;
		if ( $size > self::MAX_IMPORT_SIZE ) {
			self::redirect( 'import_too_big' );
		}

		$data = json_decode( (string) file_get_contents( $tmp ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) || empty( $data['records'] ) || ! is_array( $data['records'] ) || ! in_array( isset( $data['plugin'] ) ? $data['plugin'] : '', array( 'mt-smart-link-automation', 'autolink-by-mohsentavoosi', 'auto-internal-links' ), true ) ) {
			self::redirect( 'import_invalid' );
		}

		$mode = ( isset( $_POST['mtal_import_mode'] ) && 'replace' === $_POST['mtal_import_mode'] ) ? 'replace' : 'merge';
		$zwnj = MTAL_Settings::zwnj_as_space();

		// Validate everything first, so a bad file can never wipe the table in Replace mode.
		$clean    = array();
		$skipped  = array(
			'invalid' => 0,
			'url'     => 0,
			'keyword' => 0,
		);
		$seen_url = array();
		$seen_kw  = array();
		if ( 'merge' === $mode ) {
			global $wpdb;
			$table = $wpdb->prefix . 'mtal_keywords';
			foreach ( (array) $wpdb->get_col( "SELECT target_url FROM {$table}" ) as $u ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
				$seen_url[ $u ] = true;
			}
			$seen_kw = self::keyword_owners( $zwnj, 0 );
		}
		foreach ( $data['records'] as $rec ) {
			if ( ! is_array( $rec ) ) {
				++$skipped['invalid'];
				continue;
			}
			$kws = isset( $rec['keywords'] ) ? $rec['keywords'] : array();
			if ( is_string( $kws ) ) {
				$d   = json_decode( $kws, true );
				$kws = is_array( $d ) ? $d : array( $kws );
			}
			$keywords = self::parse_keywords( implode( "\n", array_map( 'sanitize_text_field', array_filter( (array) $kws, 'is_scalar' ) ) ), $zwnj );
			$keywords = array_slice( $keywords, 0, self::MAX_KEYWORDS );
			$url      = isset( $rec['target_url'] ) && is_scalar( $rec['target_url'] ) ? trim( sanitize_text_field( (string) $rec['target_url'] ) ) : '';
			if ( empty( $keywords ) || '' === $url || ! MTAL_Processor::is_safe_url( $url ) ) {
				++$skipped['invalid'];
				continue;
			}
			if ( isset( $seen_url[ $url ] ) ) {
				++$skipped['url'];
				continue;
			}
			$norms = array();
			$dup   = false;
			foreach ( $keywords as $kw ) {
				$n = MTAL_Processor::norm_text( $kw, $zwnj );
				if ( isset( $seen_kw[ $n ] ) ) {
					$dup = true;
					break;
				}
				$norms[] = $n;
			}
			if ( $dup ) {
				++$skipped['keyword'];
				continue;
			}
			$seen_url[ $url ] = true;
			foreach ( $norms as $n ) {
				$seen_kw[ $n ] = true;
			}
			$clean[] = array(
				'keywords'   => wp_json_encode( array_values( $keywords ), JSON_UNESCAPED_UNICODE ),
				'target_url' => $url,
				'max_links'  => isset( $rec['max_links'] ) ? max( 1, absint( $rec['max_links'] ) ) : 1,
				'para_max'   => isset( $rec['para_max'] ) ? absint( $rec['para_max'] ) : 0,
				'para_mode'  => ( isset( $rec['para_mode'] ) && in_array( $rec['para_mode'], array( 'heading', 'headings' ), true ) ) ? 'heading' : 'p_br',
				'post_ids'   => self::clean_post_ids( isset( $rec['post_ids'] ) && is_scalar( $rec['post_ids'] ) ? sanitize_text_field( (string) $rec['post_ids'] ) : '' ),
			);
		}

		if ( 'replace' === $mode && empty( $clean ) ) {
			self::redirect( 'import_invalid' ); // Nothing valid: do not delete anything.
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mtal_keywords';
		if ( 'replace' === $mode ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		}
		$imported = 0;
		foreach ( $clean as $row ) {
			if ( $wpdb->insert( $table, $row, array( '%s', '%s', '%d', '%d', '%s', '%s' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
				++$imported;
			}
		}

		// Settings in the file.
		if ( ! empty( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$s = $data['settings'];
			// Files exported before 2.2.0 use the old prefix in their setting names.
			foreach ( array_keys( $s ) as $legacy_name ) {
				if ( 0 === strpos( (string) $legacy_name, 'ail_' ) ) {
					$s[ 'mtal_' . substr( $legacy_name, 4 ) ] = $s[ $legacy_name ];
				}
			}
			if ( isset( $s['mtal_enabled'] ) ) {
				update_option( 'mtal_enabled', (int) (bool) $s['mtal_enabled'] );
			}
			if ( isset( $s['mtal_zwnj_as_space'] ) ) {
				update_option( 'mtal_zwnj_as_space', (int) (bool) $s['mtal_zwnj_as_space'] );
			}
			foreach ( array( 'mtal_diversity', 'mtal_unlock_capped', 'mtal_short_inside', 'mtal_front_page' ) as $opt ) {
				if ( isset( $s[ $opt ] ) ) {
					update_option( $opt, (int) (bool) $s[ $opt ] );
				}
			}
			foreach ( array( 'mtal_exclude_posts', 'mtal_exclude_terms' ) as $opt ) {
				if ( isset( $s[ $opt ] ) && is_array( $s[ $opt ] ) ) {
					update_option( $opt, MTAL_Settings::id_list( $s[ $opt ] ) );
				}
			}
			if ( isset( $s['mtal_post_types'] ) && is_array( $s['mtal_post_types'] ) ) {
				update_option( 'mtal_post_types', MTAL_Settings::whitelist( $s['mtal_post_types'], array_keys( MTAL_Settings::public_post_types() ) ) );
			}
			if ( isset( $s['mtal_taxonomies'] ) && is_array( $s['mtal_taxonomies'] ) ) {
				update_option( 'mtal_taxonomies', MTAL_Settings::whitelist( $s['mtal_taxonomies'], array_keys( MTAL_Settings::public_taxonomies() ) ) );
			}
		}

		MTAL_Hooks::reset();
		self::redirect(
			'import_done',
			array(
				'import_count' => $imported,
				'sk_invalid'   => $skipped['invalid'],
				'sk_url'       => $skipped['url'],
				'sk_kw'        => $skipped['keyword'],
			)
		);
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	private static function redirect( $msg, array $extra = array() ) {
		wp_safe_redirect( self::page_url( array_merge( array( 'msg' => $msg ), $extra ) ) );
		exit;
	}

	private static function page_url( array $extra = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'mt-smart-link-automation' ), $extra ), admin_url( 'admin.php' ) );
	}

	private static function get_records() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mtal_keywords ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table of this plugin, table name built from $wpdb->prefix, values prepared.
		return $rows ? $rows : array();
	}

	/** Map of normalised keyword => record id, skipping one record (the one being edited). */
	private static function keyword_owners( $zwnj, $skip_id ) {
		$out = array();
		foreach ( self::get_records() as $rec ) {
			if ( $skip_id && (int) $rec->id === (int) $skip_id ) {
				continue;
			}
			foreach ( self::keywords_display( $rec->keywords ) as $kw ) {
				$out[ MTAL_Processor::norm_text( $kw, $zwnj ) ] = (int) $rec->id;
			}
		}
		return $out;
	}

	public static function clean_post_ids( $raw ) {
		$parts = preg_split( '/[\s,،]+/u', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$ids   = array_filter( array_map( 'absint', (array) $parts ) );
		return implode( ',', array_unique( $ids ) );
	}

	/** One keyword per line, trimmed, duplicates (after normalising) dropped. */
	private static function parse_keywords( $raw, $zwnj ) {
		$out  = array();
		$seen = array();
		foreach ( (array) preg_split( '/\r\n|\r|\n/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
			$line = trim( $line );
			$n    = MTAL_Processor::norm_text( $line, $zwnj );
			if ( '' === $n || isset( $seen[ $n ] ) ) {
				continue;
			}
			$seen[ $n ] = true;
			$out[]      = $line;
		}
		return $out;
	}

	private static function keywords_display( $json_string ) {
		$arr = json_decode( (string) $json_string, true );
		return is_array( $arr ) ? array_map( 'strval', $arr ) : array( (string) $json_string );
	}

	// ── Page ─────────────────────────────────────────────────────────────────

	/**
	 * Split picker values ("p:12", "t:5") into post IDs and term IDs.
	 *
	 * @param string[] $vals Picker values.
	 * @return array array( post IDs, term IDs ).
	 */
	private static function split_picks( array $vals ) {
		$posts = array();
		$terms = array();
		foreach ( $vals as $v ) {
			if ( preg_match( '/^([pt]):(\d+)$/', (string) $v, $m ) ) {
				if ( 'p' === $m[1] ) {
					$posts[] = (int) $m[2];
				} else {
					$terms[] = (int) $m[2];
				}
			}
		}
		return array( MTAL_Settings::id_list( $posts ), MTAL_Settings::id_list( $terms ) );
	}

	/**
	 * Search posts (enabled post types) and terms (enabled taxonomies) by title for the pickers.
	 * kind=posts limits the search to posts.
	 */
	public static function ajax_search() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'mtal_search', 'nonce' );
		$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : 'all';
		$out  = array();
		if ( '' === trim( $q ) ) {
			wp_send_json_success( $out );
		}
		$types = MTAL_Settings::post_types();
		if ( $types ) {
			$found = get_posts(
				array(
					's'                => $q,
					'post_type'        => $types,
					'post_status'      => array( 'publish', 'private', 'draft', 'future' ),
					'posts_per_page'   => 20,
					'orderby'          => 'relevance',
					'suppress_filters' => false,
				)
			);
			if ( ctype_digit( $q ) ) {
				$by_id = get_post( (int) $q );
				if ( $by_id && in_array( $by_id->post_type, $types, true ) ) {
					array_unshift( $found, $by_id );
				}
			}
			$seen = array();
			foreach ( $found as $p ) {
				if ( isset( $seen[ $p->ID ] ) ) {
					continue;
				}
				$seen[ $p->ID ] = true;
				$out[]          = array(
					'value' => 'p:' . $p->ID,
					'label' => self::pick_label( 'p', $p->ID ),
				);
			}
		}
		$taxes = MTAL_Settings::taxonomies();
		if ( 'all' === $kind && $taxes ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxes,
					'name__like' => $q,
					'number'     => 20,
					'hide_empty' => false,
				)
			);
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					$out[] = array(
						'value' => 't:' . $t->term_id,
						'label' => self::pick_label( 't', $t->term_id ),
					);
				}
			}
		}
		wp_send_json_success( $out );
	}

	/** Readable label for a picked item: title, type and ID. */
	private static function pick_label( $kind, $id ) {
		if ( 'p' === $kind ) {
			$p = get_post( $id );
			if ( ! $p ) {
				/* translators: %d: post ID */
				return sprintf( __( 'Deleted item #%d', 'mt-smart-link-automation' ), $id );
			}
			$obj   = get_post_type_object( $p->post_type );
			$title = '' !== $p->post_title ? $p->post_title : __( '(no title)', 'mt-smart-link-automation' );
			return sprintf( '%s — %s #%d', $title, $obj ? $obj->labels->singular_name : $p->post_type, $id );
		}
		$t = get_term( $id );
		if ( ! ( $t instanceof WP_Term ) ) {
			/* translators: %d: term ID */
			return sprintf( __( 'Deleted term #%d', 'mt-smart-link-automation' ), $id );
		}
		$tax = get_taxonomy( $t->taxonomy );
		return sprintf( '%s — %s #%d', $t->name, $tax ? $tax->labels->singular_name : $t->taxonomy, $id );
	}

	/**
	 * Print a search-and-pick box.
	 *
	 * @param string $name   Field name. In "list" mode values are posted as name[] ("p:12", "t:5"); in "csv" mode as one comma list of post IDs.
	 * @param string $kind   all or posts.
	 * @param array  $picked array of array( 'p'|'t', id ).
	 * @param string $mode   list or csv.
	 */
	private static function picker( $name, $kind, array $picked, $mode = 'list' ) {
		?>
		<div class="ail-picker" data-name="<?php echo esc_attr( $name ); ?>" data-kind="<?php echo esc_attr( $kind ); ?>" data-mode="<?php echo esc_attr( $mode ); ?>">
			<input type="search" class="regular-text ail-picker-search" placeholder="<?php echo esc_attr( 'posts' === $kind ? __( 'Search posts and pages by title or ID…', 'mt-smart-link-automation' ) : __( 'Search posts, pages and terms by title or ID…', 'mt-smart-link-automation' ) ); ?>" autocomplete="off">
			<ul class="ail-picker-results" hidden></ul>
			<ul class="ail-picker-chips">
				<?php foreach ( $picked as $it ) : ?>
					<li class="ail-chip" data-value="<?php echo esc_attr( $it[0] . ':' . $it[1] ); ?>">
						<span><?php echo esc_html( self::pick_label( $it[0], $it[1] ) ); ?></span>
						<button type="button" class="ail-chip-x" aria-label="<?php esc_attr_e( 'Remove', 'mt-smart-link-automation' ); ?>">×</button>
						<?php if ( 'list' === $mode ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $it[0] . ':' . $it[1] ); ?>">
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( 'csv' === $mode ) : ?>
				<input type="hidden" class="ail-picker-csv" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( implode( ',', wp_list_pluck( $picked, 1 ) ) ); ?>">
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of redirect messages and the edit target.
		$enabled  = MTAL_Settings::enabled();
		$zwnj     = MTAL_Settings::zwnj_as_space();
		$records  = self::get_records();
		$msg      = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		$edit_id  = isset( $_GET['edit_id'] ) ? absint( $_GET['edit_id'] ) : 0;
		$edit_rec = null;
		foreach ( $records as $r ) {
			if ( $edit_id && (int) $r->id === $edit_id ) {
				$edit_rec = $r;
				break;
			}
		}
		$show_form = isset( $_GET['add'] ) || $edit_rec;

		$edit_text = '';
		$edit_pmax = 0;
		$edit_mode = 'p_br';
		if ( $edit_rec ) {
			$edit_text = implode( "\n", self::keywords_display( $edit_rec->keywords ) );
			$edit_pmax = (int) $edit_rec->para_max;
			$edit_mode = ( 'heading' === $edit_rec->para_mode ) ? 'heading' : 'p_br';
		}
		$dup_kw    = isset( $_GET['dup_kw'] ) ? sanitize_text_field( wp_unslash( $_GET['dup_kw'] ) ) : '';
		$dup_rec   = isset( $_GET['dup_rec'] ) ? absint( $_GET['dup_rec'] ) : 0;
		$imp_count = isset( $_GET['import_count'] ) ? absint( $_GET['import_count'] ) : 0;
		$sk_inv    = isset( $_GET['sk_invalid'] ) ? absint( $_GET['sk_invalid'] ) : 0;
		$sk_url    = isset( $_GET['sk_url'] ) ? absint( $_GET['sk_url'] ) : 0;
		$sk_kw     = isset( $_GET['sk_kw'] ) ? absint( $_GET['sk_kw'] ) : 0;
		// phpcs:enable

		$saved_types = MTAL_Settings::post_types();
		$saved_taxes = MTAL_Settings::taxonomies();
		?>
		<div class="wrap ail-wrap">
			<h1>🔗 <?php esc_html_e( 'MT Smart Link Automation', 'mt-smart-link-automation' ); ?></h1>

			<?php
			$notices = array(
				'settings_saved' => array( 'success', __( 'Settings saved.', 'mt-smart-link-automation' ) ),
				'kw_saved'       => array( 'success', __( 'Record saved.', 'mt-smart-link-automation' ) ),
				'kw_deleted'     => array( 'success', __( 'Record deleted.', 'mt-smart-link-automation' ) ),
				'kw_error'       => array( 'error', __( 'Error: Keywords and URL cannot be empty.', 'mt-smart-link-automation' ) ),
				'kw_too_many'    => array( 'error', sprintf( /* translators: %d: maximum keywords per record */ __( 'Error: Maximum %d keywords per record.', 'mt-smart-link-automation' ), self::MAX_KEYWORDS ) ),
				'bad_url'        => array( 'error', __( 'Error: The link URL must start with http://, https:// or a single slash (for example /my-page).', 'mt-smart-link-automation' ) ),
				'dup_url'        => array( 'error', __( 'Error: This URL is already registered in another record. Each URL can only be used once.', 'mt-smart-link-automation' ) ),
				'import_no_file' => array( 'error', __( 'Error: No file selected for import.', 'mt-smart-link-automation' ) ),
				'import_too_big' => array( 'error', __( 'Error: The import file is larger than 1 MB.', 'mt-smart-link-automation' ) ),
				'import_invalid' => array( 'error', __( 'Error: Invalid import file. Please use a JSON file exported by this plugin. Nothing was changed.', 'mt-smart-link-automation' ) ),
			);
			if ( isset( $notices[ $msg ] ) ) {
				printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notices[ $msg ][0] ), esc_html( ( 'success' === $notices[ $msg ][0] ? '✓ ' : '' ) . $notices[ $msg ][1] ) );
			} elseif ( 'dup_keyword' === $msg ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . sprintf(
					/* translators: 1: keyword, 2: record number */
					esc_html__( 'Error: The keyword "%1$s" already exists in record #%2$d. Each keyword must be unique across all records.', 'mt-smart-link-automation' ),
					'<strong>' . esc_html( $dup_kw ) . '</strong>',
					(int) $dup_rec
				) . '</p></div>';
			} elseif ( 'import_done' === $msg ) {
				echo '<div class="notice notice-success is-dismissible"><p>✓ ' . sprintf(
					/* translators: 1: imported count, 2: invalid, 3: duplicate URL, 4: duplicate keyword */
					esc_html__( 'Import complete. %1$d records imported. Skipped: %2$d invalid, %3$d duplicate URL, %4$d duplicate keyword.', 'mt-smart-link-automation' ),
					(int) $imp_count,
					(int) $sk_inv,
					(int) $sk_url,
					(int) $sk_kw
				) . '</p></div>';
			}
			?>

			<div class="ail-card">
				<h2><?php esc_html_e( 'General Settings', 'mt-smart-link-automation' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mtal_save_settings">
					<?php wp_nonce_field( 'mtal_settings_save' ); ?>
					<table class="form-table">
						<tr>
							<th><?php esc_html_e( 'Plugin status', 'mt-smart-link-automation' ); ?></th>
							<td>
								<label><input type="checkbox" name="mtal_enabled" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Enabled', 'mt-smart-link-automation' ); ?></label>
								<p class="description"><?php esc_html_e( 'Turn off to remove every link from the pages without deactivating the plugin. Your records are kept.', 'mt-smart-link-automation' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Half-space (ZWNJ)', 'mt-smart-link-automation' ); ?></th>
							<td>
								<label><input type="checkbox" name="mtal_zwnj_as_space" value="1" <?php checked( $zwnj ); ?>> <?php esc_html_e( 'Treat half-space (ZWNJ) as a space', 'mt-smart-link-automation' ); ?></label>
								<p class="description"><?php esc_html_e( 'On: a keyword written with a space and the same words joined by a half-space are the same keyword, in your keywords and in the page text. You only need one version of each keyword. Turn it off for languages where a half-space and a space mean different things. A half-space at the edge of a word still stops a partial match.', 'mt-smart-link-automation' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Keyword order', 'mt-smart-link-automation' ); ?></th>
							<td>
								<label><input type="checkbox" name="mtal_diversity" value="1" <?php checked( MTAL_Settings::diversity() ); ?>> <?php esc_html_e( 'Smart link diversity order by keywords per link based on Mohsen Tavoosi tutorial', 'mt-smart-link-automation' ); ?></label>
								<a href="<?php echo esc_url( MTAL_DIVERSITY_INFO_URL ); ?>" target="_blank" rel="noopener" class="ail-info" title="<?php esc_attr_e( 'Read the tutorial', 'mt-smart-link-automation' ); ?>">ⓘ</a>
								<p class="description"><?php esc_html_e( 'On (recommended): inside each record, the first link on a page goes to keyword 1 (the first line), the next link to the first keyword that has no link on this page yet, and so on up to keyword 20. When every keyword found on the page has a link, it starts again from keyword 1. This spreads your links over all the keywords of a record instead of always linking the same words. Off: the order of keywords does not matter. The first match in the text gets the link, within the limits.', 'mt-smart-link-automation' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Long phrases at their limit', 'mt-smart-link-automation' ); ?></th>
							<td>
								<label><input type="checkbox" name="mtal_unlock_capped" value="1" <?php checked( MTAL_Settings::unlock_capped() ); ?>> <?php esc_html_e( 'Unlock longer phrases after their page limit: let shorter phrases inside them get linked', 'mt-smart-link-automation' ); ?></label>
								<p class="description"><?php esc_html_e( 'Off (default): a longer keyword always keeps its place, even when it already reached its limit and gets no link there. A shorter keyword is never linked inside it. Example: "buy automatic door" reached its limit, so in the next "buy automatic door" the words "automatic door" are not linked either. On: once the longer keyword reached its limit, the shorter keyword inside it can be linked, within its own limits.', 'mt-smart-link-automation' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Short phrase without a link', 'mt-smart-link-automation' ); ?></th>
							<td>
								<label><input type="checkbox" name="mtal_short_inside" value="1" <?php checked( MTAL_Settings::short_inside() ); ?>> <?php esc_html_e( 'Link a shorter phrase inside a longer matched phrase if the shorter phrase has not been linked yet on this page', 'mt-smart-link-automation' ); ?></label>
								<p class="description"><?php esc_html_e( 'Off (default): a shorter keyword gets a link only where it appears on its own. On: if a shorter keyword has no link anywhere on the page, it is linked once inside a longer keyword that did not get a link there (a link can never sit inside another link). After that one link the longer keyword keeps its place again.', 'mt-smart-link-automation' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Front page', 'mt-smart-link-automation' ); ?></th>
							<td>
								<label><input type="checkbox" name="mtal_front_page" value="1" <?php checked( MTAL_Settings::front_page() ); ?>> <?php esc_html_e( 'Add links on the front page', 'mt-smart-link-automation' ); ?></label>
								<p class="description"><?php esc_html_e( 'Off: the home page of the site never gets automatic links, whatever its post type.', 'mt-smart-link-automation' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Excluded pages', 'mt-smart-link-automation' ); ?></th>
							<td>
								<?php
								$picked = array();
								foreach ( MTAL_Settings::exclude_posts() as $pid ) {
									$picked[] = array( 'p', $pid );
								}
								foreach ( MTAL_Settings::exclude_terms() as $tid ) {
									$picked[] = array( 't', $tid );
								}
								self::picker( 'mtal_exclude', 'all', $picked, 'list' );
								?>
								<p class="description"><?php esc_html_e( 'These never get automatic links. Search by title or ID among the post types and taxonomies ticked below (posts, pages, products, categories, tags and others) and pick from the list. Save the settings after ticking a new post type or taxonomy to search in it.', 'mt-smart-link-automation' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Post types', 'mt-smart-link-automation' ); ?></th>
							<td>
								<?php foreach ( MTAL_Settings::public_post_types() as $slug => $label ) : ?>
									<label style="display:inline-block;margin:0 16px 6px 0;"><input type="checkbox" name="mtal_post_types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $saved_types, true ) ); ?>> <?php echo esc_html( $label ); ?> <code><?php echo esc_html( $slug ); ?></code></label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Links are added to the content of these post types. For WooCommerce products this covers the full and the short description, and both share one counter.', 'mt-smart-link-automation' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Taxonomies', 'mt-smart-link-automation' ); ?></th>
							<td>
								<?php foreach ( MTAL_Settings::public_taxonomies() as $slug => $label ) : ?>
									<label style="display:inline-block;margin:0 16px 6px 0;"><input type="checkbox" name="mtal_taxonomies[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $saved_taxes, true ) ); ?>> <?php echo esc_html( $label ); ?> <code><?php echo esc_html( $slug ); ?></code></label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e( 'Links are added to the description of terms in these taxonomies (category, subcategory, tag, product category, product tag and custom ones).', 'mt-smart-link-automation' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'On uninstall', 'mt-smart-link-automation' ); ?></th>
							<td>
								<label><input type="checkbox" name="mtal_delete_on_uninstall" value="1" <?php checked( MTAL_Settings::delete_on_uninstall() ); ?>> <?php esc_html_e( 'Delete all plugin data when the plugin is deleted', 'mt-smart-link-automation' ); ?></label>
								<p class="description"><strong><?php esc_html_e( 'Warning:', 'mt-smart-link-automation' ); ?></strong> <?php esc_html_e( 'This removes every record and setting for good. Export a backup first. Off by default.', 'mt-smart-link-automation' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save Settings', 'mt-smart-link-automation' ) ); ?>
				</form>
			</div>

			<div class="ail-card">
				<h2><?php esc_html_e( 'Keywords & Links', 'mt-smart-link-automation' ); ?></h2>

				<?php if ( ! $show_form ) : ?>
					<a href="<?php echo esc_url( self::page_url( array( 'add' => '1' ) ) ); ?>" class="button button-primary" style="margin-bottom:16px">+ <?php esc_html_e( 'Add New Record', 'mt-smart-link-automation' ); ?></a>
				<?php endif; ?>

				<?php if ( $show_form ) : ?>
				<div class="ail-form-box" style="margin:16px 0;">
					<h3><?php echo $edit_rec ? esc_html__( 'Edit Record', 'mt-smart-link-automation' ) : esc_html__( 'Add New Record', 'mt-smart-link-automation' ); ?></h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="mtal_save_keyword">
						<input type="hidden" name="kw_id" value="<?php echo esc_attr( $edit_rec ? $edit_rec->id : 0 ); ?>">
						<?php wp_nonce_field( 'mtal_keyword_save' ); ?>
						<table class="form-table">
							<tr>
								<th><label for="kw_keywords"><?php esc_html_e( 'Keywords', 'mt-smart-link-automation' ); ?></label></th>
								<td>
									<textarea name="kw_keywords" id="kw_keywords" rows="5" class="regular-text" placeholder="<?php echo esc_attr( sprintf( /* translators: %d: maximum keywords */ __( 'One keyword per line (max %d)', 'mt-smart-link-automation' ), self::MAX_KEYWORDS ) ); ?>" required><?php echo esc_textarea( $edit_text ); ?></textarea>
									<p class="description"><?php echo esc_html( sprintf( /* translators: %d: maximum keywords */ __( 'One keyword per line. Maximum %d keywords per record. All of them link to the same URL.', 'mt-smart-link-automation' ), self::MAX_KEYWORDS ) ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="kw_url"><?php esc_html_e( 'Link URL', 'mt-smart-link-automation' ); ?></label></th>
								<td><input type="text" name="kw_url" id="kw_url" class="regular-text" value="<?php echo esc_attr( $edit_rec ? $edit_rec->target_url : '' ); ?>" placeholder="/my-page or https://example.com/page/" required></td>
							</tr>
							<tr>
								<th><label for="kw_max"><?php esc_html_e( 'Max links per page', 'mt-smart-link-automation' ); ?></label></th>
								<td>
									<input type="number" name="kw_max" id="kw_max" min="1" max="100" style="width:70px" value="<?php echo esc_attr( $edit_rec ? $edit_rec->max_links : 1 ); ?>">
									<p class="description"><?php esc_html_e( 'How many times this keyword set can be linked on a single page.', 'mt-smart-link-automation' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><label for="kw_para_max"><?php esc_html_e( 'Max links per paragraph', 'mt-smart-link-automation' ); ?></label></th>
								<td>
									<input type="number" name="kw_para_max" id="kw_para_max" min="0" max="100" style="width:70px" value="<?php echo esc_attr( $edit_pmax ); ?>">
									<p class="description"><strong>0</strong> = <?php esc_html_e( 'no paragraph limit.', 'mt-smart-link-automation' ); ?></p>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Paragraph detection mode', 'mt-smart-link-automation' ); ?></th>
								<td>
									<fieldset>
										<label style="display:block;margin-bottom:8px;">
											<input type="radio" name="kw_para_mode" value="p_br" <?php checked( $edit_mode, 'p_br' ); ?>>
											<strong><?php esc_html_e( 'Take <p> and <br> tags as paragraph', 'mt-smart-link-automation' ); ?></strong>
										</label>
										<label style="display:block;">
											<input type="radio" name="kw_para_mode" value="heading" <?php checked( $edit_mode, 'heading' ); ?>>
											<strong><?php esc_html_e( 'Take texts between headings as paragraph', 'mt-smart-link-automation' ); ?></strong>
											<br><span class="description" style="margin-left:24px;"><?php esc_html_e( 'All content between two headings (h1 to h6) is one paragraph. The text before the first heading and after the last heading are each one paragraph.', 'mt-smart-link-automation' ); ?></span>
										</label>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Only on these pages', 'mt-smart-link-automation' ); ?></th>
								<td>
									<?php
									$rec_picked = array();
									foreach ( MTAL_Settings::id_list( $edit_rec ? (string) $edit_rec->post_ids : '' ) as $pid ) {
										$rec_picked[] = array( 'p', $pid );
									}
									self::picker( 'kw_post_ids', 'posts', $rec_picked, 'csv' );
									?>
									<p class="description"><?php esc_html_e( 'Search by title or ID and pick. Empty = every page. Use it to test a record on one page first. A record limited to some pages is not used in term descriptions.', 'mt-smart-link-automation' ); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button( $edit_rec ? __( 'Save Changes', 'mt-smart-link-automation' ) : __( 'Add Record', 'mt-smart-link-automation' ), 'primary', 'submit', false ); ?>
						<a href="<?php echo esc_url( self::page_url() ); ?>" class="button" style="margin-left:8px"><?php esc_html_e( 'Cancel', 'mt-smart-link-automation' ); ?></a>
					</form>
				</div>
				<?php endif; ?>

				<table class="wp-list-table widefat fixed striped" style="margin-top:12px">
					<thead>
						<tr>
							<th style="width:6%">#</th>
							<th style="width:25%"><?php esc_html_e( 'Keywords', 'mt-smart-link-automation' ); ?></th>
							<th style="width:22%"><?php esc_html_e( 'Link URL', 'mt-smart-link-automation' ); ?></th>
							<th style="width:7%"><?php esc_html_e( 'Max/Page', 'mt-smart-link-automation' ); ?></th>
							<th style="width:8%"><?php esc_html_e( 'Max/Para', 'mt-smart-link-automation' ); ?></th>
							<th style="width:8%"><?php esc_html_e( 'Para Mode', 'mt-smart-link-automation' ); ?></th>
							<th style="width:10%"><?php esc_html_e( 'Post IDs', 'mt-smart-link-automation' ); ?></th>
							<th style="width:14%"><?php esc_html_e( 'Actions', 'mt-smart-link-automation' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $records ) ) : ?>
						<tr><td colspan="8" style="text-align:center;padding:20px;color:#999;"><?php esc_html_e( 'No records defined yet.', 'mt-smart-link-automation' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $records as $rec ) : ?>
						<tr>
							<td><?php echo (int) $rec->id; ?></td>
							<td>
								<?php foreach ( self::keywords_display( $rec->keywords ) as $k ) : ?>
									<code style="display:inline-block;margin:1px 2px;font-size:12px;background:#f0f0f1;padding:1px 6px;border-radius:3px;"><?php echo esc_html( $k ); ?></code>
								<?php endforeach; ?>
							</td>
							<td style="color:#2271b1;word-break:break-all;font-size:12px"><?php echo esc_html( $rec->target_url ); ?></td>
							<td><?php echo (int) $rec->max_links; ?></td>
							<td><?php echo (int) $rec->para_max; ?></td>
							<td style="font-size:11px"><?php echo 'heading' === $rec->para_mode ? esc_html__( 'Heading', 'mt-smart-link-automation' ) : 'P/BR'; ?></td>
							<td style="color:#555;font-size:12px"><?php echo '' !== (string) $rec->post_ids ? esc_html( $rec->post_ids ) : '<span style="color:#999">' . esc_html__( 'All', 'mt-smart-link-automation' ) . '</span>'; ?></td>
							<td>
								<a href="<?php echo esc_url( self::page_url( array( 'edit_id' => $rec->id ) ) ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'mt-smart-link-automation' ); ?></a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" class="ail-delete-form" data-confirm="<?php echo esc_attr__( 'Delete this record?', 'mt-smart-link-automation' ); ?>">
									<input type="hidden" name="action" value="mtal_delete_keyword">
									<input type="hidden" name="kw_id" value="<?php echo (int) $rec->id; ?>">
									<?php wp_nonce_field( 'mtal_delete_keyword' ); ?>
									<button type="submit" class="button button-small" style="color:#b32d2e"><?php esc_html_e( 'Delete', 'mt-smart-link-automation' ); ?></button>
								</form>
							</td>
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="ail-card">
				<h2><?php esc_html_e( 'Import / Export', 'mt-smart-link-automation' ); ?></h2>
				<div style="display:flex;gap:40px;flex-wrap:wrap;">
					<div style="flex:1;min-width:280px;">
						<h3 style="margin-top:0;"><?php esc_html_e( 'Export', 'mt-smart-link-automation' ); ?></h3>
						<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Download all records and settings as a JSON file. Use it as a backup before updating, or to move to another site.', 'mt-smart-link-automation' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mtal_export">
							<?php wp_nonce_field( 'mtal_export' ); ?>
							<button type="submit" class="button button-primary">⬇ <?php esc_html_e( 'Export All Records (JSON)', 'mt-smart-link-automation' ); ?></button>
							<?php if ( ! empty( $records ) ) : ?>
								<span style="margin-left:10px;color:#666;font-size:12px;"><?php echo esc_html( sprintf( /* translators: %d: number of records */ _n( '%d record', '%d records', count( $records ), 'mt-smart-link-automation' ), count( $records ) ) ); ?></span>
							<?php endif; ?>
						</form>
					</div>
					<div style="flex:1;min-width:280px;">
						<h3 style="margin-top:0;"><?php esc_html_e( 'Import', 'mt-smart-link-automation' ); ?></h3>
						<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Upload a JSON file exported by this plugin (up to 1 MB). Duplicate URLs and keywords are skipped and counted.', 'mt-smart-link-automation' ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="ail-import-form">
							<input type="hidden" name="action" value="mtal_import">
							<?php wp_nonce_field( 'mtal_import' ); ?>
							<div style="margin-bottom:12px;">
								<label style="display:block;margin-bottom:6px;"><input type="radio" name="mtal_import_mode" value="merge" checked> <strong><?php esc_html_e( 'Merge', 'mt-smart-link-automation' ); ?></strong> — <?php esc_html_e( 'add the imported records to the existing ones (skip duplicates)', 'mt-smart-link-automation' ); ?></label>
								<label style="display:block;"><input type="radio" name="mtal_import_mode" value="replace"> <strong><?php esc_html_e( 'Replace', 'mt-smart-link-automation' ); ?></strong> — <?php esc_html_e( 'delete all existing records first, then import', 'mt-smart-link-automation' ); ?></label>
							</div>
							<input type="file" name="mtal_import_file" accept=".json,application/json" style="margin-bottom:10px;" required>
							<br>
							<button type="submit" class="button" data-confirm="<?php echo esc_attr__( 'This will DELETE all existing records and replace them with the imported data. Continue?', 'mt-smart-link-automation' ); ?>">⬆ <?php esc_html_e( 'Import Records', 'mt-smart-link-automation' ); ?></button>
						</form>
					</div>
				</div>
			</div>

			<div class="ail-card">
				<h2><?php esc_html_e( 'How it works', 'mt-smart-link-automation' ); ?></h2>
				<ul style="list-style:disc;padding-left:20px;line-height:2">
					<li><?php esc_html_e( 'Keywords match whole words only, and upper or lower case does not matter.', 'mt-smart-link-automation' ); ?></li>
					<li><?php esc_html_e( 'With the half-space switch on, a space and a half-space between the words of a keyword are the same.', 'mt-smart-link-automation' ); ?></li>
					<li><?php esc_html_e( 'Longer keywords win over shorter ones, across all records.', 'mt-smart-link-automation' ); ?></li>
					<li><?php esc_html_e( 'An existing link whose text is exactly one of your keywords but points to another URL is corrected when the page is shown.', 'mt-smart-link-automation' ); ?></li>
					<li><?php esc_html_e( 'Links whose text is longer than the keyword, or unrelated to your keywords, are left alone.', 'mt-smart-link-automation' ); ?></li>
					<li><?php esc_html_e( 'A page never links to itself.', 'mt-smart-link-automation' ); ?></li>
					<li><?php esc_html_e( 'Each URL and each keyword can be used in one record only.', 'mt-smart-link-automation' ); ?></li>
					<li><?php esc_html_e( 'Links are added while the page is shown. Your posts and the database content are never changed.', 'mt-smart-link-automation' ); ?></li>
					<li><?php esc_html_e( 'If you use a page cache or a CDN, clear it after changing records.', 'mt-smart-link-automation' ); ?></li>
				</ul>
			</div>

			<p class="ail-credit">
				<?php esc_html_e( 'Made by', 'mt-smart-link-automation' ); ?>
				<a href="<?php echo esc_url( MTAL_AUTHOR_URL ); ?>" target="_blank" rel="noopener">Mohsen Tavoosi</a>
				&middot; <a href="<?php echo esc_url( MTAL_DOCS_WP_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'WordPress guide', 'mt-smart-link-automation' ); ?></a>
				&middot; <a href="<?php echo esc_url( MTAL_DOCS_GENERAL_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'General rules of automatic internal linking', 'mt-smart-link-automation' ); ?></a>
			</p>
		</div>
		<?php
	}
}
