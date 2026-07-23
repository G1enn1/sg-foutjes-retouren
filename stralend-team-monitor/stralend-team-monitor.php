<?php
/**
 * Plugin Name:       Stralend Team Monitor
 * Plugin URI:        https://stralendgroen.nl/
 * Description:        E-mail- (HubSpot) en telefoonstatistieken (Voys Freedom) per medewerker per dag, met moeilijkheids- en progressie-inzichten. Dashboard in wp-admin.
 * Version:           0.4.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Stralend Groen
 * Text Domain:       stralend-team-monitor
 * License:           GPL-2.0-or-later
 *
 * Secrets (HubSpot-token, webhook-secret) worden opgeslagen in WP-opties, of —
 * veiliger — als constante in wp-config.php (STM_HUBSPOT_TOKEN / STM_WEBHOOK_SECRET).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'STM_VERSION', '0.4.0' );
define( 'STM_DB_VERSION', '2' );
define( 'STM_FILE', __FILE__ );
define( 'STM_PATH', plugin_dir_path( __FILE__ ) );
define( 'STM_URL', plugin_dir_url( __FILE__ ) );
define( 'STM_SLUG', 'stralend-team-monitor' );

require_once STM_PATH . 'includes/class-stm-db.php';
require_once STM_PATH . 'includes/class-stm-activator.php';
require_once STM_PATH . 'includes/class-stm-settings.php';
require_once STM_PATH . 'includes/class-stm-metrics.php';
require_once STM_PATH . 'includes/class-stm-voys-webhook.php';
require_once STM_PATH . 'includes/class-stm-voys-import.php';
require_once STM_PATH . 'includes/class-stm-hubspot.php';
require_once STM_PATH . 'includes/class-stm-whatsapp.php';
require_once STM_PATH . 'includes/class-stm-cron.php';
require_once STM_PATH . 'includes/class-stm-admin-dashboard.php';
require_once STM_PATH . 'includes/class-stm-woo.php';
require_once STM_PATH . 'includes/class-stm-trends.php';
require_once STM_PATH . 'includes/class-stm-plugin.php';

register_activation_hook( __FILE__, array( 'STM_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'STM_Activator', 'deactivate' ) );

/**
 * Boot the plugin once WordPress is ready.
 */
function stm_boot() {
	STM_Plugin::instance()->run();
}
add_action( 'plugins_loaded', 'stm_boot' );
