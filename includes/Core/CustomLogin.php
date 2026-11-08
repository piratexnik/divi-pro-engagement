<?php
declare(strict_types=1);

namespace DiviProEngagement\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CustomLogin {
	private static ?self $instance = null;

	private bool $hide_admin_bar;
	private bool $enable_custom_login;
	private string $custom_slug;
	private bool $is_custom_login_request = false;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->hide_admin_bar      = (bool) dpc_get_option( 'hide_admin_bar', false );
		$this->enable_custom_login = (bool) dpc_get_option( 'enable_custom_login', false );
		$this->custom_slug         = trim( (string) dpc_get_option( 'custom_login_slug', '' ), '/' );

		if ( $this->hide_admin_bar ) {
			add_action( 'init', [ $this, 'restrict_subscriber_admin_bar_and_area' ] );
		}

		if ( $this->enable_custom_login && '' !== $this->custom_slug ) {
			$this->detect_custom_login_request();
			add_action( 'wp_loaded', [ $this, 'handle_custom_login_routing' ] );
			add_filter( 'site_url', [ $this, 'filter_login_url' ], 10, 4 );
			add_filter( 'network_site_url', [ $this, 'filter_network_login_url' ], 10, 3 );
			add_filter( 'wp_redirect', [ $this, 'filter_login_redirect' ], 10, 2 );
		}
	}

	/**
	 * Hide Admin Bar and block /wp-admin/ access for users who cannot edit_posts.
	 */
	public function restrict_subscriber_admin_bar_and_area(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( current_user_can( 'edit_posts' ) ) {
			return;
		}

		add_filter( 'show_admin_bar', '__return_false' );

		if ( is_admin() && ! wp_doing_ajax() ) {
			$script_filename = isset( $_SERVER['SCRIPT_FILENAME'] )
				? basename( sanitize_text_field( wp_unslash( (string) $_SERVER['SCRIPT_FILENAME'] ) ) )
				: '';

			if ( 'admin-post.php' !== $script_filename ) {
				wp_safe_redirect( home_url() );
				exit;
			}
		}
	}

	/**
	 * Detect early whether the request targets the custom login slug.
	 */
	public function detect_custom_login_request(): void {
		$request_path = $this->get_relative_request_path();

		if ( $request_path === $this->custom_slug ) {
			$this->is_custom_login_request = true;
			$GLOBALS['pagenow']            = 'wp-login.php';
		}
	}

	/**
	 * Serve wp-login.php on custom slug or return 404 on direct wp-login.php / unauthenticated wp-admin.
	 */
	public function handle_custom_login_routing(): void {
		global $pagenow, $user_login, $error, $action, $errors, $interim_login, $redirect_to;

		if ( $this->is_custom_login_request ) {
			$user_login = isset( $user_login ) && is_string( $user_login ) ? $user_login : '';
			$error      = isset( $error ) && is_string( $error ) ? $error : '';
			require_once ABSPATH . 'wp-login.php';
			exit;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '';

		$is_wp_login = ( 'wp-login.php' === $pagenow )
			|| str_contains( $request_uri, 'wp-login.php' )
			|| str_contains( $request_uri, 'wp-signup.php' );

		// Allow POST action=postpass for password-protected posts
		$req_action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : '';
		if ( 'postpass' === $req_action ) {
			return;
		}

		if ( $is_wp_login ) {
			$this->render_404();
		}

		if ( is_admin() && ! is_user_logged_in() && ! wp_doing_ajax() ) {
			$script_filename = isset( $_SERVER['SCRIPT_FILENAME'] )
				? basename( sanitize_text_field( wp_unslash( (string) $_SERVER['SCRIPT_FILENAME'] ) ) )
				: '';

			if ( ! in_array( $script_filename, [ 'admin-ajax.php', 'admin-post.php' ], true ) ) {
				$this->render_404();
			}
		}
	}

	/**
	 * Replace wp-login.php in site_url().
	 */
	public function filter_login_url( string $url, string $path, ?string $scheme = null, ?int $blog_id = null ): string {
		return $this->replace_wp_login_in_url( $url );
	}

	/**
	 * Replace wp-login.php in network_site_url().
	 */
	public function filter_network_login_url( string $url, string $path, ?string $scheme = null ): string {
		return $this->replace_wp_login_in_url( $url );
	}

	/**
	 * Replace wp-login.php in wp_redirect().
	 */
	public function filter_login_redirect( string $location, int $status = 302 ): string {
		return $this->replace_wp_login_in_url( $location );
	}

	private function replace_wp_login_in_url( string $url ): string {
		if ( ! str_contains( $url, 'wp-login.php' ) ) {
			return $url;
		}

		if ( str_contains( $url, 'action=postpass' ) ) {
			return $url;
		}

		return str_replace( 'wp-login.php', $this->custom_slug, $url );
	}

	private function get_relative_request_path(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '/';

		$parsed_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$site_path   = (string) wp_parse_url( site_url(), PHP_URL_PATH );
		$home_path   = (string) wp_parse_url( home_url(), PHP_URL_PATH );

		if ( '' !== $site_path && '/' !== $site_path && str_starts_with( $parsed_path, $site_path ) ) {
			$parsed_path = substr( $parsed_path, strlen( $site_path ) );
		} elseif ( '' !== $home_path && '/' !== $home_path && str_starts_with( $parsed_path, $home_path ) ) {
			$parsed_path = substr( $parsed_path, strlen( $home_path ) );
		}

		return trim( $parsed_path, '/' );
	}

	private function render_404(): never {
		global $wp_query;
		if ( isset( $wp_query ) && $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}

		status_header( 404 );
		nocache_headers();

		$template = get_query_template( '404' );
		if ( is_string( $template ) && '' !== $template && file_exists( $template ) ) {
			include $template;
		} else {
			wp_die(
				esc_html__( '404 — Страница не найдена.', 'divi-pro-engagement' ),
				esc_html__( '404 Not Found', 'divi-pro-engagement' ),
				[ 'response' => 404 ]
			);
		}
		exit;
	}
}
