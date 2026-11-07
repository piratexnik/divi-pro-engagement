<?php
declare(strict_types=1);

namespace DiviProEngagement\Modules\Auth\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FacebookProvider {
	private string $app_id;
	private string $app_secret;
	private string $redirect_uri;

	public function __construct() {
		$this->app_id      = trim( (string) dpc_get_option( 'auth_facebook_app_id', '' ) );
		$this->app_secret  = trim( (string) dpc_get_option( 'auth_facebook_app_secret', '' ) );
		$this->redirect_uri = home_url( '/wp-json/dpc/v1/auth/facebook' );
	}

	public function is_configured(): bool {
		return (bool) dpc_get_option( 'auth_facebook_enable', false )
			&& '' !== $this->app_id
			&& '' !== $this->app_secret;
	}

	public function get_auth_url( string $state ): string {
		$params = [
			'client_id'     => $this->app_id,
			'redirect_uri'  => $this->redirect_uri,
			'state'         => $state,
			'response_type' => 'code',
			'scope'         => 'public_profile,email',
		];

		return 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchange code for user profile data.
	 *
	 * @param string $code OAuth code.
	 * @return array{social_id: string, email: string, name: string, avatar: string}|\WP_Error
	 */
	public function get_user_profile( string $code ): array|\WP_Error {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'dpc_facebook_not_configured', __( 'Facebook OAuth не настроен.', 'divi-pro-engagement' ) );
		}

		$token_url = add_query_arg(
			[
				'client_id'     => $this->app_id,
				'client_secret' => $this->app_secret,
				'redirect_uri'  => $this->redirect_uri,
				'code'          => $code,
			],
			'https://graph.facebook.com/v19.0/oauth/access_token'
		);

		$token_response = wp_remote_get( $token_url, [ 'timeout' => 15 ] );
		if ( is_wp_error( $token_response ) ) {
			return $token_response;
		}

		$token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );
		if ( ! is_array( $token_body ) || empty( $token_body['access_token'] ) ) {
			return new \WP_Error( 'dpc_facebook_token_error', __( 'Не удалось получить токен Facebook.', 'divi-pro-engagement' ) );
		}

		$access_token = (string) $token_body['access_token'];
		$user_url     = add_query_arg(
			[
				'fields'       => 'id,name,email,picture.type(large)',
				'access_token' => $access_token,
			],
			'https://graph.facebook.com/me'
		);

		$user_response = wp_remote_get( $user_url, [ 'timeout' => 15 ] );
		if ( is_wp_error( $user_response ) ) {
			return $user_response;
		}

		$user_data = json_decode( wp_remote_retrieve_body( $user_response ), true );
		if ( ! is_array( $user_data ) || empty( $user_data['id'] ) ) {
			return new \WP_Error( 'dpc_facebook_user_error', __( 'Не удалось получить данные профиля Facebook.', 'divi-pro-engagement' ) );
		}

		$id     = sanitize_text_field( (string) $user_data['id'] );
		$email  = isset( $user_data['email'] ) ? sanitize_email( (string) $user_data['email'] ) : '';
		$name   = isset( $user_data['name'] ) ? sanitize_text_field( (string) $user_data['name'] ) : 'FacebookUser';
		$avatar = '';

		if ( ! empty( $user_data['picture']['data']['url'] ) ) {
			$avatar = esc_url_raw( (string) $user_data['picture']['data']['url'] );
		}

		return [
			'social_id' => $id,
			'email'     => $email,
			'name'      => $name,
			'avatar'    => $avatar,
		];
	}
}
