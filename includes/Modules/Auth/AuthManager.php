<?php
declare(strict_types=1);

namespace DiviProEngagement\Modules\Auth;

use DiviProEngagement\Modules\Auth\Providers\GoogleProvider;
use DiviProEngagement\Modules\Auth\Providers\DiscordProvider;
use DiviProEngagement\Modules\Auth\Providers\FacebookProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuthManager {
	private const REST_NAMESPACE = 'dpc/v1';
	private const STATE_TTL      = 600; // 10 minutes

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_filter( 'get_avatar_url', [ $this, 'filter_avatar_url' ], 10, 3 );
	}

	public function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/auth/(?P<provider>[a-z]+)/start',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_auth_start' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'provider' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/auth/(?P<provider>[a-z]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'handle_auth_callback' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'provider' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
	}

	/**
	 * Step 1: Start OAuth flow and redirect to provider.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_auth_start( \WP_REST_Request $request ): mixed {
		$provider_name = (string) $request->get_param( 'provider' );
		$provider      = $this->get_provider_instance( $provider_name );

		if ( null === $provider || ! $provider->is_configured() ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$redirect_to = (string) $request->get_param( 'redirect_to' );
		if ( '' === $redirect_to ) {
			$redirect_to = wp_get_referer() ?: home_url();
		}
		$redirect_to = wp_validate_redirect( esc_url_raw( $redirect_to ), home_url() );

		$state = wp_generate_password( 32, false, false );

		set_transient(
			'dpc_oauth_state_' . $state,
			[
				'provider'    => $provider_name,
				'redirect_to' => $redirect_to,
			],
			self::STATE_TTL
		);

		$auth_url = $provider->get_auth_url( $state );

		// Allow external redirect to OAuth provider hosts
		add_filter( 'allowed_redirect_hosts', static function ( array $hosts ): array {
			$hosts[] = 'accounts.google.com';
			$hosts[] = 'discord.com';
			$hosts[] = 'www.facebook.com';
			$hosts[] = 'oauth.telegram.org';
			return $hosts;
		} );

		wp_redirect( $auth_url );
		exit;
	}

	/**
	 * Step 2: Handle OAuth callback from provider.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_auth_callback( \WP_REST_Request $request ): mixed {
		$provider_name = (string) $request->get_param( 'provider' );
		$state         = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$code          = sanitize_text_field( (string) $request->get_param( 'code' ) );

		if ( '' === $state || '' === $code ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$state_data = get_transient( 'dpc_oauth_state_' . $state );
		delete_transient( 'dpc_oauth_state_' . $state );

		if ( ! is_array( $state_data ) || ( $state_data['provider'] ?? '' ) !== $provider_name ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$redirect_to = ! empty( $state_data['redirect_to'] ) ? (string) $state_data['redirect_to'] : home_url();
		$provider    = $this->get_provider_instance( $provider_name );

		if ( null === $provider || ! $provider->is_configured() ) {
			wp_safe_redirect( $redirect_to );
			exit;
		}

		$profile = $provider->get_user_profile( $code );
		if ( is_wp_error( $profile ) || empty( $profile['social_id'] ) ) {
			wp_safe_redirect( $redirect_to );
			exit;
		}

		$this->login_or_register_user( $profile, $provider_name, $redirect_to );
		exit;
	}

	/**
	 * Find or create a WordPress user from social profile, log them in, and redirect.
	 *
	 * @param array{social_id: string, email: string, name: string, avatar: string} $profile     Normalized profile data.
	 * @param string                                                                 $provider    Provider slug.
	 * @param string                                                                 $redirect_to Target redirect URL.
	 */
	public function login_or_register_user( array $profile, string $provider, string $redirect_to = '' ): void {
		$social_id = sanitize_text_field( (string) ( $profile['social_id'] ?? '' ) );
		$email     = sanitize_email( (string) ( $profile['email'] ?? '' ) );
		$name      = sanitize_text_field( (string) ( $profile['name'] ?? '' ) );
		$avatar    = esc_url_raw( (string) ( $profile['avatar'] ?? '' ) );
		$meta_key  = "_dpc_{$provider}_id";

		$user = null;

		// 1. Search by provider social ID meta
		$existing_users = get_users(
			[
				'meta_key'   => $meta_key,
				'meta_value' => $social_id,
				'number'     => 1,
				'fields'     => 'all',
			]
		);

		if ( ! empty( $existing_users[0] ) && $existing_users[0] instanceof \WP_User ) {
			$user = $existing_users[0];
		}

		// 2. Fallback search by email (if auto-linking is enabled)
		if ( null === $user && '' !== $email && (bool) dpc_get_option( 'auth_auto_link_email', 1 ) ) {
			$user_by_email = get_user_by( 'email', $email );
			if ( $user_by_email instanceof \WP_User ) {
				$user = $user_by_email;
			}
		}

		// Block administrator social login if auth_disable_admin is enabled
		if ( $user instanceof \WP_User && (bool) dpc_get_option( 'auth_disable_admin', 1 ) && user_can( $user, 'manage_options' ) ) {
			wp_die(
				'В целях безопасности Администратор должен входить только через страницу входа по паролю.',
				'Доступ ограничен',
				[
					'response'  => 403,
					'back_link' => true,
				]
			);
		}

		// 3. Register new user if not found
		if ( null === $user ) {
			$base_login = '';
			if ( '' !== $email ) {
				$base_login = sanitize_user( strstr( $email, '@', true ) ?: '', true );
			}
			if ( '' === $base_login && '' !== $name ) {
				$base_login = sanitize_user( strtolower( str_replace( ' ', '_', $name ) ), true );
			}
			if ( '' === $base_login ) {
				$base_login = $provider . '_' . substr( md5( $social_id ), 0, 8 );
			}

			$user_login = $base_login;
			$counter    = 1;
			while ( username_exists( $user_login ) ) {
				$user_login = $base_login . $counter;
				++$counter;
			}

			$default_role = sanitize_key( (string) dpc_get_option( 'auth_default_role', 'subscriber' ) );
			if ( '' === $default_role || 'administrator' === $default_role || null === get_role( $default_role ) ) {
				$default_role = 'subscriber';
			}

			$user_email = ( '' !== $email && ! email_exists( $email ) ) ? $email : "{$user_login}@{$provider}.local";
			$user_id    = wp_insert_user(
				[
					'user_login'   => $user_login,
					'user_email'   => $user_email,
					'user_pass'    => wp_generate_password( 24 ),
					'display_name' => '' !== $name ? $name : $user_login,
					'first_name'   => '' !== $name ? $name : '',
					'role'         => $default_role,
				]
			);

			if ( is_wp_error( $user_id ) ) {
				wp_safe_redirect( '' !== $redirect_to ? $redirect_to : home_url() );
				exit;
			}

			$user = get_user_by( 'id', $user_id );
		}

		if ( ! ( $user instanceof \WP_User ) ) {
			wp_safe_redirect( '' !== $redirect_to ? $redirect_to : home_url() );
			exit;
		}

		if ( (bool) dpc_get_option( 'auth_disable_admin', 1 ) && user_can( $user, 'manage_options' ) ) {
			wp_die(
				'В целях безопасности Администратор должен входить только через страницу входа по паролю.',
				'Доступ ограничен',
				[
					'response'  => 403,
					'back_link' => true,
				]
			);
		}

		// Update social meta and avatar
		update_user_meta( $user->ID, $meta_key, $social_id );
		if ( '' !== $avatar ) {
			update_user_meta( $user->ID, '_dpc_avatar_url', $avatar );
		}

		// Authenticate user
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );

		$target_url = '' !== $redirect_to ? wp_validate_redirect( $redirect_to, home_url() ) : home_url();
		wp_safe_redirect( $target_url );
		exit;
	}

	/**
	 * Override default Gravatar with social avatar if stored in user_meta.
	 *
	 * @param string $url         Default avatar URL.
	 * @param mixed  $id_or_email User ID, WP_User, WP_Post, WP_Comment, or email.
	 * @param array  $args        Avatar arguments.
	 * @return string
	 */
	public function filter_avatar_url( string $url, mixed $id_or_email, array $args ): string {
		$user_id = 0;

		if ( is_numeric( $id_or_email ) ) {
			$user_id = (int) $id_or_email;
		} elseif ( $id_or_email instanceof \WP_User ) {
			$user_id = $id_or_email->ID;
		} elseif ( $id_or_email instanceof \WP_Comment && ! empty( $id_or_email->user_id ) ) {
			$user_id = (int) $id_or_email->user_id;
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			if ( $user instanceof \WP_User ) {
				$user_id = $user->ID;
			}
		}

		if ( $user_id > 0 ) {
			$custom_avatar = get_user_meta( $user_id, '_dpc_avatar_url', true );
			if ( is_string( $custom_avatar ) && '' !== $custom_avatar ) {
				return esc_url( $custom_avatar );
			}
		}

		return $url;
	}

	/**
	 * Render social login buttons or logged-in user bar.
	 *
	 * @return string
	 */
	public function render_auth_box(): string {
		$current_url = $this->get_current_page_url();

		if ( is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			$avatar_url   = get_avatar_url( $current_user->ID, [ 'size' => 64 ] );
			$logout_url   = wp_logout_url( $current_url );

			ob_start();
			?>
			<div class="dpc-user-logged">
				<div class="dpc-user-logged__info">
					<img class="dpc-user-logged__avatar" src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $current_user->display_name ); ?>" width="36" height="36" loading="lazy" />
					<span class="dpc-user-logged__name"><?php echo esc_html( $current_user->display_name ); ?></span>
				</div>
				<a class="dpc-user-logged__logout" href="<?php echo esc_url( $logout_url ); ?>">
					<?php echo esc_html__( 'Выйти', 'divi-pro-engagement' ); ?>
				</a>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		$google   = new GoogleProvider();
		$discord  = new DiscordProvider();
		$facebook = new FacebookProvider();

		$has_active_provider = $google->is_configured() || $discord->is_configured() || $facebook->is_configured();
		if ( ! $has_active_provider ) {
			return '';
		}

		$current_url_encoded = rawurlencode( $current_url );

		ob_start();
		?>
		<div class="dpc-social-login">
			<div class="dpc-social-login__title"><?php echo esc_html__( 'Войти через соцсети:', 'divi-pro-engagement' ); ?></div>
			<div class="dpc-social-login__buttons">
				<?php if ( $google->is_configured() ) : ?>
					<?php
					$google_start_url = add_query_arg(
						'redirect_to',
						$current_url_encoded,
						rest_url( self::REST_NAMESPACE . '/auth/google/start' )
					);
					?>
					<a href="<?php echo esc_url( $google_start_url ); ?>" class="dpc-social-btn dpc-social-btn--google" rel="nofollow">
						<span class="dpc-social-btn__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg">
								<path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
								<path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
								<path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
								<path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
							</svg>
						</span>
						<span class="dpc-social-btn__text">Google</span>
					</a>
				<?php endif; ?>

				<?php if ( $discord->is_configured() ) : ?>
					<?php
					$discord_start_url = add_query_arg(
						'redirect_to',
						$current_url_encoded,
						rest_url( self::REST_NAMESPACE . '/auth/discord/start' )
					);
					?>
					<a href="<?php echo esc_url( $discord_start_url ); ?>" class="dpc-social-btn dpc-social-btn--discord" rel="nofollow">
						<span class="dpc-social-btn__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg">
								<path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994.021-.041.001-.09-.041-.106a13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.929 1.793 8.18 1.793 12.061 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.894.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.028zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z" fill="#ffffff"/>
							</svg>
						</span>
						<span class="dpc-social-btn__text">Discord</span>
					</a>
				<?php endif; ?>

				<?php if ( $facebook->is_configured() ) : ?>
					<?php
					$facebook_start_url = add_query_arg(
						'redirect_to',
						$current_url_encoded,
						rest_url( self::REST_NAMESPACE . '/auth/facebook/start' )
					);
					?>
					<a href="<?php echo esc_url( $facebook_start_url ); ?>" class="dpc-social-btn dpc-social-btn--facebook" rel="nofollow">
						<span class="dpc-social-btn__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg">
								<path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" fill="#ffffff"/>
							</svg>
						</span>
						<span class="dpc-social-btn__text">Facebook</span>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private function get_provider_instance( string $provider ): object|null {
		return match ( $provider ) {
			'google'   => new GoogleProvider(),
			'discord'  => new DiscordProvider(),
			'facebook' => new FacebookProvider(),
			default    => null,
		};
	}

	private function get_current_page_url(): string {
		if ( is_singular() ) {
			$permalink = get_permalink();
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		return home_url( $request_uri );
	}
}
