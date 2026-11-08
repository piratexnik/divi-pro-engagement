<?php
declare(strict_types=1);

namespace DiviProEngagement\Modules\Auth\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GoogleProvider {
	private string $client_id;
	private string $client_secret;
	private string $redirect_uri;

	public function __construct() {
		$this->client_id     = trim( (string) dpc_get_option( 'auth_google_client_id', '' ) );
		$this->client_secret = trim( (string) dpc_get_option( 'auth_google_client_secret', '' ) );
		$this->redirect_uri  = home_url( '/wp-json/dpc/v1/auth/google' );
	}

	public function is_configured(): bool {
		return (bool) dpc_get_option( 'auth_google_enable', false )
			&& '' !== $this->client_id
			&& '' !== $this->client_secret;
	}

	public function get_auth_url( string $state ): string {
		$params = [
			'client_id'     => $this->client_id,
			'redirect_uri'  => $this->redirect_uri,
			'response_type' => 'code',
			'scope'         => 'openid email profile',
			'state'         => $state,
			'access_type'   => 'online',
		];

		if ( (bool) dpc_get_option( 'auth_select_account', 1 ) ) {
			$params['prompt'] = 'select_account';
		}

		return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchange authorization code for user profile data.
	 *
	 * @param string $code OAuth authorization code.
	 * @return array{social_id: string, email: string, name: string, avatar: string}|\WP_Error
	 */
	public function get_user_profile( string $code ): array|\WP_Error {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'dpc_google_not_configured', __( 'Google OAuth не настроен.', 'divi-pro-engagement' ) );
		}

		$token_response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			[
				'timeout' => 15,
				'body'    => [
					'code'          => $code,
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
					'redirect_uri'  => $this->redirect_uri,
					'grant_type'    => 'authorization_code',
				],
			]
		);

		if ( is_wp_error( $token_response ) ) {
			return $token_response;
		}

		$token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );
		if ( ! is_array( $token_body ) || empty( $token_body['access_token'] ) ) {
			return new \WP_Error( 'dpc_google_token_error', __( 'Не удалось получить токен доступа от Google.', 'divi-pro-engagement' ) );
		}

		$access_token  = (string) $token_body['access_token'];
		$user_response = wp_remote_get(
			'https://www.googleapis.com/oauth2/v3/userinfo',
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
		if ( ! is_array( $user_data ) || empty( $user_data['sub'] ) ) {
			return new \WP_Error( 'dpc_google_userinfo_error', __( 'Не удалось получить профиль пользователя Google.', 'divi-pro-engagement' ) );
		}

		$sub            = sanitize_text_field( (string) $user_data['sub'] );
		$email_verified = ! empty( $user_data['email_verified'] ) && true === (bool) $user_data['email_verified'];
		$email          = ( $email_verified && isset( $user_data['email'] ) ) ? sanitize_email( (string) $user_data['email'] ) : '';
		$name           = isset( $user_data['name'] ) ? sanitize_text_field( (string) $user_data['name'] ) : '';
		$picture        = isset( $user_data['picture'] ) ? esc_url_raw( (string) $user_data['picture'] ) : '';

		if ( '' === $name && '' !== $email ) {
			$name = strstr( $email, '@', true ) ?: 'User';
		}

		return [
			'social_id' => $sub,
			'email'     => $email,
			'name'      => $name,
			'avatar'    => $picture,
		];
	}
}
