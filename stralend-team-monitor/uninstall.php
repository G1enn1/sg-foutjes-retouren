<?php
/**
 * Runs when the plugin is deleted from wp-admin. Removes the table and options.
 * (Deactivation keeps data; only a full delete cleans up.)
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$table = $wpdb->prefix . 'stm_interactions';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

foreach ( array(
	'stm_employees',
	'stm_category_weights',
	'stm_hubspot_token',
	'stm_webhook_secret',
	'stm_db_version',
	'stm_last_sync',
) as $option ) {
	delete_option( $option );
}

// Remove the access capability from every user that holds it (targeted query —
// don't iterate the full user table of a shop with thousands of customers).
foreach ( get_users( array( 'capability' => 'stm_access', 'fields' => 'ID' ) ) as $uid ) {
	$user = new WP_User( (int) $uid );
	$user->remove_cap( 'stm_access' );
}
