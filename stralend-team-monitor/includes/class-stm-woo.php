<?php
/**
 * WooCommerce order counts, read directly from the shop's own database.
 *
 * Supports both order storages: HPOS (wp_wc_orders) and legacy post-based
 * (wp_posts, post_type shop_order). Only "real" orders count — processing,
 * completed and on-hold; cancelled/refunded/failed/pending are excluded.
 * Everything degrades silently when WooCommerce is not installed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STM_Woo {

	const STATUSES = array( 'wc-processing', 'wc-completed', 'wc-on-hold' );

	/** Which storage does this shop use? 'hpos' | 'legacy' | '' (no WooCommerce). */
	public static function storage() {
		static $storage = null;
		if ( null !== $storage ) {
			return $storage;
		}
		global $wpdb;
		$hpos = $wpdb->prefix . 'wc_orders';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos ) ) === $hpos ) {
			$storage = 'hpos';
		} elseif ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order' LIMIT 1" ) > 0 ) {
			$storage = 'legacy';
		} else {
			$storage = '';
		}
		return $storage;
	}

	public static function available() {
		return '' !== self::storage();
	}

	/**
	 * Orders per day in [from, to]. Returns date (Y-m-d) => count.
	 */
	public static function orders_per_day( $from, $to ) {
		global $wpdb;
		$storage = self::storage();
		if ( '' === $storage ) {
			return array();
		}
		$in = "'" . implode( "','", array_map( 'esc_sql', self::STATUSES ) ) . "'";

		if ( 'hpos' === $storage ) {
			$table = $wpdb->prefix . 'wc_orders';
			$sql   = "SELECT DATE(date_created_gmt) d, COUNT(*) c FROM {$table}
				WHERE type = 'shop_order' AND status IN ({$in})
				AND DATE(date_created_gmt) BETWEEN %s AND %s GROUP BY d";
		} else {
			$sql = "SELECT DATE(post_date) d, COUNT(*) c FROM {$wpdb->posts}
				WHERE post_type = 'shop_order' AND post_status IN ({$in})
				AND DATE(post_date) BETWEEN %s AND %s GROUP BY d";
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array( $from, $to ) ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ $r['d'] ] = (int) $r['c'];
		}
		return $out;
	}

	/** Total orders in [from, to]. */
	public static function count( $from, $to ) {
		return array_sum( self::orders_per_day( $from, $to ) );
	}
}
