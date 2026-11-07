<?php
declare(strict_types=1);

namespace DiviProEngagement\Modules\Auth\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TwitterProvider {
	private string $client_id;
	private string $client_secret;
	private string $redirect_uri;

	public function __construct() {
		$this->client_id     = trim( (string) dpc_get_option( 'auth_twitter_client_id', '' ) );
		$this->client_secret = trim( (string) dpc_get_option( 'auth_twitter_client_secret', '' ) );
		$this->redirect_uri  = home_url( '/wp-json/dpc/v1/auth/twitter' );
	}

	public function is_configured(): bool {
		return (bool) dpc_get_option( 'auth_twitter_enable', false )
			&& '' !== $this->client_id
			&& '' !== $this->client_secret;
	}

	public function get_auth_url( string $state ): string {
		$code_verifier  = wp_generate_password( 64, false, false );
		$code_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );

		set_transient( 'dpc_twitter_pkce_' . $state, $code_verifier, 600 );

		$params = [
			'response_type'         => 'code',
			'client_id'             => $this->client_id,
			'redirect_uri'          => $this->redirect_uri,
			'scope'                 => 'users.read tweet.read',
			'state'                 => $state,
			'code_challenge'        => $code_challenge,
			'code_challenge_method' => 'S256',
		];

		return 'https://twitter.com/i/oauth2/authorize?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchange authorization code for X (Twitter) user profile data.
	 *
	 * @param string $code  OAuth 2.0 authorization code.
	 * @param string $state OAuth state used to retrieve PKCE code_verifier.
	 * @return array{social_id: string, email: string, name: string, avatar: string}|\WP_Error
	 */
	public function get_user_profile( string $code, string $state = '' ): array|\WP_Error {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'dpc_twitter_not_configured', __( 'X (Twitter) OAuth не настроен.', 'divi-pro-engagement' ) );
		}

		$code_verifier = '';
		if ( '' !== $state ) {
			$code_verifier = (string) get_transient( 'dpc_twitter_pkce_' . $state );
			delete_transient( 'dpc_twitter_pkce_' . $state );
		}

		$basic_auth = base64_encode( $this->client_id . ':' . $this->client_secret );

		$token_response = wp_remote_post(
			'https://api.twitter.com/2/oauth2/token',
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Basic ' . $basic_auth,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				],
				'body'    => [
					'code'          => $code,
					'grant_type'    => 'authorization_code',
					'client_id'     => $this->client_id,
					'redirect_uri'  => $this->redirect_uri,
					'code_verifier' => $code_verifier,
				],
			]
		);

		if ( is_wp_error( $token_response ) ) {
			return $token_response;
		}

		$token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );
		if ( ! is_array( $token_body ) || empty( $token_body['access_token'] ) ) {
			return new \WP_Error( 'dpc_twitter_token_error', __( 'Не удалось получить токен доступа X (Twitter).', 'divi-pro-engagement' ) );
		}

		$access_token  = (string) $token_body['access_token'];
		$user_response = wp_remote_get(
			'https://api.twitter.com/2/users/me?user.fields=profile_image_url,name,username',
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

		$user_body = json_decode( wp_remote_retrieve_body( $user_response ), true );
		$user_data = isset( $user_body['data'] ) && is_array( $user_body['data'] ) ? $user_body['data'] : [];

		if ( empty( $user_data['id'] ) ) {
			return new \WP_Error( 'dpc_twitter_user_error', __( 'Не удалось получить профиль пользователя X (Twitter).', 'divi-pro-engagement' ) );
		}

		$id       = sanitize_text_field( (string) $user_data['id'] );
		$username = isset( $user_data['username'] ) ? sanitize_text_field( (string) $user_data['username'] ) : '';
		$name     = ! empty( $user_data['name'] ) ? sanitize_text_field( (string) $user_data['name'] ) : $username;
		$avatar   = ! empty( $user_data['profile_image_url'] ) ? esc_url_raw( (string) $user_data['profile_image_url'] ) : '';

		return [
			'social_id' => $id,
			'email'     => 'x_' . $id . '@twitter.user',
			'name'      => '' !== $name ? $name : 'X User',
			'avatar'    => $avatar,
		];
	}
}
