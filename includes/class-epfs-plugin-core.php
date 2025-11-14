<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The main core class for the Email Piping for FluentSupport plugin.
 *
 * @since 1.0.0
 */
class EPFS_Plugin_Core {

	/**
	 * Single instance of the class.
	 *
	 * @var EPFS_Plugin_Core
	 */
	private static $instance = null;

	/**
	 * Retrieves the one true instance of the plugin.
	 *
	 * @return EPFS_Plugin_Core
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->setup_dependencies();
			self::$instance->setup_hooks();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Private constructor to enforce singleton pattern.
	}

	/**
	 * Load necessary class files and dependencies.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function setup_dependencies() {
		// Include Admin Settings class.
		require_once EPFS_PATH . 'includes/class-epfs-admin-settings.php';
		// Include POP3 Handler class.
		require_once EPFS_PATH . 'includes/class-epfs-pop3-handler.php';
	}

	/**
	 * Setup WordPress action and filter hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function setup_hooks() {
		// Admin hooks.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_menu', array( EPFS_Admin_Settings::get_instance(), 'add_admin_menu' ) );

		// Plugin activation/deactivation hooks.
		register_activation_hook( EPFS_PATH . 'email-piping-for-fluentsupport.php', array( $this, 'on_activation' ) );
		register_deactivation_hook( EPFS_PATH . 'email-piping-for-fluentsupport.php', array( $this, 'on_deactivation' ) );

		// Initialize POP3 Handler and cron setup.
		EPFS_POP3_Handler::get_instance();
	}

	/**
	 * Enqueue admin-specific styles and scripts.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue_admin_assets() {
		$screen = get_current_screen();
		// Check for the top-level log page (toplevel_page_epfs-piping) OR the settings submenu page (epfs-piping_page_epfs-settings).
		if ( 'toplevel_page_epfs-piping' === $screen->id || 'epfs-piping_page_epfs-settings' === $screen->id ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant defined by core WP.
			wp_enqueue_style( 'epfs-admin-style', EPFS_URL . 'admin/css/epfs-admin.css', array(), '1.0.0' );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant defined by core WP.
			wp_enqueue_script( 'epfs-admin-script', EPFS_URL . 'admin/js/epfs-admin.js', array( 'jquery' ), '1.0.0', true );
		}
	}

	/**
	 * Actions to run on plugin activation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function on_activation() {
		// Ensure the initial cron schedule is set up upon activation.
		EPFS_POP3_Handler::get_instance()->setup_cron_schedule();
	}

	/**
	 * Actions to run on plugin deactivation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function on_deactivation() {
		// Clear the cron job upon deactivation to prevent errors.
		EPFS_POP3_Handler::get_instance()->clear_cron_schedule();
	}
}
