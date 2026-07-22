<?php
/**
 * Orchestrator: instantiates components and registers all WordPress hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STM_Plugin {

	private static $instance = null;

	/** @var STM_Settings */        private $settings;
	/** @var STM_Voys_Webhook */    private $webhook;
	/** @var STM_Voys_Import */     private $import;
	/** @var STM_Cron */            private $cron;
	/** @var STM_Admin_Dashboard */ private $dashboard;
	/** @var STM_Trends */          private $trends;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings  = new STM_Settings();
		$this->webhook   = new STM_Voys_Webhook();
		$this->import    = new STM_Voys_Import();
		$this->cron      = new STM_Cron();
		$this->dashboard = new STM_Admin_Dashboard();
		$this->trends    = new STM_Trends();
	}

	public function run() {
		// Live call capture + daily email sync.
		add_action( 'rest_api_init', array( $this->webhook, 'register_routes' ) );
		$this->cron->hook();

		// Admin.
		add_action( 'admin_menu', array( $this, 'menus' ) );
		add_action( 'admin_init', array( $this->settings, 'maybe_save' ) );
		add_action( 'admin_init', array( $this->import, 'maybe_handle_upload' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'styles' ) );
		$this->dashboard->register_actions();

		// DB upgrade guard (in case the table predates a schema bump).
		add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );
	}

	public function menus() {
		// Show the menu only to allow-listed users. We translate our own access
		// check into a capability WordPress can evaluate for the current user:
		// 'read' (every logged-in user has it) when allowed, else 'do_not_allow'.
		$cap = STM_Settings::can_access() ? 'read' : 'do_not_allow';

		add_menu_page(
			__( 'Team Monitor', 'stralend-team-monitor' ),
			__( 'Team Monitor', 'stralend-team-monitor' ),
			$cap,
			STM_SLUG,
			array( $this->dashboard, 'render_page' ),
			'dashicons-chart-bar',
			58
		);
		add_submenu_page( STM_SLUG, __( 'Dashboard', 'stralend-team-monitor' ), __( 'Dashboard', 'stralend-team-monitor' ), $cap, STM_SLUG, array( $this->dashboard, 'render_page' ) );
		add_submenu_page( STM_SLUG, __( 'Trends', 'stralend-team-monitor' ), __( 'Trends', 'stralend-team-monitor' ), $cap, 'stm-trends', array( $this->trends, 'render_page' ) );
		add_submenu_page( STM_SLUG, __( 'Voys-import', 'stralend-team-monitor' ), __( 'Voys-import', 'stralend-team-monitor' ), $cap, 'stm-import', array( $this->import, 'render_page' ) );
		add_submenu_page( STM_SLUG, __( 'Instellingen', 'stralend-team-monitor' ), __( 'Instellingen', 'stralend-team-monitor' ), $cap, 'stm-settings', array( $this->settings, 'render_page' ) );
	}

	public function styles( $hook ) {
		if ( false === strpos( (string) $hook, STM_SLUG ) && false === strpos( (string) $hook, 'stm-' ) ) {
			return;
		}
		wp_enqueue_style( 'stm-admin', STM_URL . 'admin/css/admin.css', array(), STM_VERSION );
	}

	public function maybe_upgrade_db() {
		if ( get_option( 'stm_db_version' ) !== STM_DB_VERSION ) {
			STM_DB::create_table();
			update_option( 'stm_db_version', STM_DB_VERSION );
		}
		$this->maybe_purge_epoch_rows();
		$this->maybe_null_repair();
	}

	/**
	 * One-time repair: a parsing bug stored HubSpot emails with a 1970 epoch
	 * date. Those rows are invisible in any real date range, and their dedup
	 * keys would block a correct re-sync, so delete them once and let the user
	 * re-run the sync.
	 */
	private function maybe_purge_epoch_rows() {
		if ( get_option( 'stm_epoch_purge_done' ) ) {
			return;
		}
		global $wpdb;
		$table = STM_DB::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$table} WHERE source = 'hubspot' AND event_date < '2000-01-01'" );
		update_option( 'stm_epoch_purge_done', 1 );
	}

	/**
	 * One-time repair: NULLs were stringified to '' on insert and coerced to 0,
	 * so csat/is_fcr read as real zeroes. None of the current sources supplies
	 * these fields, so resetting their zeroes to NULL is safe.
	 */
	private function maybe_null_repair() {
		if ( get_option( 'stm_null_repair_done' ) ) {
			return;
		}
		global $wpdb;
		$table = STM_DB::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET csat = NULL WHERE csat = 0" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET is_fcr = NULL WHERE is_fcr = 0" );
		update_option( 'stm_null_repair_done', 1 );
	}
}
