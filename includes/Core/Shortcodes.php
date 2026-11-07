<?php
declare(strict_types=1);

namespace DiviProEngagement\Core;

use DiviProEngagement\Modules\Auth\AuthManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Shortcodes {
	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'dpc_social_login', [ $this, 'render_social_login_shortcode' ] );
	}

	/**
	 * Shortcode callback for [dpc_social_login].
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_social_login_shortcode( array|string $atts = [] ): string {
		if ( ! (bool) dpc_get_option( 'enable_social_auth', false ) ) {
			return '';
		}

		if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
			return '';
		}

		$parsed_atts = shortcode_atts(
			[
				'style' => (string) dpc_get_option( 'auth_shortcode_style', 'inline' ),
				'skin'  => (string) dpc_get_option( 'auth_shortcode_skin', 'brand' ),
			],
			is_array( $atts ) ? $atts : [],
			'dpc_social_login'
		);

		return AuthManager::get_instance()->render_auth_box( 'shortcode', $parsed_atts );
	}
}
