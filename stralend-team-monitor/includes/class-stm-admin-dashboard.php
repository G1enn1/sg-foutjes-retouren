<?php
/**
 * The wp-admin dashboard: per-employee-per-day e-mail & call figures, plus
 * difficulty/quality columns. Also hosts the manual sync + CSV export actions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STM_Admin_Dashboard {

	const ACCENT   = '#2f8f5b';
	const ACCENT_2 = '#3b6ea5';

	public function register_actions() {
		add_action( 'admin_post_stm_sync_hubspot', array( $this, 'action_sync_hubspot' ) );
		add_action( 'admin_post_stm_sync_owners', array( $this, 'action_sync_owners' ) );
		add_action( 'admin_post_stm_export_csv', array( $this, 'action_export_csv' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Actions
	 * ------------------------------------------------------------------ */

	public function action_sync_hubspot() {
		$this->guard( 'stm_sync_hubspot' );
		list( $start, $end ) = $this->range_from_referer();
		$result = ( new STM_HubSpot() )->sync_emails( $start, $end );
		$this->redirect_with_notice( isset( $result['error'] )
			? array( 'e' => $result['error'] )
			: array( 'm' => sprintf( '%d e-mails opgehaald, %d nieuw opgeslagen.', $result['fetched'] ?? 0, $result['inserted'] ?? 0 ) ) );
	}

	public function action_sync_owners() {
		$this->guard( 'stm_sync_owners' );
		$n = ( new STM_HubSpot() )->sync_owner_ids();
		$this->redirect_with_notice( array( 'm' => sprintf( '%d medewerkers gekoppeld aan een HubSpot owner-id.', $n ) ) );
	}

	public function action_export_csv() {
		$this->guard( 'stm_export_csv' );
		list( $start, $end ) = $this->range_from_referer();
		$daily = STM_Metrics::aggregate( STM_DB::fetch( $start, $end ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="team-monitor-' . $start . '_' . $end . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'datum', 'medewerker', 'emails_verzonden', 'telefoontjes', 'belminuten',
			'gem_belminuten', 'escalaties', 'fcr_ratio', 'gem_csat', 'tickets' ) );
		foreach ( $daily as $s ) {
			fputcsv( $out, array(
				$s['date'], $s['employee_name'], $s['emails_sent'], $s['calls_handled'],
				STM_Metrics::call_minutes( $s ), STM_Metrics::avg_call_minutes( $s ),
				$s['escalations'], STM_Metrics::fcr_rate( $s ), STM_Metrics::avg_csat( $s ), $s['ticket_count'],
			) );
		}
		fclose( $out );
		exit;
	}

	private function guard( $action ) {
		if ( ! STM_Settings::can_access() ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot Team Monitor.', 'stralend-team-monitor' ) );
		}
		check_admin_referer( $action );
	}

	private function range_from_referer() {
		$ref = wp_get_referer();
		$from = '';
		$to   = '';
		if ( $ref ) {
			$q = wp_parse_url( $ref, PHP_URL_QUERY );
			if ( $q ) {
				parse_str( $q, $args );
				$from = isset( $args['from'] ) ? sanitize_text_field( $args['from'] ) : '';
				$to   = isset( $args['to'] ) ? sanitize_text_field( $args['to'] ) : '';
			}
		}
		return $this->normalize_range( $from, $to );
	}

	private function normalize_range( $from, $to ) {
		$valid = function ( $d ) {
			return $d && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
		};
		if ( ! $valid( $to ) ) {
			$to = current_time( 'Y-m-d' );
		}
		if ( ! $valid( $from ) ) {
			$from = gmdate( 'Y-m-d', strtotime( $to . ' -13 days' ) );
		}
		return array( $from, $to );
	}

	private function redirect_with_notice( $args ) {
		list( $from, $to ) = $this->range_from_referer();
		wp_safe_redirect( add_query_arg(
			array_merge( array( 'page' => STM_SLUG, 'from' => $from, 'to' => $to ), $args ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/* ------------------------------------------------------------------ *
	 * Render
	 * ------------------------------------------------------------------ */

	public function render_page() {
		if ( ! STM_Settings::can_access() ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot Team Monitor.', 'stralend-team-monitor' ) );
		}
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		list( $from, $to ) = $this->normalize_range( $from, $to );

		$rows   = STM_DB::fetch( $from, $to );
		$daily  = STM_Metrics::aggregate( $rows );
		$totals = STM_Metrics::employee_totals( $daily );
		$days   = $this->distinct_days( $daily );

		$max_emails  = 1;
		$max_minutes = 1;
		foreach ( $totals as $t ) {
			$max_emails  = max( $max_emails, $t['emails_sent'] );
			$max_minutes = max( $max_minutes, STM_Metrics::call_minutes( $t ) );
		}
		$last_sync = get_option( 'stm_last_sync' );
		?>
		<div class="wrap stm-dashboard">
			<h1><?php esc_html_e( 'Team Monitor', 'stralend-team-monitor' ); ?></h1>

			<?php $this->notices(); ?>

			<form method="get" class="stm-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( STM_SLUG ); ?>" />
				<label><?php esc_html_e( 'Van', 'stralend-team-monitor' ); ?>
					<input type="date" name="from" value="<?php echo esc_attr( $from ); ?>" /></label>
				<label><?php esc_html_e( 'Tot', 'stralend-team-monitor' ); ?>
					<input type="date" name="to" value="<?php echo esc_attr( $to ); ?>" /></label>
				<?php submit_button( __( 'Toon', 'stralend-team-monitor' ), 'secondary', '', false ); ?>
			</form>

			<p class="stm-actions">
				<?php echo $this->action_button( 'stm_sync_hubspot', __( 'HubSpot nu synchroniseren', 'stralend-team-monitor' ), $from, $to ); ?>
				<?php echo $this->action_button( 'stm_sync_owners', __( 'HubSpot owner-ids koppelen', 'stralend-team-monitor' ), $from, $to ); ?>
				<?php echo $this->action_button( 'stm_export_csv', __( 'Exporteer CSV', 'stralend-team-monitor' ), $from, $to, 'button' ); ?>
			</p>

			<?php if ( empty( $daily ) ) : ?>
				<div class="notice notice-info inline"><p>
					<?php esc_html_e( 'Nog geen data in deze periode. Importeer een Voys-export, zet de webhook aan, of synchroniseer HubSpot.', 'stralend-team-monitor' ); ?>
				</p></div>
			<?php else : ?>
				<?php $this->render_summary( $totals ); ?>
				<div class="stm-grid">
					<div><h2><?php esc_html_e( 'E-mails verzonden (totaal)', 'stralend-team-monitor' ); ?></h2>
						<?php $this->render_bars( $totals, 'emails', $max_emails, self::ACCENT ); ?></div>
					<div><h2><?php esc_html_e( 'Belminuten (totaal)', 'stralend-team-monitor' ); ?></h2>
						<?php $this->render_bars( $totals, 'minutes', $max_minutes, self::ACCENT_2 ); ?></div>
				</div>
				<h2><?php esc_html_e( 'Per dag — e-mails ✉ / telefoontjes ☎', 'stralend-team-monitor' ); ?></h2>
				<?php $this->render_matrix( $daily, $days ); ?>
			<?php endif; ?>

			<p class="description" style="margin-top:24px">
				<?php
				if ( is_array( $last_sync ) && ! empty( $last_sync['time'] ) ) {
					echo esc_html( sprintf( __( 'Laatste HubSpot-sync: %s.', 'stralend-team-monitor' ), $last_sync['time'] ) ) . ' ';
				}
				echo esc_html( sprintf( __( 'Totaal %d interacties opgeslagen.', 'stralend-team-monitor' ), STM_DB::count() ) );
				?>
			</p>
		</div>
		<?php
	}

	private function notices() {
		if ( isset( $_GET['m'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( wp_unslash( $_GET['m'] ) ) . '</p></div>';
		}
		if ( isset( $_GET['e'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( wp_unslash( $_GET['e'] ) ) . '</p></div>';
		}
	}

	private function action_button( $action, $label, $from, $to, $class = 'button button-primary' ) {
		$url = wp_nonce_url(
			add_query_arg(
				array( 'action' => $action, 'from' => $from, 'to' => $to ),
				admin_url( 'admin-post.php' )
			),
			$action
		);
		return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
	}

	private function render_summary( $totals ) {
		echo '<table class="widefat striped stm-summary"><thead><tr>';
		foreach ( array( 'Medewerker', 'E-mails', 'Telefoontjes', 'Belmin.', 'Gem/gesprek',
			'Zwaarte', 'Touches/ticket', 'Escalatie', 'FCR', 'CSAT' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $totals as $t ) {
			$esc = STM_Metrics::escalation_rate( $t );
			$fcr = STM_Metrics::fcr_rate( $t );
			echo '<tr>';
			echo '<td class="stm-name">' . esc_html( $t['employee_name'] ) . '</td>';
			echo '<td>' . (int) $t['emails_sent'] . '</td>';
			echo '<td>' . (int) $t['calls_handled'] . '</td>';
			echo '<td>' . esc_html( STM_Metrics::call_minutes( $t ) ) . '</td>';
			echo '<td>' . esc_html( STM_Metrics::avg_call_minutes( $t ) ) . '</td>';
			echo '<td>' . esc_html( $this->dash( STM_Metrics::weighted_difficulty( $t ) ) ) . '</td>';
			echo '<td>' . esc_html( $this->dash( STM_Metrics::avg_touches_per_ticket( $t ) ) ) . '</td>';
			echo '<td>' . esc_html( null === $esc ? '—' : round( $esc * 100, 1 ) . '%' ) . '</td>';
			echo '<td>' . esc_html( null === $fcr ? '—' : round( $fcr * 100, 1 ) . '%' ) . '</td>';
			echo '<td>' . esc_html( $this->dash( STM_Metrics::avg_csat( $t ) ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Zwaarte = gewogen complexiteit van categorieën. Touches/ticket, escalatie en FCR zeggen iets over moeilijkheid en kwaliteit — niet het pure volume.', 'stralend-team-monitor' ) . '</p>';
	}

	private function render_bars( $totals, $metric, $max, $color ) {
		echo '<div class="stm-bars">';
		foreach ( $totals as $t ) {
			$value = ( 'emails' === $metric ) ? (int) $t['emails_sent'] : STM_Metrics::call_minutes( $t );
			$pct   = $max > 0 ? max( 2, round( $value / $max * 100, 1 ) ) : 0;
			echo '<div class="stm-bar-row">';
			echo '<span class="stm-bar-label">' . esc_html( $t['employee_name'] ) . '</span>';
			echo '<span class="stm-bar-track"><span class="stm-bar-fill" style="width:' . esc_attr( $pct ) . '%;background:' . esc_attr( $color ) . '"></span></span>';
			echo '<span class="stm-bar-val">' . esc_html( $value ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
	}

	private function render_matrix( $daily, $days ) {
		$by_emp = array();
		$names  = array();
		foreach ( $daily as $s ) {
			$by_emp[ $s['employee_id'] ][ $s['date'] ] = $s;
			$names[ $s['employee_id'] ] = $s['employee_name'];
		}
		asort( $names );

		echo '<div class="stm-scroll"><table class="widefat stm-matrix"><thead><tr><th>' . esc_html__( 'Medewerker', 'stralend-team-monitor' ) . '</th>';
		foreach ( $days as $d ) {
			echo '<th>' . esc_html( gmdate( 'd-m', strtotime( $d ) ) ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $names as $id => $name ) {
			echo '<tr><td class="stm-name">' . esc_html( $name ) . '</td>';
			foreach ( $days as $d ) {
				if ( isset( $by_emp[ $id ][ $d ] ) ) {
					$s = $by_emp[ $id ][ $d ];
					$title = esc_attr( STM_Metrics::call_minutes( $s ) . ' belmin' );
					echo '<td title="' . $title . '">' . (int) $s['emails_sent'] . '✉ / ' . (int) $s['calls_handled'] . '☎</td>';
				} else {
					echo '<td class="stm-empty">·</td>';
				}
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private function distinct_days( $daily ) {
		$days = array();
		foreach ( $daily as $s ) {
			$days[ $s['date'] ] = true;
		}
		$days = array_keys( $days );
		sort( $days );
		return $days;
	}

	private function dash( $v ) {
		return ( null === $v ) ? '—' : $v;
	}
}
