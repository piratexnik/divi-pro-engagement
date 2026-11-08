<?php
declare(strict_types=1);

namespace DiviProEngagement\Modules\Auth;

use DiviProEngagement\Modules\Auth\Providers\GoogleProvider;
use DiviProEngagement\Modules\Auth\Providers\DiscordProvider;
use DiviProEngagement\Modules\Auth\Providers\FacebookProvider;
use DiviProEngagement\Modules\Auth\Providers\TelegramProvider;
use DiviProEngagement\Modules\Auth\Providers\TwitterProvider;

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
		add_filter( 'pre_get_avatar_data', [ $this, 'filter_avatar_data' ], 10, 2 );
		add_filter( 'kses_allowed_protocols', [ $this, 'allow_data_protocol' ] );
	}

	/**
	 * Allow 'data' protocol in esc_url() for inline SVG letter avatars.
	 *
	 * @param array<int, string> $protocols Allowed protocols.
	 * @return array<int, string>
	 */
	public function allow_data_protocol( array $protocols ): array {
		if ( ! in_array( 'data', $protocols, true ) ) {
			$protocols[] = 'data';
		}
		return $protocols;
	}

	/**
	 * Filter pre_get_avatar_data so WordPress get_avatar() uses our local/social/SVG avatar without querying Gravatar.
	 *
	 * @param array<string, mixed> $args        Avatar data arguments.
	 * @param mixed                $id_or_email User ID, WP_User, WP_Post, WP_Comment, or email.
	 * @return array<string, mixed>
	 */
	public function filter_avatar_data( array $args, mixed $id_or_email ): array {
		$args['url']          = $this->filter_avatar_url( (string) ( $args['url'] ?? '' ), $id_or_email, $args );
		$args['found_avatar'] = true;
		return $args;
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

		if ( null === $provider || ! $provider->is_configured() || ! method_exists( $provider, 'get_auth_url' ) ) {
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
			$hosts[] = 'twitter.com';
			$hosts[] = 'x.com';
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

		if ( 'telegram' === $provider_name ) {
			$raw_redirect = (string) $request->get_param( 'redirect_to' );
			$redirect_to  = '' !== $raw_redirect
				? wp_validate_redirect( esc_url_raw( $raw_redirect ), home_url() )
				: home_url();

			$telegram = new TelegramProvider();
			$profile  = $telegram->verify_and_get_profile( $request->get_query_params() );

			if ( is_wp_error( $profile ) || empty( $profile['social_id'] ) ) {
				wp_safe_redirect( $redirect_to );
				exit;
			}

			$this->login_or_register_user( $profile, 'telegram', $redirect_to );
			exit;
		}

		$state = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$code  = sanitize_text_field( (string) $request->get_param( 'code' ) );

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

		if ( null === $provider || ! $provider->is_configured() || ! method_exists( $provider, 'get_user_profile' ) ) {
			wp_safe_redirect( $redirect_to );
			exit;
		}

		$profile = 'twitter' === $provider_name
			? $provider->get_user_profile( $code, $state )
			: $provider->get_user_profile( $code );

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
	 * Filter WordPress avatar URL to return stored social avatar, Simple Local Avatars fallback, or SVG letter avatar.
	 *
	 * @param string $url         Default avatar URL.
	 * @param mixed  $id_or_email User ID, WP_User, WP_Post, WP_Comment, or email.
	 * @param array  $args        Avatar arguments.
	 * @return string
	 */
	public function filter_avatar_url( string $url, mixed $id_or_email, array $args ): string {
		$user_id = 0;
		$name    = '';

		if ( is_numeric( $id_or_email ) ) {
			$user_id = (int) $id_or_email;
		} elseif ( $id_or_email instanceof \WP_User ) {
			$user_id = $id_or_email->ID;
			$name    = (string) $id_or_email->display_name;
		} elseif ( $id_or_email instanceof \WP_Comment ) {
			if ( ! empty( $id_or_email->user_id ) ) {
				$user_id = (int) $id_or_email->user_id;
			}
			$name = (string) $id_or_email->comment_author;
		} elseif ( $id_or_email instanceof \WP_Post ) {
			$user_id = (int) $id_or_email->post_author;
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			if ( $user instanceof \WP_User ) {
				$user_id = $user->ID;
				$name    = (string) $user->display_name;
			}
		}

		if ( $user_id > 0 ) {
			// 1. Check DPC social avatar URL
			$custom_avatar = get_user_meta( $user_id, '_dpc_avatar_url', true );
			if ( is_string( $custom_avatar ) && '' !== trim( $custom_avatar ) ) {
				return esc_url( $custom_avatar );
			}

			// 2. Check legacy Simple Local Avatars user_meta ('simple_local_avatar')
			$local_avatar = get_user_meta( $user_id, 'simple_local_avatar', true );
			if ( is_array( $local_avatar ) ) {
				if ( ! empty( $local_avatar['full'] ) && is_string( $local_avatar['full'] ) ) {
					return esc_url( $local_avatar['full'] );
				}
				if ( ! empty( $local_avatar['media_id'] ) && is_numeric( $local_avatar['media_id'] ) ) {
					$att_url = wp_get_attachment_image_url( (int) $local_avatar['media_id'], 'thumbnail' );
					if ( is_string( $att_url ) && '' !== $att_url ) {
						return esc_url( $att_url );
					}
				}
			} elseif ( is_numeric( $local_avatar ) && (int) $local_avatar > 0 ) {
				$att_url = wp_get_attachment_image_url( (int) $local_avatar, 'thumbnail' );
				if ( is_string( $att_url ) && '' !== $att_url ) {
					return esc_url( $att_url );
				}
			} elseif ( is_string( $local_avatar ) && '' !== trim( $local_avatar ) && str_starts_with( $local_avatar, 'http' ) ) {
				return esc_url( $local_avatar );
			}

			if ( '' === trim( $name ) ) {
				$u = get_userdata( $user_id );
				if ( $u instanceof \WP_User ) {
					$name = (string) $u->display_name;
				}
			}
		}

		// 3. Fallback: Generate local SVG letter avatar (replaces Gravatar & Leira Letter Avatar)
		return self::generate_letter_avatar_svg( $name );
	}

	/**
	 * Generate a circular SVG Data URI avatar with the first letter of the author's name.
	 *
	 * @param string $name Author or user name.
	 * @return string Data URI string (data:image/svg+xml;utf8,...).
	 */
	public static function generate_letter_avatar_svg( string $name ): string {
		$clean_name = trim( wp_strip_all_tags( $name ) );
		if ( '' === $clean_name ) {
			$clean_name = '?';
		}

		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strtoupper' ) ) {
			$letter = mb_strtoupper( mb_substr( $clean_name, 0, 1, 'UTF-8' ), 'UTF-8' );
		} else {
			$letter = strtoupper( substr( $clean_name, 0, 1 ) );
		}

		$palette = [
			'#2563eb', // Blue
			'#7c3aed', // Violet
			'#db2777', // Pink
			'#059669', // Emerald
			'#d97706', // Amber
			'#0891b2', // Cyan
			'#4f46e5', // Indigo
			'#e11d48', // Rose
		];

		$hash_index = abs( (int) crc32( strtolower( $clean_name ) ) ) % 8;
		$bg_color   = $palette[ $hash_index ];
		$safe_char  = htmlspecialchars( $letter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 88 88" width="88" height="88">'
			. '<circle cx="44" cy="44" r="44" fill="' . $bg_color . '"/>'
			. '<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#ffffff" font-family="-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif" font-size="40" font-weight="700">'
			. $safe_char
			. '</text></svg>';

		return 'data:image/svg+xml;utf8,' . rawurlencode( $svg );
	}

	/**
	 * Render multilingual social login buttons or logged-in User Avatar Dropdown.
	 *
	 * @param string               $context 'shortcode' or 'comments'.
	 * @param array<string, mixed> $atts    Optional style/skin overrides.
	 * @return string
	 */
	public function render_auth_box( string $context = 'shortcode', array $atts = [] ): string {
		$current_url  = $this->get_current_page_url();
		$size_desktop = max( 24, min( 100, (int) dpc_get_option( 'auth_size_desktop', 44 ) ) );
		$size_mobile  = max( 24, min( 100, (int) dpc_get_option( 'auth_size_mobile', 40 ) ) );
		$size_vars    = sprintf( '--dpc-size-desktop: %dpx; --dpc-size-mobile: %dpx;', $size_desktop, $size_mobile );

		$skin = 'comments' === $context
			? (string) dpc_get_option( 'auth_comments_skin', 'brand' )
			: ( ! empty( $atts['skin'] ) ? (string) $atts['skin'] : (string) dpc_get_option( 'auth_shortcode_skin', 'brand' ) );

		if ( ! in_array( $skin, [ 'brand', 'dark', 'light' ], true ) ) {
			$skin = 'brand';
		}

		if ( is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			$avatar_url   = get_avatar_url( $current_user->ID, [ 'size' => 96 ] );
			$logout_url   = wp_logout_url( $current_url );
			$public_name  = trim( (string) $current_user->display_name );
			if ( '' === $public_name || ( user_can( $current_user, 'manage_options' ) && 'admin' === strtolower( $public_name ) ) ) {
				$public_name = 'Author';
			}
			$safe_avatar_src = str_starts_with( $avatar_url, 'data:image/svg+xml' ) ? $avatar_url : esc_url( $avatar_url );

			ob_start();
			?>
			<div class="dpc-user-logged skin-<?php echo esc_attr( $skin ); ?>" style="<?php echo esc_attr( $size_vars ); ?>">
				<button type="button" class="dpc-user-avatar-trigger" aria-expanded="false" aria-label="<?php echo esc_attr( $public_name ); ?>">
					<img class="dpc-user-logged__avatar" src="<?php echo esc_attr( $safe_avatar_src ); ?>" alt="<?php echo esc_attr( $public_name ); ?>" width="<?php echo esc_attr( (string) $size_desktop ); ?>" height="<?php echo esc_attr( (string) $size_desktop ); ?>" loading="lazy" />
				</button>
				<div class="dpc-user-dropdown" role="menu">
					<div class="dpc-user-dropdown__profile">
						<img class="dpc-user-dropdown__avatar" src="<?php echo esc_attr( $safe_avatar_src ); ?>" alt="<?php echo esc_attr( $public_name ); ?>" width="48" height="48" loading="lazy" />
						<div class="dpc-user-dropdown__info">
							<span class="dpc-user-dropdown__name"><?php echo esc_html( $public_name ); ?></span>
						</div>
					</div>
					<a class="dpc-logout-btn dpc-user-logged__logout" href="<?php echo esc_url( $logout_url ); ?>" title="Logout" aria-label="Logout">
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
							<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
							<polyline points="16 17 21 12 16 7"/>
							<line x1="21" y1="12" x2="9" y2="12"/>
						</svg>
					</a>
				</div>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		if ( 'comments' === $context ) {
			$style_desktop = (string) dpc_get_option( 'auth_comments_style_desktop', (string) dpc_get_option( 'auth_comments_style', 'inline' ) );
			$style_mobile  = (string) dpc_get_option( 'auth_comments_style_mobile', 'column' );
		} else {
			$style_desktop = ! empty( $atts['style_desktop'] )
				? (string) $atts['style_desktop']
				: ( ! empty( $atts['style'] )
					? (string) $atts['style']
					: (string) dpc_get_option( 'auth_shortcode_style_desktop', (string) dpc_get_option( 'auth_shortcode_style', 'inline' ) ) );

			$style_mobile = ! empty( $atts['style_mobile'] )
				? (string) $atts['style_mobile']
				: ( ! empty( $atts['style'] )
					? (string) $atts['style']
					: (string) dpc_get_option( 'auth_shortcode_style_mobile', 'icon' ) );
		}

		$valid_styles = [ 'icon', 'inline', 'column', 'fullwidth' ];
		if ( ! in_array( $style_desktop, $valid_styles, true ) ) {
			$style_desktop = 'inline';
		}
		if ( ! in_array( $style_mobile, $valid_styles, true ) ) {
			$style_mobile = 'icon';
		}

		$google   = new GoogleProvider();
		$telegram = new TelegramProvider();
		$discord  = new DiscordProvider();
		$twitter  = new TwitterProvider();
		$facebook = new FacebookProvider();

		$has_active_provider = $google->is_configured()
			|| $telegram->is_configured()
			|| $discord->is_configured()
			|| $twitter->is_configured()
			|| $facebook->is_configured();

		if ( ! $has_active_provider ) {
			return '';
		}

		$current_url_encoded = rawurlencode( $current_url );

		ob_start();
		?>
		<div class="dpc-social-login dpc-desktop-style-<?php echo esc_attr( $style_desktop ); ?> dpc-mobile-style-<?php echo esc_attr( $style_mobile ); ?> skin-<?php echo esc_attr( $skin ); ?>" style="<?php echo esc_attr( $size_vars ); ?>">
			<div class="dpc-social-login__buttons">
				<?php if ( $google->is_configured() ) : ?>
					<?php
					$google_start_url = add_query_arg(
						'redirect_to',
						$current_url_encoded,
						rest_url( self::REST_NAMESPACE . '/auth/google/start' )
					);
					?>
					<a href="<?php echo esc_url( $google_start_url ); ?>" class="dpc-btn dpc-social-btn dpc-btn-google dpc-social-btn--google" rel="nofollow" title="Google" aria-label="Google">
						<span class="dpc-btn__icon dpc-social-btn__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg">
								<path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
								<path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
								<path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
								<path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
							</svg>
						</span>
						<span class="dpc-btn__label dpc-social-btn__text">Google</span>
					</a>
				<?php endif; ?>

				<?php if ( $telegram->is_configured() ) : ?>
					<button
						type="button"
						class="dpc-btn dpc-social-btn dpc-btn-telegram"
						data-bot-id="<?php echo esc_attr( $telegram->get_bot_id() ); ?>"
						data-redirect="<?php echo esc_url( $current_url ); ?>"
						title="Telegram"
						aria-label="Telegram"
					>
						<span class="dpc-btn__icon dpc-social-btn__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg">
								<path d="M20.665 3.717l-17.73 6.837c-1.21.486-1.203 1.161-.222 1.462l4.552 1.42 10.532-6.645c.498-.303.953-.14.579.192l-8.533 7.701h-.002l.002.001-.314 4.692c.46 0 .663-.211.921-.46l2.211-2.15 4.599 3.397c.848.467 1.457.227 1.668-.785l3.019-14.228c.309-1.239-.473-1.8-1.282-1.434z" fill="currentColor"/>
							</svg>
						</span>
						<span class="dpc-btn__label dpc-social-btn__text">Telegram</span>
					</button>
				<?php endif; ?>

				<?php if ( $discord->is_configured() ) : ?>
					<?php
					$discord_start_url = add_query_arg(
						'redirect_to',
						$current_url_encoded,
						rest_url( self::REST_NAMESPACE . '/auth/discord/start' )
					);
					?>
					<a href="<?php echo esc_url( $discord_start_url ); ?>" class="dpc-btn dpc-social-btn dpc-btn-discord dpc-social-btn--discord" rel="nofollow" title="Discord" aria-label="Discord">
						<span class="dpc-btn__icon dpc-social-btn__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg">
								<path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994.021-.041.001-.09-.041-.106a13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.929 1.793 8.18 1.793 12.061 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.894.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.028zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z" fill="currentColor"/>
							</svg>
						</span>
						<span class="dpc-btn__label dpc-social-btn__text">Discord</span>
					</a>
				<?php endif; ?>

				<?php if ( $twitter->is_configured() ) : ?>
					<?php
					$twitter_start_url = add_query_arg(
						'redirect_to',
						$current_url_encoded,
						rest_url( self::REST_NAMESPACE . '/auth/twitter/start' )
					);
					?>
					<a href="<?php echo esc_url( $twitter_start_url ); ?>" class="dpc-btn dpc-social-btn dpc-btn-twitter dpc-social-btn--twitter" rel="nofollow" title="X" aria-label="X">
						<span class="dpc-btn__icon dpc-social-btn__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="16" height="16" xmlns="http://www.w3.org/2000/svg">
								<path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" fill="currentColor"/>
							</svg>
						</span>
						<span class="dpc-btn__label dpc-social-btn__text">X</span>
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
					<a href="<?php echo esc_url( $facebook_start_url ); ?>" class="dpc-btn dpc-social-btn dpc-btn-facebook dpc-social-btn--facebook" rel="nofollow" title="Facebook" aria-label="Facebook">
						<span class="dpc-btn__icon dpc-social-btn__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="18" height="18" xmlns="http://www.w3.org/2000/svg">
								<path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" fill="currentColor"/>
							</svg>
						</span>
						<span class="dpc-btn__label dpc-social-btn__text">Facebook</span>
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
			'telegram' => new TelegramProvider(),
			'twitter'  => new TwitterProvider(),
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
