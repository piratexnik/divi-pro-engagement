<?php
/**
 * Plugin Name:       Divi Pro Engagement
 * Plugin URI:        https://github.com/piratexnik/divi-pro-engagement
 * Description:       Advanced engagement toolkit for Divi 5: AJAX Comments, Reactions, Post Ratings with Schema.org, and Social Authentication.
 * Version:           2.0.2
 * Author:            Piratexnik
 * Author URI:        https://github.com/piratexnik
 * License:           GPL-2.0-or-later
 * Text Domain:       divi-pro-engagement
 * Requires at least: 6.5
 * Requires PHP:      8.1
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DPC_VERSION', '2.0.2' );
define( 'DPC_PATH', plugin_dir_path( __FILE__ ) );
define( 'DPC_URL', plugin_dir_url( __FILE__ ) );
define( 'DPC_BASENAME', plugin_basename( __FILE__ ) );

// PSR-4 Autoloader
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'DiviProEngagement\\';
	$base_dir = DPC_PATH . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Global helper to retrieve options from dpc_settings.
 *
 * @param string $key Setting key.
 * @param mixed  $default Default value if key is not found.
 * @return mixed
 */
function dpc_get_option( string $key, mixed $default = false ): mixed {
	$settings = get_option( 'dpc_settings', [] );
	if ( is_array( $settings ) && array_key_exists( $key, $settings ) ) {
		return $settings[ $key ];
	}
	return $default;
}

// Bootstrap plugin
add_action( 'plugins_loaded', static function (): void {
	\DiviProEngagement\Core\Init::get_instance();
} );
