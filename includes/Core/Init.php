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
		add_action( 'wp_ajax_dpc_refresh_nonce', [ $this, 'handle_ajax_refresh_nonce' ] );
		add_action( 'wp_ajax_nopriv_dpc_refresh_nonce', [ $this, 'handle_ajax_refresh_nonce' ] );

		$comments_enabled    = (bool) dpc_get_option( 'enable_comments', false );
		$rating_enabled      = (bool) dpc_get_option( 'enable_post_rating', false );
		$social_auth_enabled = (bool) dpc_get_option( 'enable_social_auth', false );

		if ( $comments_enabled || $social_auth_enabled ) {
			AuthManager::get_instance()->register_avatar_filters();
		}

		// Module 1: Comments (Divi 5 Comments, AJAX, Reactions, AntiSpam, Floating Nudge)
		if ( $comments_enabled ) {
			CommentsEngine::get_instance();
		}

		// Module 2: Post Rating (Schema.org JSON-LD + [dpc_post_rating])
		if ( $rating_enabled ) {
			RatingEngine::get_instance();
		}

		// Module 3: Social Authentication (REST API + [dpc_social_login])
		if ( $social_auth_enabled ) {
			AuthManager::get_instance()->register_oauth_routes();

			if ( ! $comments_enabled && (bool) dpc_get_option( 'auth_show_in_comments', true ) ) {
				add_action( 'comment_form_before_fields', [ $this, 'render_comment_form_social_login' ] );
				add_action( 'comment_form_must_log_in_after', [ $this, 'render_comment_form_social_login' ] );
				add_filter( 'comment_form_defaults', [ $this, 'filter_comment_form_logged_in_as' ] );
			}
		}
	}

	/**
	 * Lightweight public AJAX endpoint to refresh expired frontend nonce on cached pages (FlyingPress).
	 */
	public function handle_ajax_refresh_nonce(): void {
		nocache_headers();
		wp_send_json_success(
			[
				'nonce' => wp_create_nonce( 'dpc_frontend_nonce' ),
			]
		);
	}

	public function enqueue_frontend_assets(): void {
		if ( self::is_visual_builder_active() ) {
			return;
		}

		$comments_enabled    = (bool) dpc_get_option( 'enable_comments', false );
		$rating_enabled      = (bool) dpc_get_option( 'enable_post_rating', false );
		$social_auth_enabled = (bool) dpc_get_option( 'enable_social_auth', false );

		if ( ! $comments_enabled && ! $rating_enabled && ! $social_auth_enabled ) {
			return;
		}

		wp_enqueue_style(
			'dpc-frontend',
			DPC_URL . 'assets/css/frontend.css',
			[],
			DPC_VERSION
		);

		if (
			! is_user_logged_in()
			&& $comments_enabled
			&& is_singular()
			&& comments_open()
			&& (bool) dpc_get_option( 'antispam_turnstile_enable', false )
			&& '' !== (string) dpc_get_option( 'turnstile_site_key', '' )
		) {
			wp_enqueue_script(
				'dpc-turnstile',
				'https://challenges.cloudflare.com/turnstile/v0/api.js',
				[],
				null,
				[
					'in_footer' => true,
					'strategy'  => 'defer',
				]
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
				'i18n'                => CommentsEngine::get_all_dictionaries(),
			]
		);
	}

	public function render_comment_form_social_login(): void {
		if ( self::is_visual_builder_active() ) {
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
		if ( self::is_visual_builder_active() ) {
			return $defaults;
		}

		$defaults['logged_in_as'] = AuthManager::get_instance()->render_auth_box( 'comments' );
		return $defaults;
	}

	/**
	 * Check whether Divi 5 Visual Builder is currently active.
	 *
	 * @return bool
	 */
	public static function is_visual_builder_active(): bool {
		return function_exists( 'et_core_is_fb_enabled' ) && (bool) et_core_is_fb_enabled();
	}

	/**
	 * Generate a 10-character visitor hash from IP and User-Agent.
	 *
	 * @return string
	 */
	public static function get_visitor_short_hash(): string {
		$ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		return substr( md5( $ip . $user_agent ), 0, 10 );
	}

	/**
	 * Append current visitor short hash into a circular buffer array.
	 *
	 * @param mixed $hashes Existing hashes meta value.
	 * @param int   $limit  Maximum buffer length.
	 * @return array<int, string>
	 */
	public static function append_visitor_hash( mixed $hashes, int $limit = 200 ): array {
		$list = is_array( $hashes ) ? $hashes : [];
		$hash = self::get_visitor_short_hash();

		if ( ! in_array( $hash, $list, true ) ) {
			$list[] = $hash;
		}

		if ( count( $list ) > $limit ) {
			$list = array_slice( $list, -$limit );
		}

		return $list;
	}
}
