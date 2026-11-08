<?php
declare(strict_types=1);

namespace DiviProEngagement\Core;

use DiviProEngagement\Modules\Auth\AuthManager;
use DiviProEngagement\Modules\PostRating\RatingEngine;

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
		add_shortcode( 'dpc_post_rating', [ $this, 'render_post_rating_shortcode' ] );
	}

	/**
	 * Shortcode callback for [dpc_social_login].
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_social_login_shortcode( array|string $atts = [] ): string {
		if ( ! (bool) dpc_get_option( 'enable_social_auth', false ) || Init::is_visual_builder_active() ) {
			return '';
		}

		$parsed_atts = shortcode_atts(
			[
				'style'         => '',
				'style_desktop' => (string) dpc_get_option( 'auth_shortcode_style_desktop', 'inline' ),
				'style_mobile'  => (string) dpc_get_option( 'auth_shortcode_style_mobile', 'icon' ),
				'skin'          => (string) dpc_get_option( 'auth_shortcode_skin', 'brand' ),
			],
			is_array( $atts ) ? $atts : [],
			'dpc_social_login'
		);

		return AuthManager::get_instance()->render_auth_box( 'shortcode', $parsed_atts );
	}

	/**
	 * Shortcode callback for [dpc_post_rating].
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_post_rating_shortcode( array|string $atts = [] ): string {
		if ( ! (bool) dpc_get_option( 'enable_post_rating', false ) || Init::is_visual_builder_active() ) {
			return '';
		}

		$parsed_atts = shortcode_atts(
			[
				'post_id' => 0,
			],
			is_array( $atts ) ? $atts : [],
			'dpc_post_rating'
		);

		$post_id = absint( $parsed_atts['post_id'] );

		return RatingEngine::get_instance()->render_rating_widget( $post_id > 0 ? $post_id : null );
	}
}
