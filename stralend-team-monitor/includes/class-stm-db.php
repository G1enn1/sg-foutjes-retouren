<?php
/**
 * Data access layer: one table holding every normalized interaction
 * (email or call), from which the dashboard aggregates on the fly.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STM_DB {

	/** Fully-qualified table name. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'stm_interactions';
	}

	/**
	 * CREATE TABLE statement for dbDelta (run on activation / db upgrade).
	 */
	public static function create_table() {
		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		// NOTE: dbDelta is whitespace/format sensitive — keep two spaces after PRIMARY KEY.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_ts datetime NOT NULL,
			event_date date NOT NULL,
			employee_id varchar(64) NOT NULL,
			channel varchar(16) NOT NULL,
			direction varchar(16) NOT NULL DEFAULT 'inbound',
			duration_seconds int(10) unsigned NOT NULL DEFAULT 0,
			category varchar(64) NULL,
			ticket_id varchar(64) NULL,
			is_escalation tinyint(1) NOT NULL DEFAULT 0,
			is_fcr tinyint(1) NULL,
			reopened tinyint(1) NOT NULL DEFAULT 0,
			csat decimal(2,1) NULL,
			source varchar(32) NOT NULL,
			dedup_key char(40) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedup_key (dedup_key),
			KEY emp_date (employee_id, event_date),
			KEY event_date (event_date)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert one interaction, ignoring duplicates (same dedup_key).
	 *
	 * @param array $row Associative array of column => value.
	 * @return int 1 if inserted, 0 if it was a duplicate/no-op.
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$table = self::table();

		$data = array(
			'event_ts'         => $row['event_ts'],
			'event_date'       => substr( $row['event_ts'], 0, 10 ),
			'employee_id'      => $row['employee_id'],
			'channel'          => $row['channel'],
			'direction'        => isset( $row['direction'] ) ? $row['direction'] : 'inbound',
			'duration_seconds' => isset( $row['duration_seconds'] ) ? (int) $row['duration_seconds'] : 0,
			'category'         => isset( $row['category'] ) ? $row['category'] : null,
			'ticket_id'        => isset( $row['ticket_id'] ) ? $row['ticket_id'] : null,
			'is_escalation'    => ! empty( $row['is_escalation'] ) ? 1 : 0,
			'is_fcr'           => isset( $row['is_fcr'] ) ? ( $row['is_fcr'] ? 1 : 0 ) : null,
			'reopened'         => ! empty( $row['reopened'] ) ? 1 : 0,
			'csat'             => isset( $row['csat'] ) ? $row['csat'] : null,
			'source'           => $row['source'],
			'dedup_key'        => self::dedup_key( $row ),
			'created_at'       => current_time( 'mysql' ),
		);

		// INSERT IGNORE so duplicate dedup_key silently no-ops.
		$columns      = implode( ', ', array_map( array( __CLASS__, 'ident' ), array_keys( $data ) ) );
		$placeholders = implode( ', ', array_fill( 0, count( $data ), '%s' ) );
		$sql          = "INSERT IGNORE INTO {$table} ({$columns}) VALUES ({$placeholders})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$prepared = $wpdb->prepare( $sql, array_values( $data ) );
		$wpdb->query( $prepared );

		return (int) $wpdb->rows_affected;
	}

	/** Backtick-quote an identifier (column names are code-controlled, not user input). */
	public static function ident( $name ) {
		return '`' . str_replace( '`', '', $name ) . '`';
	}

	/**
	 * Deterministic dedup key so re-imports / duplicate webhook deliveries do not
	 * double count. For calls we key on (ts, employee, duration); for emails on
	 * the HubSpot object id when present, else (ts, employee, subjecthash).
	 */
	public static function dedup_key( array $row ) {
		if ( ! empty( $row['dedup_seed'] ) ) {
			$seed = $row['dedup_seed'];
		} else {
			$seed = implode( '|', array(
				$row['source'],
				$row['channel'],
				$row['employee_id'],
				$row['event_ts'],
				isset( $row['duration_seconds'] ) ? (int) $row['duration_seconds'] : '',
				isset( $row['direction'] ) ? $row['direction'] : '',
			) );
		}
		return sha1( $seed );
	}

	/**
	 * Fetch interactions in a date range (inclusive).
	 *
	 * @return array[] Rows as associative arrays.
	 */
	public static function fetch( $start_date, $end_date ) {
		global $wpdb;
		$table = self::table();
		$sql   = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE event_date >= %s AND event_date <= %s ORDER BY event_ts ASC",
			$start_date,
			$end_date
		);
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/** Total row count (for the settings/status screen). */
	public static function count() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public static function drop_table() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
