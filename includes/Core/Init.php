<?php
declare(strict_types=1);

namespace DiviProEngagement\Core;

use DiviProEngagement\Modules\Auth\AuthManager;
use DiviProEngagement\Modules\Comments\CommentsEngine;
use DiviProEngagement\Modules\PostRating\RatingEngine;

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
		Shortcodes::get_instance();

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );

		$comments_enabled = (bool) dpc_get_option( 'enable_comments', false );

		// Module 1: Comments (Divi 5 Comments, AJAX, Reactions, AntiSpam, Floating Nudge)
		if ( $comments_enabled ) {
			CommentsEngine::get_instance();
		}

		// Module 2: Post Rating (Schema.org JSON-LD + [dpc_post_rating])
		if ( (bool) dpc_get_option( 'enable_post_rating', false ) ) {
			RatingEngine::get_instance();
		}

		// Module 3: Social Authentication (REST API + [dpc_social_login])
		if ( (bool) dpc_get_option( 'enable_social_auth', false ) ) {
			AuthManager::get_instance();

			if ( ! $comments_enabled && (bool) dpc_get_option( 'auth_show_in_comments', true ) ) {
				add_action( 'comment_form_before_fields', [ $this, 'render_comment_form_social_login' ] );
				add_action( 'comment_form_must_log_in_after', [ $this, 'render_comment_form_social_login' ] );
				add_filter( 'comment_form_defaults', [ $this, 'filter_comment_form_logged_in_as' ] );
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

		if ( (bool) dpc_get_option( 'enable_social_auth', false ) && (bool) dpc_get_option( 'auth_telegram_enable', false ) ) {
			wp_enqueue_script(
				'dpc-telegram-widget',
				'https://telegram.org/js/telegram-widget.js?22',
				[],
				null,
				true
			);
		}

		if (
			! is_user_logged_in()
			&& (bool) dpc_get_option( 'enable_comments', false )
			&& (bool) dpc_get_option( 'antispam_turnstile_enable', false )
			&& '' !== (string) dpc_get_option( 'turnstile_site_key', '' )
		) {
			wp_enqueue_script(
				'dpc-turnstile',
				'https://challenges.cloudflare.com/turnstile/v0/api.js',
				[],
				null,
				true
			);
		}

		wp_enqueue_script(
			'dpc-frontend',
			DPC_URL . 'assets/js/frontend.js',
			[ 'jquery' ],
			DPC_VERSION,
			true
		);

		wp_localize_script(
			'dpc-frontend',
			'dpcFrontend',
			[
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'nonce'               => wp_create_nonce( 'dpc_frontend_nonce' ),
				'telegramCallbackUrl' => home_url( '/wp-json/dpc/v1/auth/telegram' ),
			]
		);
	}

	public function render_comment_form_social_login(): void {
		if ( $this->is_visual_builder_active() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output is escaped inside render_auth_box().
		echo AuthManager::get_instance()->render_auth_box( 'comments' );
	}

	/**
	 * Replace default "Logged in as..." text in comment form with the compact User Avatar Dropdown.
	 *
	 * @param array<string, mixed> $defaults Comment form defaults.
	 * @return array<string, mixed>
	 */
	public function filter_comment_form_logged_in_as( array $defaults ): array {
		if ( $this->is_visual_builder_active() ) {
			return $defaults;
		}

		$defaults['logged_in_as'] = AuthManager::get_instance()->render_auth_box( 'comments' );
		return $defaults;
	}

	private function is_visual_builder_active(): bool {
		return function_exists( 'et_core_is_fb_enabled' ) && (bool) et_core_is_fb_enabled();
	}
}
