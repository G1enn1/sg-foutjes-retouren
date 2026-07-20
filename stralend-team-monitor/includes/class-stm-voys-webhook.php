<?php
/**
 * Voys Freedom "Gespreksnotificaties" receiver.
 *
 * Registers wp-json/team-monitor/v1/voys. Voys posts one notification per call;
 * we attribute it to an employee via the 2xx extension / name and store it.
 * Protected by a shared secret (query ?key= or X-STM-Key header).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STM_Voys_Webhook {

	public function register_routes() {
		register_rest_route(
			'team-monitor/v1',
			'/voys',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'check_secret' ),
			)
		);
	}

	/** Constant-time comparison of the shared secret. */
	public function check_secret( WP_REST_Request $request ) {
		$expected = STM_Settings::webhook_secret();
		if ( '' === $expected ) {
			return false;
		}
		$given = $request->get_param( 'key' );
		if ( ! $given ) {
			$given = $request->get_header( 'x-stm-key' );
		}
		return is_string( $given ) && hash_equals( $expected, $given );
	}

	public function handle( WP_REST_Request $request ) {
		// GET is used by Voys to validate the URL — just acknowledge.
		if ( 'GET' === $request->get_method() ) {
			return new WP_REST_Response( 'ACK', 200 );
		}

		$payload = $request->get_json_params();
		if ( empty( $payload ) ) {
			$payload = $request->get_body_params(); // form-encoded
		}
		if ( empty( $payload ) ) {
			return new WP_REST_Response( 'ACK', 200 ); // nothing to do, but never error the telco
		}

		$interaction = $this->normalize( $payload );
		if ( $interaction ) {
			STM_DB::insert( $interaction );
		}
		return new WP_REST_Response( 'ACK', 200 );
	}

	/**
	 * Map a Voys notification payload to an interaction row.
	 * Voys variable names vary; we read the common ones defensively. Inspect a
	 * real payload (it is logged on parse failure) and extend as needed.
	 *
	 * @return array|null
	 */
	public function normalize( array $p ) {
		$dir_raw = strtolower( (string) ( $p['direction'] ?? $p['richting'] ?? '' ) );
		if ( 0 === strpos( $dir_raw, 'out' ) || 0 === strpos( $dir_raw, 'uit' ) ) {
			$direction = 'outbound';
		} elseif ( 0 === strpos( $dir_raw, 'in' ) ) {
			$direction = 'inbound';
		} else {
			$direction = 'inbound';
		}

		$beller     = (string) ( $p['caller'] ?? $p['callerid'] ?? $p['beller'] ?? $p['bron'] ?? '' );
		$bestemming = (string) ( $p['destination'] ?? $p['did'] ?? $p['bestemming'] ?? '' );
		// Some payloads carry the internal number explicitly:
		$internal   = (string) ( $p['internal_number'] ?? $p['account'] ?? '' );
		if ( '' !== $internal ) {
			$bestemming = ( 'inbound' === $direction ) ? $internal : $bestemming;
			$beller     = ( 'outbound' === $direction ) ? $internal : $beller;
		}

		$employee = STM_Metrics::resolve_call( $beller, $bestemming, $direction );
		if ( ! $employee ) {
			return null; // external-to-external or unmapped extension
		}

		$duration = (int) round( (float) ( $p['duration'] ?? $p['talk_time'] ?? $p['duur'] ?? 0 ) );
		$ts       = $this->parse_ts( $p['timestamp'] ?? $p['start'] ?? $p['datum'] ?? '' );

		// Prefer a call id for dedup so repeated deliveries do not double count.
		$call_id = (string) ( $p['call_id'] ?? $p['callid'] ?? $p['uniqueid'] ?? '' );

		return array(
			'event_ts'         => $ts,
			'employee_id'      => $employee['id'],
			'channel'          => 'call',
			'direction'        => $direction,
			'duration_seconds' => $duration,
			'source'           => 'voys_webhook',
			'dedup_seed'       => '' !== $call_id ? 'voys_call_' . $call_id : '',
		);
	}

	private function parse_ts( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return current_time( 'mysql' );
		}
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : current_time( 'mysql' );
	}
}
