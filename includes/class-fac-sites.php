<?php
/**
 * The sites table: one row per site that has ever reported, holding its
 * latest report and its current problems. Times are Unix timestamps.
 *
 * `problems` is a JSON map of rule key => {
 *   title, detail  what's wrong, from FAC_Rules (or the silence check)
 *   since          when it was first seen (resets once it's resolved)
 *   alerted_at     when Slack was last told about it (0 = not yet)
 *   grace          seconds it must last before the first alert
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAC_Sites {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'fac_sites';
	}

	/** Safe to re-run: dbDelta() only adds what's missing. */
	public static function install() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				site VARCHAR(191) NOT NULL,
				home_url VARCHAR(255) NOT NULL DEFAULT '',
				environment VARCHAR(20) NOT NULL DEFAULT '',
				plugin_version VARCHAR(20) NOT NULL DEFAULT '',
				first_seen_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
				last_report_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
				muted TINYINT UNSIGNED NOT NULL DEFAULT 0,
				report LONGTEXT NULL,
				problems LONGTEXT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY site (site)
			) {$charset};"
		);
	}

	/**
	 * @param string $site Host, as FAC_Collector::site_key() returns it.
	 * @return array|null
	 */
	public static function get( $site ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE site = %s', $site ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	/** @return array|null */
	public static function get_by_id( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	/** @return array[] Every site, by host. */
	public static function all() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY site ASC', ARRAY_A );
		return array_map( array( __CLASS__, 'decode' ), $rows ? $rows : array() );
	}

	/**
	 * Saves a report as the site's latest, adding the site if it's new.
	 *
	 * @param string $site
	 * @param array  $report Decoded report.
	 * @param int    $now
	 * @return int Row id.
	 */
	public static function save_report( $site, $report, $now ) {
		global $wpdb;
		$fields = array(
			'home_url'       => esc_url_raw( self::field( $report, 'home_url' ) ),
			'environment'    => substr( sanitize_key( self::field( $report, 'environment' ) ), 0, 20 ),
			'plugin_version' => substr( preg_replace( '/[^0-9A-Za-z.\-+]/', '', self::field( $report, 'plugin_version' ) ), 0, 20 ),
			'last_report_at' => $now,
			'report'         => wp_json_encode( $report ),
		);

		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE site = %s', $site ) );
		if ( $id ) {
			$wpdb->update( self::table(), $fields, array( 'id' => $id ) );
			return $id;
		}
		$fields['site']          = $site;
		$fields['first_seen_at'] = $now;
		$fields['problems']      = '{}';
		$wpdb->insert( self::table(), $fields );
		return (int) $wpdb->insert_id;
	}

	public static function set_problems( $id, $problems ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'problems' => wp_json_encode( (object) $problems ) ), array( 'id' => (int) $id ) );
	}

	public static function set_muted( $id, $muted ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'muted' => $muted ? 1 : 0 ), array( 'id' => (int) $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/** A row with its JSON columns decoded and numbers cast. */
	private static function decode( $row ) {
		foreach ( array( 'report', 'problems' ) as $col ) {
			$value       = isset( $row[ $col ] ) ? json_decode( (string) $row[ $col ], true ) : null;
			$row[ $col ] = is_array( $value ) ? $value : array();
		}
		foreach ( array( 'id', 'first_seen_at', 'last_report_at', 'muted' ) as $col ) {
			$row[ $col ] = (int) $row[ $col ];
		}
		return $row;
	}

	private static function field( $report, $key ) {
		return isset( $report[ $key ] ) && is_scalar( $report[ $key ] ) ? (string) $report[ $key ] : '';
	}
}
