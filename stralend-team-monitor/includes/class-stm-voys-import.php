<?php
/**
 * Import a Voys Freedom call-list export (gesprekkenlijst) — CSV.
 *
 * Real export columns: Datum, "Inkomend / Uitgaand", Tijdsduur (seconds),
 * Beller, Bestemming ("2xx/Naam"). Attribution via the 2xx extension/name;
 * outbound calls whose Beller is masked ("x") are reported as unattributed.
 *
 * Excel: export/save as CSV first (Voys offers CSV) — parsing .xlsx in pure PHP
 * without a library is avoided on purpose for reliability.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STM_Voys_Import {

	const COLUMNS = array(
		'datum'      => array( 'Datum', 'Date', 'Starttijd' ),
		'direction'  => array( 'Inkomend / Uitgaand', 'Inkomend/Uitgaand', 'Richting' ),
		'duur'       => array( 'Tijdsduur', 'Duur', 'Gespreksduur', 'Duration' ),
		'beller'     => array( 'Beller', 'Bron', 'Caller' ),
		'bestemming' => array( 'Bestemming', 'Destination' ),
	);

	/** Process an uploaded file (hooked to admin_init). */
	public function maybe_handle_upload() {
		if ( ! isset( $_POST['stm_import_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'stm_import', 'stm_import_nonce' );

		if ( empty( $_FILES['stm_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['stm_file']['tmp_name'] ) ) {
			set_transient( 'stm_import_result', array( 'error' => __( 'Geen bestand ontvangen.', 'stralend-team-monitor' ) ), 60 );
			$this->redirect_back();
		}

		$result = $this->import_csv( $_FILES['stm_file']['tmp_name'] );
		set_transient( 'stm_import_result', $result, 120 );
		$this->redirect_back();
	}

	private function redirect_back() {
		wp_safe_redirect( add_query_arg( array( 'page' => 'stm-import' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @return array { imported, unattributed, total, error? }
	 */
	public function import_csv( $path ) {
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return array( 'error' => __( 'Kon het bestand niet openen.', 'stralend-team-monitor' ) );
		}

		// Detect delimiter from the first line (NL exports are usually ';').
		$first = fgets( $handle );
		$delim = ( substr_count( $first, ';' ) >= substr_count( $first, ',' ) ) ? ';' : ',';
		rewind( $handle );

		$header = fgetcsv( $handle, 0, $delim );
		if ( ! $header ) {
			fclose( $handle );
			return array( 'error' => __( 'Leeg bestand.', 'stralend-team-monitor' ) );
		}
		$header = array_map( function ( $h ) { return trim( (string) $h ); }, $header );
		$col    = $this->map_columns( $header );

		$imported     = 0;
		$unattributed = 0;
		$total        = 0;

		while ( ( $data = fgetcsv( $handle, 0, $delim ) ) !== false ) {
			if ( count( array_filter( $data, 'strlen' ) ) === 0 ) {
				continue;
			}
			$total++;
			$row = array();
			foreach ( $col as $logical => $index ) {
				$row[ $logical ] = ( null !== $index && isset( $data[ $index ] ) ) ? trim( (string) $data[ $index ] ) : '';
			}

			$direction  = $this->direction( $row['direction'] );
			$employee   = STM_Metrics::resolve_call( $row['beller'], $row['bestemming'], $direction );
			if ( ! $employee ) {
				$unattributed++;
				continue;
			}

			$imported += STM_DB::insert( array(
				'event_ts'         => $this->parse_dt( $row['datum'] ),
				'employee_id'      => $employee['id'],
				'channel'          => 'call',
				'direction'        => $direction,
				'duration_seconds' => $this->duration( $row['duur'] ),
				'source'           => 'voys_import',
			) );
		}
		fclose( $handle );

		return array( 'imported' => $imported, 'unattributed' => $unattributed, 'total' => $total );
	}

	private function map_columns( array $header ) {
		$lower = array_map( 'strtolower', $header );
		$col   = array();
		foreach ( self::COLUMNS as $logical => $candidates ) {
			$col[ $logical ] = null;
			foreach ( $candidates as $cand ) {
				$idx = array_search( strtolower( $cand ), $lower, true );
				if ( false !== $idx ) {
					$col[ $logical ] = $idx;
					break;
				}
			}
		}
		return $col;
	}

	private function direction( $value ) {
		$v = strtolower( $value );
		if ( 0 === strpos( $v, 'uit' ) || false !== strpos( $v, 'out' ) ) {
			return 'outbound';
		}
		if ( 0 === strpos( $v, 'in' ) ) {
			return 'inbound';
		}
		return 'internal';
	}

	/** "00:04:12" -> 252, "122" -> 122. */
	private function duration( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( false !== strpos( $value, ':' ) ) {
			$seconds = 0;
			foreach ( explode( ':', $value ) as $part ) {
				$seconds = $seconds * 60 + (int) $part;
			}
			return $seconds;
		}
		return (int) round( (float) str_replace( ',', '.', $value ) );
	}

	private function parse_dt( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return current_time( 'mysql' );
		}
		// Try common Voys formats first (dd-mm-yyyy hh:mm[:ss] and ISO).
		foreach ( array( 'd-m-Y H:i:s', 'd-m-Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y', 'Y-m-d' ) as $fmt ) {
			$dt = DateTime::createFromFormat( $fmt, $value );
			if ( $dt && $dt->format( $fmt ) === $value ) {
				return $dt->format( 'Y-m-d H:i:s' );
			}
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : current_time( 'mysql' );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$result = get_transient( 'stm_import_result' );
		delete_transient( 'stm_import_result' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Team Monitor — Voys-import', 'stralend-team-monitor' ); ?></h1>

			<?php if ( is_array( $result ) ) : ?>
				<?php if ( isset( $result['error'] ) ) : ?>
					<div class="notice notice-error"><p><?php echo esc_html( $result['error'] ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-success"><p>
						<?php echo esc_html( sprintf(
							/* translators: 1: imported, 2: unattributed, 3: total */
							__( '%1$d gesprekken geïmporteerd, %2$d niet-toegewezen (van %3$d regels).', 'stralend-team-monitor' ),
							$result['imported'], $result['unattributed'], $result['total']
						) ); ?>
					</p></div>
				<?php endif; ?>
			<?php endif; ?>

			<p><?php esc_html_e( 'Upload een Voys Freedom gesprekkenlijst-export (CSV). Gebruik dit voor historische data; live gesprekken komen via de webhook binnen.', 'stralend-team-monitor' ); ?></p>
			<form method="post" enctype="multipart/form-data" action="">
				<?php wp_nonce_field( 'stm_import', 'stm_import_nonce' ); ?>
				<input type="file" name="stm_file" accept=".csv,text/csv" required />
				<?php submit_button( __( 'Importeren', 'stralend-team-monitor' ) ); ?>
			</form>
		</div>
		<?php
	}
}
