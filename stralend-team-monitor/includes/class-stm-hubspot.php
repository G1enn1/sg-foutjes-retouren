<?php
/**
 * HubSpot CRM client — sent emails per owner (employee) per day.
 *
 * Uses a Private App token (Settings). The marketing connector cannot do this;
 * per-owner email activity is CRM engagement data behind the CRM API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STM_HubSpot {

	private function token() {
		return STM_Settings::hubspot_token();
	}

	/**
	 * API host, derived from the token's region prefix. Private App tokens are
	 * "pat-<region>-..." (e.g. pat-eu1 for EU-hosted portals like ours), and the
	 * matching API host is api-<region>.hubapi.com.
	 */
	private function base_url() {
		if ( preg_match( '/^pat-([a-z]{2}\d+)-/', $this->token(), $m ) && 'na1' !== $m[1] ) {
			return 'https://api-' . $m[1] . '.hubapi.com';
		}
		return 'https://api.hubapi.com';
	}

	private function ok() {
		return '' !== $this->token();
	}

	/**
	 * Fetch email engagements in [start,end] (Y-m-d) and store them.
	 *
	 * @return array { inserted, fetched, error? }
	 */
	public function sync_emails( $start, $end ) {
		if ( ! $this->ok() ) {
			return array( 'error' => 'no_token' );
		}

		$owner_index = STM_Settings::owner_index();
		$start_ms    = strtotime( $start . ' 00:00:00' ) * 1000;
		$end_ms      = strtotime( $end . ' 23:59:59' ) * 1000;

		$after     = null;
		$inserted  = 0;
		$fetched   = 0;
		$guard     = 0;

		do {
			$body = array(
				'filterGroups' => array( array( 'filters' => array( array(
					'propertyName' => 'hs_timestamp',
					'operator'     => 'BETWEEN',
					'value'        => (string) $start_ms,
					'highValue'    => (string) $end_ms,
				) ) ) ),
				'properties' => array( 'hs_timestamp', 'hubspot_owner_id', 'hs_email_direction' ),
				'limit'      => 100,
				'sorts'      => array( array( 'propertyName' => 'hs_timestamp', 'direction' => 'ASCENDING' ) ),
			);
			if ( $after ) {
				$body['after'] = $after;
			}

			$resp = wp_remote_post( $this->base_url() . '/crm/v3/objects/emails/search', array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->token(),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			) );

			if ( is_wp_error( $resp ) ) {
				return array( 'error' => $resp->get_error_message(), 'inserted' => $inserted, 'fetched' => $fetched );
			}
			$code = wp_remote_retrieve_response_code( $resp );
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( 200 !== (int) $code ) {
				$msg = isset( $data['message'] ) ? $data['message'] : ( 'HTTP ' . $code );
				return array( 'error' => $msg, 'inserted' => $inserted, 'fetched' => $fetched );
			}

			foreach ( (array) ( $data['results'] ?? array() ) as $obj ) {
				$fetched++;
				$props    = $obj['properties'] ?? array();
				$owner_id = (string) ( $props['hubspot_owner_id'] ?? '' );
				if ( '' === $owner_id || ! isset( $owner_index[ $owner_id ] ) ) {
					continue;
				}
				$employee  = $owner_index[ $owner_id ];
				$dir_raw   = strtoupper( (string) ( $props['hs_email_direction'] ?? '' ) );
				$direction = ( false !== strpos( $dir_raw, 'INCOMING' ) ) ? 'inbound' : 'outbound';
				$ts_ms     = (int) ( $props['hs_timestamp'] ?? 0 );
				$ts        = $ts_ms ? gmdate( 'Y-m-d H:i:s', (int) ( $ts_ms / 1000 ) ) : current_time( 'mysql' );

				$inserted += STM_DB::insert( array(
					'event_ts'    => $ts,
					'employee_id' => $employee['id'],
					'channel'     => 'email',
					'direction'   => $direction,
					'source'      => 'hubspot',
					'dedup_seed'  => 'hs_email_' . ( $obj['id'] ?? ( $owner_id . $ts ) ),
				) );
			}

			$after = $data['paging']['next']['after'] ?? null;
			$guard++;
		} while ( $after && $guard < 200 );

		return array( 'inserted' => $inserted, 'fetched' => $fetched );
	}

	/**
	 * List CRM owners: id => {email, firstName, lastName}.
	 */
	public function owners() {
		if ( ! $this->ok() ) {
			return array();
		}
		$out   = array();
		$after = null;
		$guard = 0;
		do {
			$url = $this->base_url() . '/crm/v3/owners?limit=100' . ( $after ? '&after=' . rawurlencode( $after ) : '' );
			$resp = wp_remote_get( $url, array(
				'headers' => array( 'Authorization' => 'Bearer ' . $this->token() ),
				'timeout' => 30,
			) );
			if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
				break;
			}
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			foreach ( (array) ( $data['results'] ?? array() ) as $o ) {
				$out[ (string) $o['id'] ] = $o;
			}
			$after = $data['paging']['next']['after'] ?? null;
			$guard++;
		} while ( $after && $guard < 50 );
		return $out;
	}

	/**
	 * Auto-fill hubspot_owner_id on employees by matching their email to an owner.
	 *
	 * @return int number of employees matched/updated.
	 */
	public function sync_owner_ids() {
		$owners = $this->owners();
		if ( empty( $owners ) ) {
			return 0;
		}
		$by_email = array();
		foreach ( $owners as $id => $o ) {
			if ( ! empty( $o['email'] ) ) {
				$by_email[ strtolower( $o['email'] ) ] = (string) $id;
			}
		}
		$employees = STM_Settings::employees();
		$matched   = 0;
		foreach ( $employees as &$e ) {
			$mail = strtolower( (string) ( $e['email'] ?? '' ) );
			if ( $mail && isset( $by_email[ $mail ] ) && empty( $e['hubspot_owner_id'] ) ) {
				$e['hubspot_owner_id'] = $by_email[ $mail ];
				$matched++;
			}
		}
		unset( $e );
		if ( $matched ) {
			update_option( 'stm_employees', $employees );
		}
		return $matched;
	}
}
