<?php
/**
 * Plugin Name:       Divi Pro Engagement
 * Plugin URI:        https://github.com/piratexnik/divi-pro-engagement
 * Description:       Advanced engagement toolkit for Divi 5: AJAX Comments, Reactions, Post Ratings with Schema.org, and Social Authentication.
 * Version:           2.1.3
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

define( 'DPC_VERSION', '2.1.3' );
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
 * Global helper to retrieve options from dpc_settings with in-memory static cache.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Default value if key is not found.
 * @return mixed
 */
function dpc_get_option( string $key, mixed $default = false ): mixed {
	static $cached_settings  = null;
	static $hooks_registered = false;

	if ( ! $hooks_registered ) {
		$hooks_registered = true;
		$reset_cache      = static function () use ( &$cached_settings ): void {
			$cached_settings = null;
		};
		add_action( 'update_option_dpc_settings', $reset_cache );
		add_action( 'add_option_dpc_settings', $reset_cache );
		add_action( 'delete_option_dpc_settings', $reset_cache );
	}

	if ( null === $cached_settings ) {
		$raw             = get_option( 'dpc_settings', [] );
		$cached_settings = is_array( $raw ) ? $raw : [];
	}

	if ( array_key_exists( $key, $cached_settings ) ) {
		return $cached_settings[ $key ];
	}

	return $default;
}

// Bootstrap plugin
add_action( 'plugins_loaded', static function (): void {
	\DiviProEngagement\Core\Init::get_instance();
} );
