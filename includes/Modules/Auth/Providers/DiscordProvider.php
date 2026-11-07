<?php
declare(strict_types=1);

namespace DiviProEngagement\Modules\Auth\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DiscordProvider {
	private string $client_id;
	private string $client_secret;
	private string $redirect_uri;

	public function __construct() {
		$this->client_id     = trim( (string) dpc_get_option( 'auth_discord_client_id', '' ) );
		$this->client_secret = trim( (string) dpc_get_option( 'auth_discord_client_secret', '' ) );
		$this->redirect_uri  = home_url( '/wp-json/dpc/v1/auth/discord' );
	}

	public function is_configured(): bool {
		return (bool) dpc_get_option( 'auth_discord_enable', false )
			&& '' !== $this->client_id
			&& '' !== $this->client_secret;
	}

	public function get_auth_url( string $state ): string {
		$params = [
			'client_id'     => $this->client_id,
			'redirect_uri'  => $this->redirect_uri,
			'response_type' => 'code',
			'scope'         => 'identify email',
			'state'         => $state,
			'prompt'        => (bool) dpc_get_option( 'auth_select_account', 1 ) ? 'consent' : 'none',
		];

		return 'https://discord.com/api/oauth2/authorize?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchange code for user profile data.
	 *
	 * @param string $code OAuth code.
	 * @return array{social_id: string, email: string, name: string, avatar: string}|\WP_Error
	 */
	public function get_user_profile( string $code ): array|\WP_Error {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'dpc_discord_not_configured', __( 'Discord OAuth не настроен.', 'divi-pro-engagement' ) );
		}

		$token_response = wp_remote_post(
			'https://discord.com/api/oauth2/token',
			[
				'timeout' => 15,
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
				],
				'body'    => [
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => $this->redirect_uri,
				],
			]
		);

		if ( is_wp_error( $token_response ) ) {
			return $token_response;
		}

		$token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );
		if ( ! is_array( $token_body ) || empty( $token_body['access_token'] ) ) {
			return new \WP_Error( 'dpc_discord_token_error', __( 'Не удалось получить токен доступа Discord.', 'divi-pro-engagement' ) );
		}

		$access_token  = (string) $token_body['access_token'];
		$user_response = wp_remote_get(
			'https://discord.com/api/users/@me',
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $access_token,
				],
			]
		);

		if ( is_wp_error( $user_response ) ) {
			return $user_response;
		}

		$user_data = json_decode( wp_remote_retrieve_body( $user_response ), true );
		if ( ! is_array( $user_data ) || empty( $user_data['id'] ) ) {
			return new \WP_Error( 'dpc_discord_user_error', __( 'Не удалось получить профиль Discord.', 'divi-pro-engagement' ) );
		}

		$id          = sanitize_text_field( (string) $user_data['id'] );
		$email       = isset( $user_data['email'] ) ? sanitize_email( (string) $user_data['email'] ) : '';
		$username    = isset( $user_data['username'] ) ? sanitize_text_field( (string) $user_data['username'] ) : '';
		$global_name = ! empty( $user_data['global_name'] ) ? sanitize_text_field( (string) $user_data['global_name'] ) : '';
		$name        = '' !== $global_name ? $global_name : $username;

		$avatar_hash = isset( $user_data['avatar'] ) ? (string) $user_data['avatar'] : '';
		$avatar_url  = '';
		if ( '' !== $avatar_hash ) {
			$avatar_url = sprintf( 'https://cdn.discordapp.com/avatars/%s/%s.png', $id, $avatar_hash );
		} else {
			$discriminator = isset( $user_data['discriminator'] ) ? (int) $user_data['discriminator'] : 0;
			$default_index = $discriminator > 0 ? ( $discriminator % 5 ) : ( ( (int) $id >> 22 ) % 6 );
			$avatar_url    = sprintf( 'https://cdn.discordapp.com/embed/avatars/%d.png', $default_index );
		}

		return [
			'social_id' => $id,
			'email'     => $email,
			'name'      => '' !== $name ? $name : 'DiscordUser',
			'avatar'    => esc_url_raw( $avatar_url ),
		];
	}
}
