<?php
/**
 * Trends page: change-over-time views for HR/CEO questions.
 *
 * - KPI tiles: how busy is this week vs last week vs the same week last year.
 * - Team volume line chart with a comparison overlay (previous period / last year).
 * - Per-employee weekly small multiples, comparable against a colleague or
 *   against the same employee in an earlier period.
 * - Weekday × hour heatmap to see intra-day/weekday patterns.
 *
 * All charts are dependency-free inline SVG/HTML, colors follow the validated
 * reference palette (2 categorical slots + one sequential blue ramp).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STM_Trends {

	/* Palette (validated, light mode): series + sequential ramp + chrome. */
	const C_A       = '#2a78d6'; // series A (blue)
	const C_B       = '#eb6834'; // series B / colleague (orange)
	const C_CTX     = '#898781'; // context overlay (previous period / last year)
	const C_GRID    = '#e1e0d9';
	const C_BASE    = '#c3c2b7';
	const C_INK     = '#0b0b0b';
	const C_INK2    = '#52514e';
	const C_MUTED   = '#898781';
	const SEQ       = array( '#cde2fb', '#9ec5f4', '#6da7ec', '#3987e5', '#256abf', '#1c5cab', '#104281' );

	/* ------------------------------------------------------------------ *
	 * Data helpers
	 * ------------------------------------------------------------------ */

	/** Period presets: key => [days, bucket, label]. */
	public static function presets() {
		return array(
			'28d' => array( 28, 'day', __( 'Laatste 4 weken (per dag)', 'stralend-team-monitor' ) ),
			'13w' => array( 91, 'week', __( 'Laatste 13 weken', 'stralend-team-monitor' ) ),
			'26w' => array( 182, 'week', __( 'Laatste 26 weken', 'stralend-team-monitor' ) ),
			'52w' => array( 364, 'week', __( 'Laatste 52 weken', 'stralend-team-monitor' ) ),
		);
	}

	/** Sum of interactions per bucket for a window. Returns aligned arrays. */
	private function series( $from, $to, $bucket, $employee_id = '' ) {
		global $wpdb;
		$table = STM_DB::table();
		$expr  = ( 'week' === $bucket ) ? 'YEARWEEK(event_date,3)' : 'event_date';

		$sql  = "SELECT {$expr} AS b,
			SUM(CASE WHEN channel='email' AND direction='outbound' THEN 1 ELSE 0 END) AS emails_sent,
			SUM(CASE WHEN channel='call' THEN 1 ELSE 0 END) AS calls,
			SUM(CASE WHEN channel='call' THEN duration_seconds ELSE 0 END) AS secs,
			SUM(CASE WHEN channel='whatsapp' THEN 1 ELSE 0 END) AS wa_msgs,
			COUNT(DISTINCT CASE WHEN channel='whatsapp' THEN thread_id END) AS wa_sessions
			FROM {$table} WHERE event_date BETWEEN %s AND %s";
		$args = array( $from, $to );
		if ( '' !== $employee_id ) {
			$sql   .= ' AND employee_id = %s';
			$args[] = $employee_id;
		}
		$sql .= ' GROUP BY b';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		$by_key = array();
		foreach ( $rows as $r ) {
			$by_key[ (string) $r['b'] ] = $r;
		}

		$out = array( 'labels' => array(), 'emails' => array(), 'calls' => array(), 'minutes' => array(), 'wa_msgs' => array(), 'wa_sessions' => array(), 'total' => array() );
		foreach ( $this->buckets( $from, $to, $bucket ) as $key => $label ) {
			$r = isset( $by_key[ $key ] ) ? $by_key[ $key ] : array( 'emails_sent' => 0, 'calls' => 0, 'secs' => 0, 'wa_msgs' => 0, 'wa_sessions' => 0 );
			$out['labels'][]      = $label;
			$out['emails'][]      = (int) $r['emails_sent'];
			$out['calls'][]       = (int) $r['calls'];
			$out['minutes'][]     = round( ( (int) $r['secs'] ) / 60, 1 );
			$out['wa_msgs'][]     = (int) $r['wa_msgs'];
			$out['wa_sessions'][] = (int) $r['wa_sessions'];
			// One WhatsApp SESSION counts as one interaction (a session ≈ one
			// email's worth of work); individual messages are detail.
			$out['total'][] = (int) $r['emails_sent'] + (int) $r['calls'] + (int) $r['wa_sessions'];
		}
		return $out;
	}

	/** Ordered bucket key => short label for a window. */
	private function buckets( $from, $to, $bucket ) {
		$out = array();
		$cur = new DateTime( $from );
		$end = new DateTime( $to );
		if ( 'day' === $bucket ) {
			while ( $cur <= $end ) {
				$out[ $cur->format( 'Y-m-d' ) ] = $cur->format( 'd-m' );
				$cur->modify( '+1 day' );
			}
		} else {
			$cur->modify( 'monday this week' );
			while ( $cur <= $end ) {
				$out[ $cur->format( 'o' ) . $cur->format( 'W' ) ] = 'wk ' . (int) $cur->format( 'W' );
				$cur->modify( '+1 week' );
			}
		}
		return $out;
	}

	/** Aggregated totals for a window (team or one employee). */
	private function totals( $from, $to, $employee_id = '' ) {
		global $wpdb;
		$table = STM_DB::table();
		$sql   = "SELECT
			SUM(CASE WHEN channel='email' AND direction='outbound' THEN 1 ELSE 0 END) AS emails_sent,
			SUM(CASE WHEN channel='call' THEN 1 ELSE 0 END) AS calls,
			SUM(CASE WHEN channel='call' THEN duration_seconds ELSE 0 END) AS secs,
			SUM(CASE WHEN channel='whatsapp' THEN 1 ELSE 0 END) AS wa_msgs,
			COUNT(DISTINCT CASE WHEN channel='whatsapp' THEN thread_id END) AS wa_sessions,
			COUNT(DISTINCT event_date) AS active_days
			FROM {$table} WHERE event_date BETWEEN %s AND %s";
		$args  = array( $from, $to );
		if ( '' !== $employee_id ) {
			$sql   .= ' AND employee_id = %s';
			$args[] = $employee_id;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return array(
			'emails'      => (int) ( $r['emails_sent'] ?? 0 ),
			'calls'       => (int) ( $r['calls'] ?? 0 ),
			'minutes'     => round( ( (int) ( $r['secs'] ?? 0 ) ) / 60, 1 ),
			'wa_msgs'     => (int) ( $r['wa_msgs'] ?? 0 ),
			'wa_sessions' => (int) ( $r['wa_sessions'] ?? 0 ),
			'total'       => (int) ( $r['emails_sent'] ?? 0 ) + (int) ( $r['calls'] ?? 0 ) + (int) ( $r['wa_sessions'] ?? 0 ),
			'active_days' => (int) ( $r['active_days'] ?? 0 ),
		);
	}

	/**
	 * Inbound-email response stats for a window (team level).
	 *
	 * Pairs each inbound email with the first outbound email in the same
	 * HubSpot thread at or after it. Emails without a thread id can only be
	 * counted as "binnengekomen", never as beantwoord/onbeantwoord — coverage
	 * is reported so the rate is read honestly.
	 *
	 * @return array {
	 *   inbound, inbound_threaded, answered, answered_same_day,
	 *   avg_first_response_hours|null, per_day: date => [inbound, same_day]
	 * }
	 */
	private function response_stats( $from, $to ) {
		global $wpdb;
		$table = STM_DB::table();
		// Include a lookahead window so replies just after the period still count.
		$look = gmdate( 'Y-m-d', strtotime( $to . ' +7 days' ) );
		$sql  = "SELECT event_ts, event_date, direction, thread_id
			FROM {$table}
			WHERE channel = 'email' AND event_date BETWEEN %s AND %s
			ORDER BY event_ts ASC";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array( $from, $look ) ), ARRAY_A );

		$out_by_thread = array();
		foreach ( $rows as $r ) {
			if ( 'outbound' === $r['direction'] && ! empty( $r['thread_id'] ) ) {
				$out_by_thread[ $r['thread_id'] ][] = $r['event_ts'];
			}
		}

		$stats = array(
			'inbound'           => 0,
			'inbound_threaded'  => 0,
			'answered'          => 0,
			'answered_same_day' => 0,
			'response_hours'    => array(),
			'per_day'           => array(),
			// Disjoint distribution of threaded inbound mail:
			'dist_1h'           => 0, // first reply within an hour
			'dist_same_day'     => 0, // same day, but later than an hour
			'dist_later'        => 0, // answered on a later day
			'dist_unanswered'   => 0, // no reply found (yet)
			'backlog_2d'        => 0, // unanswered AND older than 2 days
		);
		$backlog_cutoff = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -2 days' ) );

		foreach ( $rows as $r ) {
			if ( 'inbound' !== $r['direction'] || $r['event_date'] > $to ) {
				continue;
			}
			$day = $r['event_date'];
			if ( ! isset( $stats['per_day'][ $day ] ) ) {
				$stats['per_day'][ $day ] = array( 'inbound' => 0, 'same_day' => 0 );
			}
			$stats['inbound']++;
			$stats['per_day'][ $day ]['inbound']++;

			if ( empty( $r['thread_id'] ) ) {
				continue;
			}
			$stats['inbound_threaded']++;

			$in_ts    = strtotime( $r['event_ts'] );
			$answered = false;
			foreach ( (array) ( $out_by_thread[ $r['thread_id'] ] ?? array() ) as $out_ts_raw ) {
				$out_ts = strtotime( $out_ts_raw );
				if ( $out_ts >= $in_ts ) {
					$answered = true;
					$hours    = ( $out_ts - $in_ts ) / HOUR_IN_SECONDS;
					$same     = ( substr( $out_ts_raw, 0, 10 ) === $day );
					$stats['answered']++;
					$stats['response_hours'][] = $hours;
					if ( $same ) {
						$stats['answered_same_day']++;
						$stats['per_day'][ $day ]['same_day']++;
					}
					if ( $hours <= 1 ) {
						$stats['dist_1h']++;
					} elseif ( $same ) {
						$stats['dist_same_day']++;
					} else {
						$stats['dist_later']++;
					}
					break;
				}
			}
			if ( ! $answered ) {
				$stats['dist_unanswered']++;
				if ( $day <= $backlog_cutoff ) {
					$stats['backlog_2d']++;
				}
			}
		}
		return $stats;
	}

	/** weekday(0=ma) × hour counts for a window. */
	private function heatmap_data( $from, $to, $employee_id = '' ) {
		global $wpdb;
		$table = STM_DB::table();
		$sql   = "SELECT WEEKDAY(event_ts) wd, HOUR(event_ts) hr, COUNT(*) c
			FROM {$table} WHERE event_date BETWEEN %s AND %s";
		$args  = array( $from, $to );
		if ( '' !== $employee_id ) {
			$sql   .= ' AND employee_id = %s';
			$args[] = $employee_id;
		}
		$sql .= ' GROUP BY wd, hr';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		$grid = array();
		foreach ( $rows as $r ) {
			$grid[ (int) $r['wd'] ][ (int) $r['hr'] ] = (int) $r['c'];
		}
		return $grid;
	}

	/** How often each weekday (0=ma) occurs in the window, for honest averages. */
	private function weekday_occurrences( $from, $to ) {
		$counts = array_fill( 0, 7, 0 );
		$cur    = new DateTime( $from );
		$end    = new DateTime( $to );
		while ( $cur <= $end ) {
			$counts[ (int) $cur->format( 'N' ) - 1 ]++;
			$cur->modify( '+1 day' );
		}
		return $counts;
	}

	private static function pct_delta( $now, $then ) {
		if ( $then <= 0 ) {
			return null;
		}
		return round( ( $now - $then ) / $then * 100 );
	}

	/* ------------------------------------------------------------------ *
	 * Page
	 * ------------------------------------------------------------------ */

	public function render_page() {
		if ( ! STM_Settings::can_access() ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot Team Monitor.', 'stralend-team-monitor' ) );
		}

		$presets = self::presets();
		$p       = isset( $_GET['p'] ) && isset( $presets[ $_GET['p'] ] ) ? sanitize_key( $_GET['p'] ) : '13w';
		list( $days, $bucket ) = $presets[ $p ];

		$employees = array_values( array_filter( STM_Settings::employees(), static function ( $e ) {
			return '' !== $e['id'];
		} ) );
		$emp_ids = wp_list_pluck( $employees, 'name', 'id' );

		$emp = isset( $_GET['emp'] ) ? sanitize_key( $_GET['emp'] ) : '';
		if ( '' === $emp || ! isset( $emp_ids[ $emp ] ) ) {
			$emp = $employees ? $employees[0]['id'] : '';
		}
		$cmp_raw = isset( $_GET['cmp'] ) ? sanitize_text_field( wp_unslash( $_GET['cmp'] ) ) : 'prev';
		$hm      = ( isset( $_GET['hm'] ) && 'emp' === $_GET['hm'] ) ? 'emp' : 'team';

		$to   = current_time( 'Y-m-d' );
		$from = gmdate( 'Y-m-d', strtotime( $to . ' -' . ( $days - 1 ) . ' days' ) );

		?>
		<div class="wrap stm-dashboard stm-trends">
			<h1><?php esc_html_e( 'Team Monitor — Trends', 'stralend-team-monitor' ); ?></h1>

			<form method="get" class="stm-filter">
				<input type="hidden" name="page" value="stm-trends" />
				<label><?php esc_html_e( 'Periode', 'stralend-team-monitor' ); ?>
					<select name="p">
						<?php foreach ( $presets as $key => $def ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $p, $key ); ?>><?php echo esc_html( $def[2] ); ?></option>
						<?php endforeach; ?>
					</select></label>
				<label><?php esc_html_e( 'Medewerker', 'stralend-team-monitor' ); ?>
					<select name="emp">
						<?php foreach ( $emp_ids as $id => $name ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $emp, $id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select></label>
				<label><?php esc_html_e( 'Vergelijk met', 'stralend-team-monitor' ); ?>
					<select name="cmp">
						<option value="prev" <?php selected( $cmp_raw, 'prev' ); ?>><?php esc_html_e( 'Zichzelf — vorige periode', 'stralend-team-monitor' ); ?></option>
						<option value="yoy" <?php selected( $cmp_raw, 'yoy' ); ?>><?php esc_html_e( 'Zichzelf — vorig jaar', 'stralend-team-monitor' ); ?></option>
						<?php foreach ( $emp_ids as $id => $name ) : ?>
							<?php if ( $id !== $emp ) : ?>
								<option value="emp:<?php echo esc_attr( $id ); ?>" <?php selected( $cmp_raw, 'emp:' . $id ); ?>><?php echo esc_html( sprintf( __( 'Collega — %s', 'stralend-team-monitor' ), $name ) ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
						<option value="none" <?php selected( $cmp_raw, 'none' ); ?>><?php esc_html_e( 'Geen vergelijking', 'stralend-team-monitor' ); ?></option>
					</select></label>
				<label><?php esc_html_e( 'Drukte-patroon', 'stralend-team-monitor' ); ?>
					<select name="hm">
						<option value="team" <?php selected( $hm, 'team' ); ?>><?php esc_html_e( 'Team', 'stralend-team-monitor' ); ?></option>
						<option value="emp" <?php selected( $hm, 'emp' ); ?>><?php esc_html_e( 'Gekozen medewerker', 'stralend-team-monitor' ); ?></option>
					</select></label>
				<?php submit_button( __( 'Toon', 'stralend-team-monitor' ), 'secondary', '', false ); ?>
			</form>

			<?php
			$this->render_kpis( $to );
			$this->render_team_section( $from, $to, $bucket );
			$this->render_orders_section( $from, $to, $bucket );
			$this->render_response_section( $from, $to, $bucket );
			$this->render_employee_section( $from, $to, $emp, $emp_ids, $cmp_raw );
			$this->render_heatmap_section( $from, $to, ( 'emp' === $hm ) ? $emp : '', ( 'emp' === $hm && isset( $emp_ids[ $emp ] ) ) ? $emp_ids[ $emp ] : '' );
			?>

			<p class="description" style="margin-top:20px">
				<?php esc_html_e( 'Lees eerlijk: volume is niet hetzelfde als kwaliteit of inzet. Houd bij vergelijkingen rekening met deeltijd/rooster, taakverdeling en type werk — en bespreek opvallende patronen mét de medewerker, niet alleen óver de cijfers.', 'stralend-team-monitor' ); ?>
			</p>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Sections
	 * ------------------------------------------------------------------ */

	/** KPI tiles: this week vs last week vs same week last year (team). */
	private function render_kpis( $today ) {
		$monday        = gmdate( 'Y-m-d', strtotime( $today . ' monday this week' ) );
		$this_week     = $this->totals( $monday, $today );
		$lw_from       = gmdate( 'Y-m-d', strtotime( $monday . ' -7 days' ) );
		$lw_to         = gmdate( 'Y-m-d', strtotime( $today . ' -7 days' ) );
		$last_week     = $this->totals( $lw_from, $lw_to );
		$ly_from       = gmdate( 'Y-m-d', strtotime( $monday . ' -364 days' ) );
		$ly_to         = gmdate( 'Y-m-d', strtotime( $today . ' -364 days' ) );
		$last_year     = $this->totals( $ly_from, $ly_to );

		$d_week = self::pct_delta( $this_week['total'], $last_week['total'] );
		$d_year = self::pct_delta( $this_week['total'], $last_year['total'] );

		echo '<div class="stm-tiles">';
		$sub = sprintf( '%s ✉ · %s ☎ · %s min', number_format_i18n( $this_week['emails'] ), number_format_i18n( $this_week['calls'] ), number_format_i18n( $this_week['minutes'] ) );
		if ( $this_week['wa_sessions'] > 0 ) {
			$sub .= sprintf( ' · %s app-sessies', number_format_i18n( $this_week['wa_sessions'] ) );
		}
		$this->tile( __( 'Deze week (t/m vandaag)', 'stralend-team-monitor' ), number_format_i18n( $this_week['total'] ), $sub );
		$this->tile(
			__( 'T.o.v. vorige week', 'stralend-team-monitor' ),
			$this->delta_text( $d_week ),
			sprintf( __( 'vorige week t/m zelfde dag: %s', 'stralend-team-monitor' ), number_format_i18n( $last_week['total'] ) )
		);
		$this->tile(
			__( 'T.o.v. vorig jaar', 'stralend-team-monitor' ),
			( null === $d_year ) ? '—' : $this->delta_text( $d_year ),
			( null === $d_year )
				? __( 'nog geen data van vorig jaar (vul historie aan)', 'stralend-team-monitor' )
				: sprintf( __( 'zelfde week vorig jaar: %s', 'stralend-team-monitor' ), number_format_i18n( $last_year['total'] ) )
		);
		$missed = $this->missed_calls( $monday, $today );
		if ( null !== $missed ) {
			$this->tile(
				__( 'Onbeantwoorde oproepen (indicatie)', 'stralend-team-monitor' ),
				number_format_i18n( $missed ),
				__( 'inkomend met 0 sec gespreksduur, deze week', 'stralend-team-monitor' )
			);
		}
		echo '</div>';
	}

	/**
	 * Missed-call indicator: inbound calls with zero talk time. Returns null
	 * when the window has no call data at all (metric would be meaningless).
	 */
	private function missed_calls( $from, $to ) {
		global $wpdb;
		$table = STM_DB::table();
		$sql   = "SELECT COUNT(*) AS calls,
			SUM(CASE WHEN direction = 'inbound' AND duration_seconds = 0 THEN 1 ELSE 0 END) AS missed
			FROM {$table} WHERE channel = 'call' AND event_date BETWEEN %s AND %s";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( $sql, array( $from, $to ) ), ARRAY_A );
		if ( ! $r || 0 === (int) $r['calls'] ) {
			return null;
		}
		return (int) $r['missed'];
	}

	private function tile( $label, $value, $sub ) {
		echo '<div class="stm-tile">';
		echo '<span class="stm-tile-label">' . esc_html( $label ) . '</span>';
		echo '<span class="stm-tile-value">' . esc_html( $value ) . '</span>';
		echo '<span class="stm-tile-sub">' . esc_html( $sub ) . '</span>';
		echo '</div>';
	}

	private function delta_text( $pct ) {
		if ( null === $pct ) {
			return '—';
		}
		$arrow = $pct > 0 ? '▲' : ( $pct < 0 ? '▼' : '=' );
		return sprintf( '%s %s%d%%', $arrow, $pct > 0 ? '+' : '', $pct );
	}

	/** Team volume over time with a context overlay. */
	private function render_team_section( $from, $to, $bucket ) {
		$cur = $this->series( $from, $to, $bucket );

		// Overlay: same window last year when it has data, else the previous period.
		$len      = ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS;
		$ly_from  = gmdate( 'Y-m-d', strtotime( $from . ' -364 days' ) );
		$ly_to    = gmdate( 'Y-m-d', strtotime( $to . ' -364 days' ) );
		$ly       = $this->series( $ly_from, $ly_to, $bucket );
		$overlay  = null;
		$ov_label = '';
		if ( array_sum( $ly['total'] ) > 0 ) {
			$overlay  = $ly['total'];
			$ov_label = __( 'vorig jaar', 'stralend-team-monitor' );
		} else {
			$pv_to   = gmdate( 'Y-m-d', strtotime( $from . ' -1 day' ) );
			$pv_from = gmdate( 'Y-m-d', strtotime( $pv_to . ' -' . $len . ' days' ) );
			$pv      = $this->series( $pv_from, $pv_to, $bucket );
			if ( array_sum( $pv['total'] ) > 0 ) {
				$overlay  = $pv['total'];
				$ov_label = __( 'vorige periode', 'stralend-team-monitor' );
			}
		}

		echo '<h2>' . esc_html__( 'Team-drukte — interacties (verzonden e-mails + telefoontjes)', 'stralend-team-monitor' ) . '</h2>';
		$series = array( array(
			'label'  => __( 'deze periode', 'stralend-team-monitor' ),
			'color'  => self::C_A,
			'values' => $cur['total'],
		) );
		if ( $overlay ) {
			$series[] = array(
				'label'  => $ov_label,
				'color'  => self::C_CTX,
				'values' => $overlay,
				'dash'   => true,
			);
		}
		$this->legend( $series );
		$this->line_chart( $cur['labels'], $series, 720, 240 );
		$this->series_table( __( 'Tabel: team per periode', 'stralend-team-monitor' ), $cur['labels'], $series );
	}

	/**
	 * WooCommerce orders vs contact pressure. Answers the CEO question: is it
	 * busier because we sell more, or does each order need more contact?
	 * Hidden when WooCommerce is absent or the window has no orders.
	 */
	private function render_orders_section( $from, $to, $bucket ) {
		if ( ! class_exists( 'STM_Woo' ) || ! STM_Woo::available() ) {
			return;
		}
		$per_day = STM_Woo::orders_per_day( $from, $to );
		if ( empty( $per_day ) ) {
			return;
		}

		// Roll orders up into the page's buckets.
		$keys   = array_keys( $this->buckets( $from, $to, $bucket ) );
		$pos    = array_flip( $keys );
		$labels = array_values( $this->buckets( $from, $to, $bucket ) );
		$orders = array_fill( 0, count( $keys ), 0 );
		foreach ( $per_day as $day => $c ) {
			$dt  = new DateTime( $day );
			$key = ( 'week' === $bucket ) ? $dt->format( 'o' ) . $dt->format( 'W' ) : $day;
			if ( isset( $pos[ $key ] ) ) {
				$orders[ $pos[ $key ] ] += $c;
			}
		}

		$team  = $this->series( $from, $to, $bucket );
		$ratio = array();
		foreach ( $orders as $i => $n ) {
			$ratio[] = $n > 0 ? round( $team['total'][ $i ] / $n, 2 ) : 0;
		}

		$orders_total = array_sum( $orders );
		$inter_total  = array_sum( $team['total'] );
		$ratio_total  = $orders_total > 0 ? round( $inter_total / $orders_total, 2 ) : null;

		// Same numbers for the previous window, for the delta tiles.
		$len     = ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS;
		$pv_to   = gmdate( 'Y-m-d', strtotime( $from . ' -1 day' ) );
		$pv_from = gmdate( 'Y-m-d', strtotime( $pv_to . ' -' . $len . ' days' ) );
		$pv_orders = STM_Woo::count( $pv_from, $pv_to );
		$pv_team   = $this->totals( $pv_from, $pv_to );
		$pv_ratio  = $pv_orders > 0 ? $pv_team['total'] / $pv_orders : null;

		echo '<h2>' . esc_html__( 'Bestellingen & contactdruk', 'stralend-team-monitor' ) . '</h2>';
		echo '<div class="stm-tiles">';
		$d_orders = self::pct_delta( $orders_total, $pv_orders );
		$this->tile(
			__( 'Bestellingen', 'stralend-team-monitor' ),
			number_format_i18n( $orders_total ),
			( null === $d_orders ) ? __( 'in de gekozen periode', 'stralend-team-monitor' ) : sprintf( __( '%s t.o.v. vorige periode', 'stralend-team-monitor' ), $this->delta_text( $d_orders ) )
		);
		$d_ratio = ( null !== $ratio_total && null !== $pv_ratio && $pv_ratio > 0 ) ? (int) round( ( $ratio_total - $pv_ratio ) / $pv_ratio * 100 ) : null;
		$this->tile(
			__( 'Contactmomenten per bestelling', 'stralend-team-monitor' ),
			( null === $ratio_total ) ? '—' : number_format_i18n( $ratio_total, 2 ),
			( null === $d_ratio )
				? __( 'e-mails uit + telefoontjes, gedeeld door bestellingen', 'stralend-team-monitor' )
				: sprintf( __( '%s t.o.v. vorige periode — stijgt dit, dan kost elke bestelling meer werk', 'stralend-team-monitor' ), $this->delta_text( $d_ratio ) )
		);
		echo '</div>';

		echo '<div class="stm-grid">';
		echo '<div><h3 class="stm-subhead">' . esc_html__( 'Bestellingen', 'stralend-team-monitor' ) . '</h3>';
		$this->line_chart( $labels, array( array( 'label' => __( 'bestellingen', 'stralend-team-monitor' ), 'color' => self::C_A, 'values' => $orders ) ), 480, 180 );
		echo '</div><div><h3 class="stm-subhead">' . esc_html__( 'Contactmomenten per bestelling', 'stralend-team-monitor' ) . '</h3>';
		$this->line_chart( $labels, array( array( 'label' => __( 'per bestelling', 'stralend-team-monitor' ), 'color' => self::C_B, 'values' => $ratio ) ), 480, 180 );
		echo '</div></div>';

		$this->series_table( __( 'Tabel: bestellingen & contactdruk', 'stralend-team-monitor' ), $labels, array(
			array( 'label' => __( 'bestellingen', 'stralend-team-monitor' ), 'values' => $orders ),
			array( 'label' => __( 'interacties', 'stralend-team-monitor' ), 'values' => $team['total'] ),
			array( 'label' => __( 'per bestelling', 'stralend-team-monitor' ), 'values' => $ratio ),
		) );
	}

	/** Inbound email volume + same-day answers (team). */
	private function render_response_section( $from, $to, $bucket ) {
		$stats = $this->response_stats( $from, $to );
		if ( 0 === $stats['inbound'] ) {
			return;
		}

		echo '<h2>' . esc_html__( 'E-mail — binnengekomen & dezelfde dag beantwoord (team)', 'stralend-team-monitor' ) . '</h2>';

		$coverage = $stats['inbound'] ? (int) round( $stats['inbound_threaded'] / $stats['inbound'] * 100 ) : 0;
		$rate     = $stats['inbound_threaded'] ? (int) round( $stats['answered_same_day'] / $stats['inbound_threaded'] * 100 ) : null;
		$avg_h    = $stats['response_hours'] ? round( array_sum( $stats['response_hours'] ) / count( $stats['response_hours'] ), 1 ) : null;

		echo '<div class="stm-tiles">';
		$this->tile( __( 'Binnengekomen e-mails', 'stralend-team-monitor' ), number_format_i18n( $stats['inbound'] ), sprintf( __( 'in de gekozen periode', 'stralend-team-monitor' ) ) );
		$this->tile(
			__( 'Zelfde dag beantwoord', 'stralend-team-monitor' ),
			( null === $rate ) ? '—' : $rate . '%',
			( null === $rate )
				? __( 'nog geen thread-informatie — synchroniseer HubSpot opnieuw', 'stralend-team-monitor' )
				: sprintf( __( 'op basis van %d%% van de mails (met thread-info)', 'stralend-team-monitor' ), $coverage )
		);
		$this->tile(
			__( 'Gem. eerste reactietijd', 'stralend-team-monitor' ),
			( null === $avg_h ) ? '—' : ( ( $avg_h < 1 ) ? round( $avg_h * 60 ) . ' min' : number_format_i18n( $avg_h, 1 ) . ' uur' ),
			__( 'van binnenkomst tot eerste antwoord in dezelfde thread', 'stralend-team-monitor' )
		);
		if ( $stats['inbound_threaded'] > 0 ) {
			$this->tile(
				__( 'Onbeantwoord (>2 dagen)', 'stralend-team-monitor' ),
				number_format_i18n( $stats['backlog_2d'] ),
				__( 'binnengekomen mails zonder antwoord, ouder dan 2 dagen', 'stralend-team-monitor' )
			);
		}
		echo '</div>';

		$this->render_response_distribution( $stats );

		// Roll the per-day pairs up into the page's buckets.
		$labels   = array();
		$inbound  = array();
		$same_day = array();
		foreach ( $this->buckets( $from, $to, $bucket ) as $key => $label ) {
			$labels[]   = $label;
			$inbound[]  = 0;
			$same_day[] = 0;
		}
		$keys = array_keys( $this->buckets( $from, $to, $bucket ) );
		$pos  = array_flip( $keys );
		foreach ( $stats['per_day'] as $day => $p ) {
			$dt  = new DateTime( $day );
			$key = ( 'week' === $bucket ) ? $dt->format( 'o' ) . $dt->format( 'W' ) : $day;
			if ( isset( $pos[ $key ] ) ) {
				$inbound[ $pos[ $key ] ]  += $p['inbound'];
				$same_day[ $pos[ $key ] ] += $p['same_day'];
			}
		}

		$series = array(
			array( 'label' => __( 'binnengekomen', 'stralend-team-monitor' ), 'color' => self::C_A, 'values' => $inbound ),
			array( 'label' => __( 'zelfde dag beantwoord', 'stralend-team-monitor' ), 'color' => self::C_B, 'values' => $same_day ),
		);
		$this->legend( $series );
		$this->line_chart( $labels, $series, 720, 240 );
		$this->series_table( __( 'Tabel: binnengekomen vs zelfde dag beantwoord', 'stralend-team-monitor' ), $labels, $series );
	}

	/**
	 * Response-time distribution as a segmented part-to-whole bar.
	 * Ordinal blue ramp for the answered buckets, neutral gray for unanswered.
	 */
	private function render_response_distribution( $stats ) {
		$total = $stats['dist_1h'] + $stats['dist_same_day'] + $stats['dist_later'] + $stats['dist_unanswered'];
		if ( $total < 5 ) {
			return; // too little threaded mail for a meaningful split
		}
		$segments = array(
			array( __( 'binnen 1 uur', 'stralend-team-monitor' ), $stats['dist_1h'], '#86b6ef' ),
			array( __( 'zelfde dag', 'stralend-team-monitor' ), $stats['dist_same_day'], '#3987e5' ),
			array( __( 'later', 'stralend-team-monitor' ), $stats['dist_later'], '#1c5cab' ),
			array( __( 'onbeantwoord', 'stralend-team-monitor' ), $stats['dist_unanswered'], '#c3c2b7' ),
		);
		echo '<h3 class="stm-subhead">' . esc_html__( 'Hoe snel wordt beantwoord?', 'stralend-team-monitor' ) . '</h3>';
		echo '<div class="stm-distbar" role="img" aria-label="' . esc_attr__( 'Verdeling reactietijd', 'stralend-team-monitor' ) . '">';
		foreach ( $segments as $seg ) {
			if ( $seg[1] <= 0 ) {
				continue;
			}
			$pct = $seg[1] / $total * 100;
			$tip = sprintf( '%s: %s (%s%%)', $seg[0], number_format_i18n( $seg[1] ), round( $pct ) );
			echo '<span style="width:' . esc_attr( round( $pct, 2 ) ) . '%;background:' . esc_attr( $seg[2] ) . '" title="' . esc_attr( $tip ) . '"></span>';
		}
		echo '</div>';
		echo '<p class="stm-legend">';
		foreach ( $segments as $seg ) {
			$pct = round( $seg[1] / $total * 100 );
			echo '<span class="stm-legend-item"><span class="stm-chip" style="background:' . esc_attr( $seg[2] ) . '"></span>' . esc_html( $seg[0] . ' ' . $pct . '%' ) . '</span>';
		}
		echo '</p>';
	}

	/** Per-employee weekly small multiples with comparison. */
	private function render_employee_section( $from, $to, $emp, $emp_ids, $cmp_raw ) {
		if ( '' === $emp ) {
			return;
		}
		$a_name = isset( $emp_ids[ $emp ] ) ? $emp_ids[ $emp ] : $emp;
		$a      = $this->series( $from, $to, 'week', $emp );
		$a_tot  = $this->totals( $from, $to, $emp );

		$b        = null;
		$b_name   = '';
		$b_color  = self::C_CTX;
		$b_dash   = true;
		if ( 0 === strpos( $cmp_raw, 'emp:' ) ) {
			$b_id = sanitize_key( substr( $cmp_raw, 4 ) );
			if ( isset( $emp_ids[ $b_id ] ) ) {
				$b       = $this->series( $from, $to, 'week', $b_id );
				$b_name  = $emp_ids[ $b_id ];
				$b_color = self::C_B;
				$b_dash  = false;
			}
		} elseif ( 'prev' === $cmp_raw ) {
			$len     = ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS;
			$pv_to   = gmdate( 'Y-m-d', strtotime( $from . ' -1 day' ) );
			$pv_from = gmdate( 'Y-m-d', strtotime( $pv_to . ' -' . $len . ' days' ) );
			$b       = $this->series( $pv_from, $pv_to, 'week', $emp );
			$b_name  = __( 'vorige periode', 'stralend-team-monitor' );
		} elseif ( 'yoy' === $cmp_raw ) {
			$b      = $this->series( gmdate( 'Y-m-d', strtotime( $from . ' -364 days' ) ), gmdate( 'Y-m-d', strtotime( $to . ' -364 days' ) ), 'week', $emp );
			$b_name = __( 'vorig jaar', 'stralend-team-monitor' );
		}
		if ( $b && array_sum( $b['total'] ) === 0 ) {
			$b = null; // no data to compare against — drop the empty overlay
		}

		echo '<h2>' . esc_html( sprintf( __( 'Verloop per week — %s', 'stralend-team-monitor' ), $a_name ) ) . '</h2>';
		$mix = '';
		if ( $a_tot['total'] > 0 ) {
			if ( $a_tot['wa_sessions'] > 0 ) {
				$mix = sprintf(
					/* translators: 1: email share, 2: phone share, 3: whatsapp share, 4: whatsapp messages */
					__( ' Kanaalbalans: %1$d%% e-mail / %2$d%% telefoon / %3$d%% WhatsApp-sessies (%4$s appjes).', 'stralend-team-monitor' ),
					(int) round( $a_tot['emails'] / $a_tot['total'] * 100 ),
					(int) round( $a_tot['calls'] / $a_tot['total'] * 100 ),
					(int) round( $a_tot['wa_sessions'] / $a_tot['total'] * 100 ),
					number_format_i18n( $a_tot['wa_msgs'] )
				);
			} else {
				$mix = sprintf(
					/* translators: 1: email share, 2: phone share */
					__( ' Kanaalbalans: %1$d%% e-mail / %2$d%% telefoon.', 'stralend-team-monitor' ),
					(int) round( $a_tot['emails'] / $a_tot['total'] * 100 ),
					(int) round( $a_tot['calls'] / $a_tot['total'] * 100 )
				);
			}
		}
		$rooster = '';
		$sched   = STM_Settings::scheduled_hours( $emp, $from, $to );
		if ( $sched > 0 && $a_tot['total'] > 0 ) {
			$rooster = sprintf(
				/* translators: 1: interactions per scheduled hour, 2: scheduled hours */
				__( ' Per gewerkt uur (rooster): %1$s interacties (%2$s roosteruren in deze periode).', 'stralend-team-monitor' ),
				number_format_i18n( round( $a_tot['total'] / $sched, 1 ), 1 ),
				number_format_i18n( $sched )
			);
		}
		echo '<p class="description">' . esc_html( sprintf(
			__( 'Totaal deze periode: %1$s e-mails, %2$s telefoontjes, %3$s belminuten — gem. %4$s interacties per actieve dag (%5$d dagen actief).', 'stralend-team-monitor' ),
			number_format_i18n( $a_tot['emails'] ),
			number_format_i18n( $a_tot['calls'] ),
			number_format_i18n( $a_tot['minutes'] ),
			$a_tot['active_days'] ? number_format_i18n( round( $a_tot['total'] / $a_tot['active_days'], 1 ) ) : '0',
			$a_tot['active_days']
		) . $rooster . $mix ) . '</p>';

		$metrics = array(
			'emails'  => __( 'E-mails verzonden', 'stralend-team-monitor' ),
			'calls'   => __( 'Telefoontjes', 'stralend-team-monitor' ),
			'minutes' => __( 'Belminuten', 'stralend-team-monitor' ),
		);
		if ( array_sum( $a['wa_sessions'] ) > 0 || ( $b && array_sum( $b['wa_sessions'] ) > 0 ) ) {
			$metrics['wa_sessions'] = __( 'WhatsApp-sessies', 'stralend-team-monitor' );
		}

		$legend_series = array( array( 'label' => $a_name, 'color' => self::C_A ) );
		if ( $b ) {
			$legend_series[] = array( 'label' => $b_name, 'color' => $b_color );
		}
		$this->legend( $legend_series );

		echo '<div class="stm-multiples">';
		foreach ( $metrics as $key => $title ) {
			$series = array( array( 'label' => $a_name, 'color' => self::C_A, 'values' => $a[ $key ] ) );
			if ( $b ) {
				$series[] = array( 'label' => $b_name, 'color' => $b_color, 'values' => $b[ $key ], 'dash' => $b_dash );
			}
			$delta = $b ? self::pct_delta( array_sum( $a[ $key ] ), array_sum( $b[ $key ] ) ) : null;
			echo '<div class="stm-multiple">';
			echo '<h3>' . esc_html( $title );
			if ( null !== $delta ) {
				echo ' <span class="stm-delta">' . esc_html( $this->delta_text( $delta ) ) . '</span>';
			}
			echo '</h3>';
			$this->line_chart( $a['labels'], $series, 360, 150 );
			echo '</div>';
		}
		// Average minutes per call as its own multiple (ratio, not a sum).
		$avg_a = array();
		foreach ( $a['calls'] as $i => $n ) {
			$avg_a[] = $n ? round( $a['minutes'][ $i ] / $n, 1 ) : 0;
		}
		$series = array( array( 'label' => $a_name, 'color' => self::C_A, 'values' => $avg_a ) );
		if ( $b ) {
			$avg_b = array();
			foreach ( $b['calls'] as $i => $n ) {
				$avg_b[] = $n ? round( $b['minutes'][ $i ] / $n, 1 ) : 0;
			}
			$series[] = array( 'label' => $b_name, 'color' => $b_color, 'values' => $avg_b, 'dash' => $b_dash );
		}
		echo '<div class="stm-multiple"><h3>' . esc_html__( 'Gem. minuten per gesprek', 'stralend-team-monitor' ) . '</h3>';
		$this->line_chart( $a['labels'], $series, 360, 150 );
		echo '</div>';
		echo '</div>';

		$table_series = array( array( 'label' => $a_name . ' ✉', 'values' => $a['emails'] ), array( 'label' => $a_name . ' ☎', 'values' => $a['calls'] ), array( 'label' => $a_name . ' min', 'values' => $a['minutes'] ) );
		if ( $b ) {
			$table_series[] = array( 'label' => $b_name . ' ✉', 'values' => $b['emails'] );
			$table_series[] = array( 'label' => $b_name . ' ☎', 'values' => $b['calls'] );
			$table_series[] = array( 'label' => $b_name . ' min', 'values' => $b['minutes'] );
		}
		$this->series_table( __( 'Tabel: weekcijfers', 'stralend-team-monitor' ), $a['labels'], $table_series );
	}

	/** Weekday × hour average-interactions heatmap. */
	private function render_heatmap_section( $from, $to, $employee_id, $emp_name ) {
		$grid = $this->heatmap_data( $from, $to, $employee_id );
		$occ  = $this->weekday_occurrences( $from, $to );

		$title = ( '' === $employee_id )
			? __( 'Drukte-patroon — gemiddeld aantal interacties per uur (team)', 'stralend-team-monitor' )
			: sprintf( __( 'Drukte-patroon — gemiddeld aantal interacties per uur (%s)', 'stralend-team-monitor' ), $emp_name );
		echo '<h2>' . esc_html( $title ) . '</h2>';

		if ( empty( $grid ) ) {
			echo '<p class="description">' . esc_html__( 'Geen data in deze periode.', 'stralend-team-monitor' ) . '</p>';
			return;
		}

		// Hour span: default working window, expanded only for hours that carry
		// meaningful volume (avg >= 0.5/day) — stray night emails would otherwise
		// stretch the grid to 0–23 with near-zero cells.
		$h_min = 8;
		$h_max = 18;
		for ( $wd = 0; $wd < 7; $wd++ ) {
			if ( empty( $occ[ $wd ] ) || empty( $grid[ $wd ] ) ) {
				continue;
			}
			foreach ( $grid[ $wd ] as $h => $c ) {
				if ( $c / $occ[ $wd ] >= 0.5 ) {
					$h_min = min( $h_min, $h );
					$h_max = max( $h_max, $h );
				}
			}
		}

		// Averages + scale max.
		$avg  = array();
		$vmax = 0;
		for ( $wd = 0; $wd < 7; $wd++ ) {
			if ( empty( $occ[ $wd ] ) ) {
				continue;
			}
			for ( $h = $h_min; $h <= $h_max; $h++ ) {
				$v = isset( $grid[ $wd ][ $h ] ) ? $grid[ $wd ][ $h ] / $occ[ $wd ] : 0;
				$avg[ $wd ][ $h ] = $v;
				$vmax             = max( $vmax, $v );
			}
		}

		$daynames = array( __( 'ma', 'stralend-team-monitor' ), __( 'di', 'stralend-team-monitor' ), __( 'wo', 'stralend-team-monitor' ), __( 'do', 'stralend-team-monitor' ), __( 'vr', 'stralend-team-monitor' ), __( 'za', 'stralend-team-monitor' ), __( 'zo', 'stralend-team-monitor' ) );

		echo '<div class="stm-scroll"><table class="stm-heatmap"><thead><tr><th></th>';
		for ( $h = $h_min; $h <= $h_max; $h++ ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		for ( $wd = 0; $wd < 7; $wd++ ) {
			if ( ! isset( $avg[ $wd ] ) ) {
				continue;
			}
			$has_data = array_sum( $avg[ $wd ] ) > 0;
			if ( $wd >= 5 && ! $has_data ) {
				continue; // hide empty weekend rows
			}
			echo '<tr><th>' . esc_html( $daynames[ $wd ] ) . '</th>';
			for ( $h = $h_min; $h <= $h_max; $h++ ) {
				$v = $avg[ $wd ][ $h ];
				if ( $v <= 0 ) {
					echo '<td class="stm-hm-zero" title="0">·</td>';
					continue;
				}
				$idx  = (int) min( count( self::SEQ ) - 1, floor( $v / $vmax * count( self::SEQ ) ) );
				$bg   = self::SEQ[ $idx ];
				$ink  = ( $idx >= 3 ) ? '#ffffff' : self::C_INK;
				$txt  = ( $v >= 10 ) ? (string) round( $v ) : number_format_i18n( round( $v, 1 ), 1 );
				$tip  = sprintf( '%s %02d:00 — gem. %s interacties', $daynames[ $wd ], $h, $txt );
				echo '<td style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $ink ) . '" title="' . esc_attr( $tip ) . '">' . esc_html( $txt ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="description">' . esc_html__( 'Gemiddeld per voorkomen van die weekdag in de gekozen periode. Donkerder = drukker.', 'stralend-team-monitor' ) . '</p>';
	}

	/* ------------------------------------------------------------------ *
	 * Chart primitives (inline SVG)
	 * ------------------------------------------------------------------ */

	private function legend( $series ) {
		echo '<p class="stm-legend">';
		foreach ( $series as $s ) {
			echo '<span class="stm-legend-item"><span class="stm-chip" style="background:' . esc_attr( $s['color'] ) . '"></span>' . esc_html( $s['label'] ) . '</span>';
		}
		echo '</p>';
	}

	/**
	 * Multi-series line chart. Series: [ label, color, values[], dash? ].
	 * All series share the x buckets of $labels; shorter series are padded.
	 */
	private function line_chart( $labels, $series, $w, $h ) {
		$n = count( $labels );
		if ( $n < 2 ) {
			echo '<p class="description">' . esc_html__( 'Te weinig punten voor een grafiek.', 'stralend-team-monitor' ) . '</p>';
			return;
		}
		$ml = 40; $mr = 12; $mt = 10; $mb = 24;
		$iw = $w - $ml - $mr;
		$ih = $h - $mt - $mb;

		$vmax = 1;
		foreach ( $series as $s ) {
			foreach ( $s['values'] as $v ) {
				$vmax = max( $vmax, $v );
			}
		}
		$vmax = $this->nice_ceil( $vmax );

		$x = function ( $i ) use ( $ml, $iw, $n ) {
			return $ml + ( $n > 1 ? $i * $iw / ( $n - 1 ) : 0 );
		};
		$y = function ( $v ) use ( $mt, $ih, $vmax ) {
			return $mt + $ih - ( $v / $vmax * $ih );
		};

		// The full sizing lives inline: width 100% of the container but hard-capped
		// at the design width. Inline max-width beats any (possibly stale-cached)
		// stylesheet rule, so charts can never blow up to viewport size again.
		$svg = '<svg class="stm-chart" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" width="' . (int) $w . '" height="' . (int) $h . '" style="display:block;width:100%;max-width:' . (int) $w . 'px;height:auto" role="img" preserveAspectRatio="xMidYMid meet">';

		// gridlines + y ticks (4 steps, one axis).
		for ( $t = 0; $t <= 4; $t++ ) {
			$val = $vmax * $t / 4;
			$yy  = $y( $val );
			$svg .= sprintf( '<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="%s" stroke-width="1"/>', $ml, $yy, $w - $mr, $yy, ( 0 === $t ) ? self::C_BASE : self::C_GRID );
			$svg .= sprintf( '<text x="%d" y="%.1f" text-anchor="end" class="stm-tick">%s</text>', $ml - 6, $yy + 3.5, esc_html( $this->fmt_tick( $val ) ) );
		}

		// x labels: at most ~8; edge labels anchored inward so they never clip.
		$step = max( 1, (int) ceil( $n / 8 ) );
		for ( $i = 0; $i < $n; $i += $step ) {
			$anchor = 'middle';
			if ( 0 === $i ) {
				$anchor = 'start';
			} elseif ( $i >= $n - 1 ) {
				$anchor = 'end';
			}
			$svg .= sprintf( '<text x="%.1f" y="%d" text-anchor="%s" class="stm-tick">%s</text>', $x( $i ), $h - 6, $anchor, esc_html( $labels[ $i ] ) );
		}

		// series lines (context first so the accent draws on top).
		$ordered = $series;
		usort( $ordered, static function ( $a, $b ) {
			return ( empty( $a['dash'] ) ? 1 : 0 ) - ( empty( $b['dash'] ) ? 1 : 0 );
		} );
		foreach ( $ordered as $s ) {
			$pts = array();
			foreach ( $labels as $i => $lbl ) {
				$v     = isset( $s['values'][ $i ] ) ? $s['values'][ $i ] : 0;
				$pts[] = sprintf( '%.1f,%.1f', $x( $i ), $y( $v ) );
			}
			$dash = ! empty( $s['dash'] ) ? ' stroke-dasharray="5 4"' : '';
			$svg .= '<polyline fill="none" stroke="' . esc_attr( $s['color'] ) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"' . $dash . ' points="' . esc_attr( implode( ' ', $pts ) ) . '"/>';
			// end marker on the accent series.
			$last_i = $n - 1;
			$last_v = isset( $s['values'][ $last_i ] ) ? $s['values'][ $last_i ] : 0;
			$svg   .= sprintf( '<circle cx="%.1f" cy="%.1f" r="4" fill="%s"/>', $x( $last_i ), $y( $last_v ), esc_attr( $s['color'] ) );
		}

		// hover layer: generous hit columns with native tooltips.
		$colw = $iw / max( 1, $n - 1 );
		foreach ( $labels as $i => $lbl ) {
			$tip = $lbl;
			foreach ( $series as $s ) {
				$v    = isset( $s['values'][ $i ] ) ? $s['values'][ $i ] : 0;
				$tip .= ' · ' . $s['label'] . ': ' . $this->fmt_tick( $v );
			}
			$svg .= sprintf(
				'<rect class="stm-hit" x="%.1f" y="%d" width="%.1f" height="%d" fill="transparent"><title>%s</title></rect>',
				$x( $i ) - $colw / 2, $mt, $colw, $ih, esc_html( $tip )
			);
		}

		$svg .= '</svg>';
		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above with escaped parts.
	}

	private function nice_ceil( $v ) {
		if ( $v <= 4 ) {
			return 4;
		}
		$mag  = pow( 10, floor( log10( $v ) ) );
		$norm = $v / $mag;
		foreach ( array( 1, 2, 4, 5, 8, 10 ) as $step ) {
			if ( $norm <= $step ) {
				return $step * $mag;
			}
		}
		return 10 * $mag;
	}

	private function fmt_tick( $v ) {
		if ( $v >= 1000 ) {
			return number_format_i18n( round( $v / 100 ) / 10, 1 ) . 'k';
		}
		return ( floor( $v ) == $v ) ? number_format_i18n( $v ) : number_format_i18n( $v, 1 );
	}

	/** Accessible numbers behind every chart. */
	private function series_table( $summary, $labels, $series ) {
		echo '<details class="stm-table-details"><summary>' . esc_html( $summary ) . '</summary>';
		echo '<div class="stm-scroll"><table class="widefat striped"><thead><tr><th></th>';
		foreach ( $labels as $l ) {
			echo '<th>' . esc_html( $l ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $series as $s ) {
			echo '<tr><td class="stm-name">' . esc_html( $s['label'] ) . '</td>';
			foreach ( $labels as $i => $l ) {
				$v = isset( $s['values'][ $i ] ) ? $s['values'][ $i ] : 0;
				echo '<td>' . esc_html( $this->fmt_tick( $v ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div></details>';
	}
}
