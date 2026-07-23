<?php
/**
 * Daily background sync: pull recent HubSpot emails into the local store.
 * Calls are captured live by the webhook, so cron only needs HubSpot.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STM_Cron {

	public function hook() {
		add_action( STM_Activator::CRON_HOOK, array( $this, 'run' ) );
	}

	/**
	 * Sync the last few days (overlap covers late-logged emails; INSERT IGNORE
	 * makes re-fetching harmless).
	 */
	public function run() {
		$end   = current_time( 'Y-m-d' );
		$start = gmdate( 'Y-m-d', strtotime( $end . ' -3 days' ) );

		// Background run, but still bounded: WP-cron often piggybacks on a
		// visitor request, so it must not run for minutes either.
		$result = ( new STM_HubSpot() )->sync_emails( $start, $end, time() + 90 );
		$wa     = ( new STM_WhatsApp() )->sync( $start, $end, time() + 180 );
		update_option( 'stm_last_sync', array(
			'time'   => current_time( 'mysql' ),
			'result' => $result,
			'wa'     => $wa,
		) );
	}
}
