<?php
declare(strict_types=1);

namespace DiviProEngagement\Core;

use DiviProEngagement\Modules\Auth\AuthManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Init {
	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init();
	}

	private function init(): void {
		if ( is_admin() ) {
			Settings::get_instance();
			Updater::get_instance();
		}

		CustomLogin::get_instance();

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );

		// Module 1: Comments (Divi 5 Comments, AJAX, Reactions, AntiSpam)
		if ( (bool) dpc_get_option( 'enable_comments', false ) ) {
			// Stub: Modules\Comments\CommentsManager will be loaded here.
		}

		// Module 2: Post Rating (Schema.org JSON-LD + [dpc_post_rating])
		if ( (bool) dpc_get_option( 'enable_post_rating', false ) ) {
			// Stub: Modules\PostRating\RatingManager will be loaded here.
		}

		// Module 3: Social Authentication (REST API + [dpc_social_login])
		if ( (bool) dpc_get_option( 'enable_social_auth', false ) ) {
			AuthManager::get_instance();
			Shortcodes::get_instance();

			if ( (bool) dpc_get_option( 'auth_show_in_comments', true ) ) {
				add_action( 'comment_form_before_fields', [ $this, 'render_comment_form_social_login' ] );
				add_action( 'comment_form_must_log_in_after', [ $this, 'render_comment_form_social_login' ] );
			}
		}
	}

	public function enqueue_frontend_assets(): void {
		if ( $this->is_visual_builder_active() ) {
			return;
		}

		wp_enqueue_style(
			'dpc-frontend',
			DPC_URL . 'assets/css/frontend.css',
			[],
			DPC_VERSION
		);
	}

	public function render_comment_form_social_login(): void {
		if ( $this->is_visual_builder_active() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped inside render_auth_box().
		echo AuthManager::get_instance()->render_auth_box();
	}

	private function is_visual_builder_active(): bool {
		return function_exists( 'et_core_is_fb_enabled' ) && (bool) et_core_is_fb_enabled();
	}
}
