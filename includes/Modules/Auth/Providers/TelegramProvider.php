<?php
declare(strict_types=1);

namespace DiviProEngagement\Modules\Auth\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TelegramProvider {
	private const ALLOWED_TELEGRAM_FIELDS = [
		'id',
		'first_name',
		'last_name',
		'username',
		'photo_url',
		'auth_date',
	];

	private string $bot_username;
	private string $bot_token;

	public function __construct() {
		$raw_username       = trim( (string) dpc_get_option( 'auth_telegram_bot_username', '' ) );
		$this->bot_username = ltrim( $raw_username, '@' );
		$this->bot_token    = trim( (string) dpc_get_option( 'auth_telegram_bot_token', '' ) );
	}

	public function is_configured(): bool {
		return (bool) dpc_get_option( 'auth_telegram_enable', false )
			&& '' !== $this->get_bot_id()
			&& '' !== $this->bot_token;
	}

	public function get_bot_id(): string {
		if ( '' === $this->bot_token || ! str_contains( $this->bot_token, ':' ) ) {
			return '';
		}
		$parts = explode( ':', $this->bot_token, 2 );
		return preg_replace( '/[^0-9]/', '', $parts[0] ) ?: '';
	}

	public function get_bot_username(): string {
		return $this->bot_username;
	}

	public function get_callback_url( string $current_url ): string {
		return add_query_arg(
			'redirect_to',
			rawurlencode( $current_url ),
			home_url( '/wp-json/dpc/v1/auth/telegram' )
		);
	}

	/**
	 * Verify Telegram HMAC-SHA256 signature and build standardized user profile.
	 *
	 * @param array<string, mixed> $request_params Query parameters from Telegram callback.
	 * @return array{social_id: string, email: string, name: string, avatar: string}|\WP_Error
	 */
	public function verify_and_get_profile( array $request_params ): array|\WP_Error {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'dpc_telegram_not_configured', __( 'Telegram авторизация не настроена.', 'divi-pro-engagement' ) );
		}

		if ( empty( $request_params['id'] ) || empty( $request_params['hash'] ) || empty( $request_params['auth_date'] ) ) {
			return new \WP_Error( 'dpc_telegram_missing_params', __( 'Отсутствуют обязательные параметры ответа Telegram.', 'divi-pro-engagement' ) );
		}

		$received_hash = (string) $request_params['hash'];
		$auth_date     = (int) $request_params['auth_date'];

		if ( abs( time() - $auth_date ) > 600 ) {
			return new \WP_Error( 'dpc_telegram_expired', __( 'Данные авторизации Telegram устарели.', 'divi-pro-engagement' ) );
		}

		$check_data = [];
		foreach ( self::ALLOWED_TELEGRAM_FIELDS as $field ) {
			if ( isset( $request_params[ $field ] ) && '' !== (string) $request_params[ $field ] ) {
				$check_data[ $field ] = (string) wp_unslash( $request_params[ $field ] );
			}
		}

		ksort( $check_data );

		$data_check_arr = [];
		foreach ( $check_data as $key => $value ) {
			$data_check_arr[] = $key . '=' . $value;
		}
		$data_check_string = implode( "\n", $data_check_arr );

		$secret_key      = hash( 'sha256', $this->bot_token, true );
		$calculated_hash = hash_hmac( 'sha256', $data_check_string, $secret_key );

		if ( ! hash_equals( $calculated_hash, $received_hash ) ) {
			return new \WP_Error( 'dpc_telegram_invalid_hash', __( 'Неверная криптографическая подпись Telegram.', 'divi-pro-engagement' ) );
		}

		$replay_key = 'dpc_tg_used_' . substr( $calculated_hash, 0, 32 );
		if ( false !== get_transient( $replay_key ) ) {
			return new \WP_Error( 'dpc_telegram_replay', __( 'Повторное использование подписи Telegram заблокировано.', 'divi-pro-engagement' ) );
		}
		set_transient( $replay_key, 1, 600 );

		$social_id  = sanitize_text_field( (string) $check_data['id'] );
		$first_name = isset( $check_data['first_name'] ) ? sanitize_text_field( $check_data['first_name'] ) : '';
		$last_name  = isset( $check_data['last_name'] ) ? sanitize_text_field( $check_data['last_name'] ) : '';
		$username   = isset( $check_data['username'] ) ? sanitize_text_field( $check_data['username'] ) : '';
		$photo_url  = isset( $check_data['photo_url'] ) ? esc_url_raw( $check_data['photo_url'] ) : '';

		$full_name = trim( $first_name . ' ' . $last_name );
		if ( '' === $full_name ) {
			$full_name = '' !== $username ? $username : 'Telegram User';
		}

		$email = 'tg_' . $social_id . '@telegram.user';

		return [
			'social_id' => $social_id,
			'email'     => $email,
			'name'      => $full_name,
			'avatar'    => $photo_url,
		];
	}
}
