<?php
/**
 * Plugin Name:       WP Drive
 * Plugin URI:        https://github.com/taahzino/wp-drive-taahzino
 * Description:       Connect your WordPress site to Google Drive. Browse your uploads, select files or folders, and upload them directly to your Drive — with a live progress bar.
 * Version:           1.0.0
 * Author:            taahzino
 * Author URI:        https://github.com/taahzino
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-drive-taahzino
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_DRIVE_VERSION', '1.0.0' );
define( 'WP_DRIVE_FILE', __FILE__ );
define( 'WP_DRIVE_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_DRIVE_URL', plugin_dir_url( __FILE__ ) );

// Composer autoloader.
if ( file_exists( WP_DRIVE_DIR . 'vendor/autoload.php' ) ) {
	require_once WP_DRIVE_DIR . 'vendor/autoload.php';
} else {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>';
		echo '<strong>WP Drive:</strong> Composer dependencies are missing. Run <code>composer install</code> inside the plugin directory.';
		echo '</p></div>';
	} );
	return;
}

// Bootstrap.
add_action( 'plugins_loaded', function () {
	WPDrive\Plugin::get_instance();
} );

register_activation_hook( __FILE__, function () {
	flush_rewrite_rules();
	// Ensure wizard is shown on first activation.
	if ( false === get_option( 'wp_drive_wizard_completed' ) ) {
		// Option already exists, skip.
	} else {
		add_option( 'wp_drive_wizard_completed', false, '', false );
	}
} );

register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
	wp_clear_scheduled_hook( 'wp_drive_upload_cron' );
} );
