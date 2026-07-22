<?php
/**
 * Activation / deactivation: create the table, seed sensible defaults, and
 * (de)schedule the daily HubSpot sync.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STM_Activator {

	const CRON_HOOK = 'stm_daily_sync';

	public static function activate() {
		STM_DB::create_table();
		update_option( 'stm_db_version', STM_DB_VERSION );

		self::seed_defaults();
		self::grant_default_access();

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// First run tomorrow ~06:30 local, then daily.
			wp_schedule_event( self::next_morning(), 'daily', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/** Timestamp for the next 06:30 in the site's timezone. */
	private static function next_morning() {
		$tz  = wp_timezone();
		$now = new DateTime( 'now', $tz );
		$run = new DateTime( 'today 06:30', $tz );
		if ( $run <= $now ) {
			$run->modify( '+1 day' );
		}
		return $run->getTimestamp();
	}

	/**
	 * Seed the employee mapping (from the Voys user list), category weights and a
	 * webhook secret — only if not already set, so re-activation is non-destructive.
	 */
	private static function seed_defaults() {
		if ( false === get_option( 'stm_employees', false ) ) {
			update_option( 'stm_employees', self::default_employees() );
		}
		if ( false === get_option( 'stm_category_weights', false ) ) {
			update_option( 'stm_category_weights', array(
				'adreswijziging'   => 1.0,
				'retour'           => 1.5,
				'verkeerd geleverd' => 2.0,
				'garantie'         => 3.0,
				'klacht'           => 3.5,
			) );
		}
		if ( ! get_option( 'stm_webhook_secret' ) ) {
			update_option( 'stm_webhook_secret', wp_generate_password( 32, false, false ) );
		}
	}

	/**
	 * Grant plugin access to the person installing it (so they are never locked
	 * out) plus any existing users matching the default access emails.
	 */
	private static function grant_default_access() {
		// set_access() refuses non-administrators, so a customer account with a
		// matching email can never be granted access here.
		$current = wp_get_current_user();
		if ( $current && $current->ID ) {
			STM_Settings::set_access( $current->ID, true );
		}
		foreach ( STM_Settings::DEFAULT_ACCESS_EMAILS as $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				STM_Settings::set_access( $user->ID, true );
			}
		}
	}

	/**
	 * Prefilled from the Voys Freedom user list. `ext` is the 2xx destination
	 * number that appears in the export's Bestemming column ("210/Sandra").
	 */
	public static function default_employees() {
		return array(
			array( 'id' => 'cunera', 'name' => 'Cunera Boomsma',        'email' => 'cunera@stralendgroen.nl',       'ext' => '205', 'hubspot_owner_id' => '' ),
			array( 'id' => 'daniel', 'name' => 'Daniel Korringa',       'email' => 'daniel@stralendgroen.nl',       'ext' => '203', 'hubspot_owner_id' => '' ),
			array( 'id' => 'henk',   'name' => 'Henk Ferbeek',          'email' => 'henk@stralendgroen.nl',         'ext' => '217', 'hubspot_owner_id' => '' ),
			array( 'id' => 'glenn',  'name' => 'Magazijn (Glenn)',      'email' => 'inkoop@stralendgroen.nl',       'ext' => '213', 'hubspot_owner_id' => '' ),
			array( 'id' => 'sandra', 'name' => 'Sandra Kamphuis',       'email' => 'sandra@stralendgroen.nl',       'ext' => '210', 'hubspot_owner_id' => '' ),
			array( 'id' => 'saskia', 'name' => 'Saskia Uittien',        'email' => 'saskia@stralendgroen.nl',       'ext' => '211', 'hubspot_owner_id' => '' ),
			array( 'id' => 'senna',  'name' => 'Senna van der Hooft',   'email' => 'senna@stralendgroen.nl',        'ext' => '212', 'hubspot_owner_id' => '' ),
			array( 'id' => 'tweedehands', 'name' => 'Tweedehands 050',  'email' => 'tweedehands050@gmail.com',       'ext' => '209', 'hubspot_owner_id' => '' ),
		);
	}
}
