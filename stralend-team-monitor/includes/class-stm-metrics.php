<?php
/**
 * Attribution helpers + per-employee-per-day aggregation and difficulty/
 * progression metrics. PHP port of the reference Python model.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STM_Metrics {

	/* ------------------------------------------------------------------ *
	 * Attribution: Voys "2xx/Naam" destination -> employee
	 * ------------------------------------------------------------------ */

	/**
	 * Internal extension token from a Voys field.
	 * "210/Sandra sip" -> "210" ; "213" -> "213" ; "+31502110420" -> "" (external).
	 */
	public static function leading_ext( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( false !== strpos( $value, '/' ) ) {
			$head = substr( $value, 0, strpos( $value, '/' ) );
			return preg_replace( '/\D/', '', $head );
		}
		$digits = preg_replace( '/\D/', '', $value );
		// Treat only short numbers (<=4 digits) as internal extensions.
		return ( '' !== $digits && strlen( $digits ) <= 4 ) ? $digits : '';
	}

	/** First-name token after the slash: "210/Sandra sip" -> "sandra". */
	public static function name_token( $value ) {
		$value = (string) $value;
		$pos   = strpos( $value, '/' );
		if ( false === $pos ) {
			return '';
		}
		$rest  = trim( substr( $value, $pos + 1 ) );
		$first = strtolower( trim( strtok( $rest, ' ' ) ) );
		// Drop trailing "sip"/"web" noise if it is the only token.
		return in_array( $first, array( 'sip', 'web' ), true ) ? '' : $first;
	}

	/**
	 * Resolve an employee for a call given its legs and direction.
	 *
	 * @return array|null Employee map, or null if unattributable.
	 */
	public static function resolve_call( $beller, $bestemming, $direction ) {
		$ext_index  = STM_Settings::ext_index();
		$name_index = STM_Settings::name_index();

		$legs = ( 'outbound' === $direction )
			? array( $beller, $bestemming )
			: array( $bestemming, $beller );

		foreach ( $legs as $leg ) {
			$ext = self::leading_ext( $leg );
			if ( '' !== $ext && isset( $ext_index[ $ext ] ) ) {
				return $ext_index[ $ext ];
			}
		}
		foreach ( $legs as $leg ) {
			$nt = self::name_token( $leg );
			if ( '' !== $nt && isset( $name_index[ $nt ] ) ) {
				return $name_index[ $nt ];
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ *
	 * Aggregation
	 * ------------------------------------------------------------------ */

	/**
	 * Aggregate raw DB rows into per-(employee, date) stats.
	 *
	 * @param array[] $rows DB rows (assoc).
	 * @return array[] keyed "date|employee_id".
	 */
	public static function aggregate( array $rows ) {
		$names   = self::name_map();
		$buckets = array();
		$seen_tickets = array();

		foreach ( $rows as $r ) {
			$key = $r['event_date'] . '|' . $r['employee_id'];
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = self::empty_stat( $r['event_date'], $r['employee_id'], $names );
				$seen_tickets[ $key ] = array();
			}
			$s =& $buckets[ $key ];

			if ( 'email' === $r['channel'] ) {
				if ( 'outbound' === $r['direction'] ) {
					$s['emails_sent']++;
				} else {
					$s['emails_received']++;
				}
			} elseif ( 'call' === $r['channel'] ) {
				$s['calls_handled']++;
				$s['call_seconds'] += (int) $r['duration_seconds'];
				if ( 'outbound' === $r['direction'] ) {
					$s['outbound_calls']++;
				} elseif ( 'inbound' === $r['direction'] ) {
					$s['inbound_calls']++;
				}
			}

			if ( ! empty( $r['is_escalation'] ) ) {
				$s['escalations']++;
			}
			if ( ! empty( $r['reopened'] ) ) {
				$s['reopened']++;
			}
			if ( isset( $r['is_fcr'] ) && '' !== $r['is_fcr'] && null !== $r['is_fcr'] && (int) $r['is_fcr'] === 1 ) {
				$s['fcr']++;
			}
			if ( isset( $r['csat'] ) && null !== $r['csat'] && '' !== $r['csat'] ) {
				$s['csat_scores'][] = (float) $r['csat'];
			}
			if ( ! empty( $r['category'] ) ) {
				$cat = $r['category'];
				$s['categories'][ $cat ] = ( $s['categories'][ $cat ] ?? 0 ) + 1;
			}
			if ( ! empty( $r['ticket_id'] ) && ! isset( $seen_tickets[ $key ][ $r['ticket_id'] ] ) ) {
				$seen_tickets[ $key ][ $r['ticket_id'] ] = true;
				$s['ticket_count']++;
			}
			unset( $s );
		}

		ksort( $buckets );
		return array_values( $buckets );
	}

	private static function empty_stat( $date, $employee_id, $names ) {
		return array(
			'date'            => $date,
			'employee_id'     => $employee_id,
			'employee_name'   => $names[ $employee_id ] ?? $employee_id,
			'emails_sent'     => 0,
			'emails_received' => 0,
			'calls_handled'   => 0,
			'call_seconds'    => 0,
			'inbound_calls'   => 0,
			'outbound_calls'  => 0,
			'escalations'     => 0,
			'reopened'        => 0,
			'fcr'             => 0,
			'ticket_count'    => 0,
			'csat_scores'     => array(),
			'categories'      => array(),
		);
	}

	/** Collapse daily rows into one total row per employee. */
	public static function employee_totals( array $daily ) {
		$totals = array();
		foreach ( $daily as $s ) {
			$id = $s['employee_id'];
			if ( ! isset( $totals[ $id ] ) ) {
				$totals[ $id ] = self::empty_stat( '', $id, array( $id => $s['employee_name'] ) );
			}
			foreach ( array( 'emails_sent', 'emails_received', 'calls_handled', 'call_seconds',
				'inbound_calls', 'outbound_calls', 'escalations', 'reopened', 'fcr', 'ticket_count' ) as $f ) {
				$totals[ $id ][ $f ] += $s[ $f ];
			}
			foreach ( $s['categories'] as $c => $n ) {
				$totals[ $id ]['categories'][ $c ] = ( $totals[ $id ]['categories'][ $c ] ?? 0 ) + $n;
			}
			$totals[ $id ]['csat_scores'] = array_merge( $totals[ $id ]['csat_scores'], $s['csat_scores'] );
		}
		usort( $totals, function ( $a, $b ) { return strcmp( $a['employee_name'], $b['employee_name'] ); } );
		return $totals;
	}

	/* ------------------------------------------------------------------ *
	 * Derived figures (safe against divide-by-zero / missing data)
	 * ------------------------------------------------------------------ */

	public static function call_minutes( $stat ) {
		return round( $stat['call_seconds'] / 60, 1 );
	}

	public static function avg_call_minutes( $stat ) {
		return $stat['calls_handled'] ? round( $stat['call_seconds'] / 60 / $stat['calls_handled'], 1 ) : 0.0;
	}

	public static function escalation_rate( $stat ) {
		return $stat['ticket_count'] ? round( $stat['escalations'] / $stat['ticket_count'], 3 ) : null;
	}

	public static function fcr_rate( $stat ) {
		return $stat['ticket_count'] ? round( $stat['fcr'] / $stat['ticket_count'], 3 ) : null;
	}

	public static function avg_csat( $stat ) {
		$n = count( $stat['csat_scores'] );
		return $n ? round( array_sum( $stat['csat_scores'] ) / $n, 2 ) : null;
	}

	/** Weighted category difficulty for a (totals) stat, or null if no categories. */
	public static function weighted_difficulty( $stat ) {
		$mix = $stat['categories'];
		if ( empty( $mix ) ) {
			return null;
		}
		$weights = STM_Settings::category_weights();
		$total   = array_sum( $mix );
		$acc     = 0.0;
		foreach ( $mix as $cat => $n ) {
			$acc += ( isset( $weights[ $cat ] ) ? (float) $weights[ $cat ] : 1.0 ) * $n;
		}
		return round( $acc / $total, 2 );
	}

	public static function avg_touches_per_ticket( $stat ) {
		$interactions = $stat['emails_sent'] + $stat['calls_handled'];
		return $stat['ticket_count'] ? round( $interactions / $stat['ticket_count'], 2 ) : null;
	}

	/** id -> display name from the settings mapping. */
	public static function name_map() {
		$map = array();
		foreach ( STM_Settings::employees() as $e ) {
			$map[ $e['id'] ] = $e['name'];
		}
		return $map;
	}
}
