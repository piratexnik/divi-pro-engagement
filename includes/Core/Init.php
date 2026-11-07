<?php
declare(strict_types=1);

namespace DiviProEngagement\Core;

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

		// Module 1: Comments (Divi 5 Comments, AJAX, Reactions, AntiSpam)
		if ( (bool) dpc_get_option( 'enable_comments', false ) ) {
			// Stub: Modules\Comments\CommentsManager will be loaded here in Sprint 2.
		}

		// Module 2: Post Rating (Schema.org JSON-LD + [dpc_post_rating])
		if ( (bool) dpc_get_option( 'enable_post_rating', false ) ) {
			// Stub: Modules\PostRating\RatingManager will be loaded here in Sprint 3.
		}

		// Module 3: Social Authentication (REST API + [dpc_social_login])
		if ( (bool) dpc_get_option( 'enable_social_auth', false ) ) {
			// Stub: Modules\Auth\AuthManager will be loaded here in Sprint 4.
		}
	}
}
