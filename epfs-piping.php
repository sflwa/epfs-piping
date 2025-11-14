<?php
/**
 * Plugin Name: Email Piping for FluentSupport
 * Plugin URI:  https://example.com/
 * Description: Securely pipes emails from a dedicated POP3 account into FluentSupport using the REST API, avoiding the need for their cloud masking service.
 * Version:     1.0.0
 * Author:      Gemini
 * Author URI:  https://google.com
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: epfs-piping
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define the plugin path and URL constants.
if ( ! defined( 'EPFS_PATH' ) ) {
	define( 'EPFS_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'EPFS_URL' ) ) {
	define( 'EPFS_URL', plugin_dir_url( __FILE__ ) );
}

// Ensure the core class file is available.
require_once EPFS_PATH . 'includes/class-epfs-plugin-core.php';

/**
 * The core function responsible for initializing the plugin.
 *
 * @since 1.0.0
 *
 * @return void
 */
function epfs_run_plugin() {
	EPFS_Plugin_Core::get_instance();
}

// Kick off the plugin.
epfs_run_plugin();
