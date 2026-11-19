<?php
declare(strict_types=1);

namespace DiviProEngagement\Modules\Downloads;

use DiviProEngagement\Core\Init;
use DiviProEngagement\Modules\Auth\AuthManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DownloadGatekeeper {
	public const DEFAULT_PATH       = 'joomla-legacy/dmdocuments';
	public const DEFAULT_EXTENSIONS = 'pdf,rar,zip,dwg,dxf,doc,docx,xls,xlsx';
	public const DEFAULT_MODE       = 'gateway';
	public const VALID_MODES        = [ 'gateway', 'htaccess_403' ];

	private const HTACCESS_BEGIN = '# BEGIN DPC Download Gatekeeper';
	private const HTACCESS_END   = '# END DPC Download Gatekeeper';

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_footer', [ $this, 'render_modal_html' ] );

		if ( ! is_admin() && ! Init::is_visual_builder_active() ) {
			add_action( 'init', [ $this, 'handle_gateway_request' ], 1 );
		}
	}

	/**
	 * Normalize protection mode value.
	 */
	public static function normalize_mode( string $mode ): string {
		$clean = sanitize_key( $mode );
		return in_array( $clean, self::VALID_MODES, true ) ? $clean : self::DEFAULT_MODE;
	}

	/**
	 * Normalize relative path inside wp-content/uploads/.
	 */
	public static function normalize_relative_path( string $path ): string {
		$clean = str_replace( '\\', '/', trim( $path ) );
		$clean = trim( $clean, "/ \t\n\r\0\x0B" );
		$clean = (string) preg_replace( '#/+#', '/', $clean );

		if ( '' !== $clean && ( str_contains( $clean, '..' ) || 0 !== validate_file( $clean ) ) ) {
			return '';
		}

		return $clean;
	}

	/**
	 * Parse comma-separated extensions into sanitized lowercase list.
	 *
	 * @return array<int, string>
	 */
	public static function parse_extensions( string $raw ): array {
		$parts = explode( ',', $raw );
		$clean = [];

		foreach ( $parts as $part ) {
			$ext = (string) preg_replace( '/[^a-z0-9]/', '', strtolower( trim( $part ) ) );
			if ( '' !== $ext && ! in_array( $ext, $clean, true ) ) {
				$clean[] = $ext;
			}
		}

		return $clean;
	}

	/**
	 * Localized strings for Download Gatekeeper modal and toast.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function get_all_dictionaries(): array {
		return [
			'uk' => [
				'title'       => '🔒 Доступ лише для авторизованих користувачів',
				'description' => 'Завантаження технічної документації, каталогів PDF та креслень AutoCAD доступне після швидкої авторизації. Увійдіть за кілька секунд:',
				'close'       => 'Закрити',
				'login_btn'   => 'Увійти на сайт',
				'toast_title' => '✓ Дякуємо за авторизацію! Завантаження розпочато.',
			],
			'ru' => [
				'title'       => '🔒 Доступ только для авторизованных пользователей',
				'description' => 'Скачивание технической документации, каталогов PDF и чертежей AutoCAD доступно после быстрой авторизации:',
				'close'       => 'Закрыть',
				'login_btn'   => 'Войти на сайт',
				'toast_title' => '✓ Спасибо за авторизацию! Загрузка началась.',
			],
			'en' => [
				'title'       => '🔒 Access for Authorized Users Only',
				'description' => 'Downloading technical documentation, PDF catalogs, and AutoCAD drawings is available after quick sign-in:',
				'close'       => 'Close',
				'login_btn'   => 'Sign In',
				'toast_title' => '✓ Thank you for signing in! Your download is starting.',
			],
		];
	}

	/**
	 * Build frontend config payload passed via dpcConfig.gatekeeper.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_frontend_config(): array {
		$protected_path = self::normalize_relative_path(
			(string) dpc_get_option( 'dl_protected_path', self::DEFAULT_PATH )
		);
		$extensions     = self::parse_extensions(
			(string) dpc_get_option( 'dl_extensions', self::DEFAULT_EXTENSIONS )
		);
		$uploads        = wp_upload_dir();
		$uploads_url    = isset( $uploads['baseurl'] ) ? untrailingslashit( (string) $uploads['baseurl'] ) : content_url( '/uploads' );

		return [
			'enabled'             => (bool) dpc_get_option( 'enable_download_gatekeeper', false ),
			'mode'                => self::normalize_mode( (string) dpc_get_option( 'dl_protection_mode', self::DEFAULT_MODE ) ),
			'protectedPath'       => $protected_path,
			'extensions'          => $extensions,
			'interceptAllUploads' => (bool) dpc_get_option( 'dl_intercept_all_uploads', false ),
			'uploadsUrl'          => $uploads_url,
			'isLoggedIn'          => is_user_logged_in(),
			'i18n'                => self::get_all_dictionaries(),
		];
	}

	/**
	 * Inspect current protected directory and .htaccess state.
	 *
	 * @return array{clean_rel: string, target_dir: string, dir_exists: bool, htaccess_exists: bool, active_mode: string, mode_label: string}
	 */
	public static function get_htaccess_status( ?string $relative_path = null ): array {
		$raw_path  = null !== $relative_path
			? $relative_path
			: (string) dpc_get_option( 'dl_protected_path', self::DEFAULT_PATH );
		$clean_rel = self::normalize_relative_path( $raw_path );

		$uploads    = wp_upload_dir();
		$basedir    = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
		$target_dir = ( '' !== $clean_rel && '' !== $basedir ) ? trailingslashit( $basedir ) . $clean_rel : '';
		$dir_exists = '' !== $target_dir && is_dir( $target_dir );

		$htaccess_file   = $dir_exists ? trailingslashit( $target_dir ) . '.htaccess' : '';
		$htaccess_exists = '' !== $htaccess_file && file_exists( $htaccess_file );
		$active_mode     = 'inactive';
		$mode_label      = __( 'Не активна', 'divi-pro-engagement' );

		if ( $htaccess_exists && is_readable( $htaccess_file ) ) {
			$content = file_get_contents( $htaccess_file );
			if ( false !== $content && str_contains( $content, self::HTACCESS_BEGIN ) ) {
				$pattern = '/' . preg_quote( self::HTACCESS_BEGIN, '/' ) . '(.*?)' . preg_quote( self::HTACCESS_END, '/' ) . '/s';
				if ( preg_match( $pattern, $content, $matches ) ) {
					$block = $matches[1];
					if ( str_contains( $block, 'dpc_dl_gateway=1' ) ) {
						$active_mode = 'gateway';
						$mode_label  = __( 'Активна: PHP-шлюз', 'divi-pro-engagement' );
					} elseif ( str_contains( $block, 'RewriteRule' ) && str_contains( $block, '[F' ) ) {
						$active_mode = 'htaccess_403';
						$mode_label  = __( 'Активна: 403 Apache', 'divi-pro-engagement' );
					}
				}
			}
		}

		return [
			'clean_rel'       => $clean_rel,
			'target_dir'      => $target_dir,
			'dir_exists'      => $dir_exists,
			'htaccess_exists' => $htaccess_exists,
			'active_mode'     => $active_mode,
			'mode_label'      => $mode_label,
		];
	}

	/**
	 * Synchronize .htaccess protection rules in the protected uploads directory.
	 *
	 * @param array<string, mixed>|null $override_settings Optional fresh settings array during sanitize_option.
	 * @param string|null               $previous_path     Optional previous relative path to clean up if path changed.
	 */
	public static function sync_htaccess_rules( ?array $override_settings = null, ?string $previous_path = null ): bool {
		$enabled = null !== $override_settings
			? ! empty( $override_settings['enable_download_gatekeeper'] )
			: (bool) dpc_get_option( 'enable_download_gatekeeper', false );

		$htaccess_enabled = null !== $override_settings
			? ! empty( $override_settings['dl_htaccess_enable'] )
			: (bool) dpc_get_option( 'dl_htaccess_enable', false );

		$raw_path = null !== $override_settings && array_key_exists( 'dl_protected_path', $override_settings )
			? (string) $override_settings['dl_protected_path']
			: (string) dpc_get_option( 'dl_protected_path', self::DEFAULT_PATH );

		$raw_exts = null !== $override_settings && array_key_exists( 'dl_extensions', $override_settings )
			? (string) $override_settings['dl_extensions']
			: (string) dpc_get_option( 'dl_extensions', self::DEFAULT_EXTENSIONS );

		$raw_mode = null !== $override_settings && array_key_exists( 'dl_protection_mode', $override_settings )
			? (string) $override_settings['dl_protection_mode']
			: (string) dpc_get_option( 'dl_protection_mode', self::DEFAULT_MODE );

		$mode           = self::normalize_mode( $raw_mode );
		$protected_path = self::normalize_relative_path( $raw_path );

		if ( null !== $previous_path ) {
			$prev_clean = self::normalize_relative_path( $previous_path );
			if ( '' !== $prev_clean && $prev_clean !== $protected_path ) {
				self::clear_htaccess_block( $prev_clean );
			}
		}

		if ( '' === $protected_path ) {
			return false;
		}

		$target_dir = self::resolve_safe_uploads_dir( $protected_path );
		if ( '' === $target_dir ) {
			return false;
		}

		$htaccess_file = trailingslashit( $target_dir ) . '.htaccess';

		if ( ! $enabled || ! $htaccess_enabled ) {
			self::clear_htaccess_block( $protected_path );
			return true;
		}

		$extensions = self::parse_extensions( $raw_exts );
		if ( empty( $extensions ) ) {
			$extensions = self::parse_extensions( self::DEFAULT_EXTENSIONS );
		}

		$ext_pattern = implode( '|', $extensions );
		$rules_lines = [
			self::HTACCESS_BEGIN,
			'Options -Indexes',
			'<Files ".htaccess">',
			'  <IfModule mod_authz_core.c>',
			'    Require all denied',
			'  </IfModule>',
			'  <IfModule !mod_authz_core.c>',
			'    Order allow,deny',
			'    Deny from all',
			'  </IfModule>',
			'</Files>',
			'<IfModule mod_headers.c>',
			'  Header set X-Robots-Tag "noindex, nofollow, noarchive"',
			'</IfModule>',
		];

		if ( 'gateway' === $mode ) {
			$home_path  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$index_path = '/' . ltrim( trailingslashit( $home_path ), '/' ) . 'index.php';

			$rules_lines[] = '<IfModule mod_rewrite.c>';
			$rules_lines[] = '  RewriteEngine On';
			$rules_lines[] = '  RewriteRule \.(' . $ext_pattern . ')$ ' . $index_path . '?dpc_dl_gateway=1 [L,QSA,NC]';
			$rules_lines[] = '</IfModule>';
		} else {
			$rules_lines[] = '<IfModule mod_rewrite.c>';
			$rules_lines[] = '  RewriteEngine On';
			$rules_lines[] = '  RewriteCond %{HTTP_COOKIE} !wordpress_logged_in_ [NC]';
			$rules_lines[] = '  RewriteRule \.(' . $ext_pattern . ')$ - [F,L,NC]';
			$rules_lines[] = '</IfModule>';
		}

		$rules_lines[] = self::HTACCESS_END;
		$block         = implode( "\n", $rules_lines );

		$existing = '';
		if ( file_exists( $htaccess_file ) ) {
			if ( ! is_writable( $htaccess_file ) ) {
				return false;
			}
			$read = file_get_contents( $htaccess_file );
			if ( false !== $read ) {
				$existing = $read;
			}
		} elseif ( ! is_writable( $target_dir ) ) {
			return false;
		}

		$updated = self::upsert_marker_block( $existing, $block );
		return false !== file_put_contents( $htaccess_file, $updated, LOCK_EX );
	}

	/**
	 * Transparent PHP gateway handler for protected file requests routed via .htaccess.
	 */
	public function handle_gateway_request(): void {
		if ( ! isset( $_GET['dpc_dl_gateway'] ) || '1' !== (string) $_GET['dpc_dl_gateway'] ) {
			return;
		}

		if ( is_admin() || Init::is_visual_builder_active() || ! (bool) dpc_get_option( 'enable_download_gatekeeper', false ) ) {
			return;
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}

		$protected_path = self::normalize_relative_path(
			(string) dpc_get_option( 'dl_protected_path', self::DEFAULT_PATH )
		);
		if ( '' === $protected_path ) {
			$this->send_not_found();
		}

		$base_real = self::resolve_safe_uploads_dir( $protected_path );
		if ( '' === $base_real ) {
			$this->send_not_found();
		}

		$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$raw_url_path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$decoded_path = rawurldecode( $raw_url_path );

		if ( '' === $decoded_path || str_contains( $decoded_path, "\0" ) || str_contains( $decoded_path, '\\' ) ) {
			$this->send_not_found();
		}

		$uploads          = wp_upload_dir();
		$uploads_url      = isset( $uploads['baseurl'] ) ? untrailingslashit( (string) $uploads['baseurl'] ) : content_url( '/uploads' );
		$uploads_url_path = '/' . trim( (string) wp_parse_url( $uploads_url, PHP_URL_PATH ), '/' );
		$expected_prefix  = rtrim( $uploads_url_path, '/' ) . '/' . $protected_path . '/';

		if ( ! str_starts_with( $decoded_path, $expected_prefix ) ) {
			$this->send_not_found();
		}

		$relative_file = substr( $decoded_path, strlen( $expected_prefix ) );
		if ( '' === $relative_file || str_starts_with( $relative_file, '/' ) || str_ends_with( $relative_file, '/' ) ) {
			$this->send_not_found();
		}

		$segments = explode( '/', $relative_file );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment || str_starts_with( $segment, '.' ) ) {
				$this->send_not_found();
			}
		}

		$candidate = $base_real . DIRECTORY_SEPARATOR . implode( DIRECTORY_SEPARATOR, $segments );
		$file_real = realpath( $candidate );

		if (
			false === $file_real
			|| ! str_starts_with( $file_real, $base_real . DIRECTORY_SEPARATOR )
			|| ! is_file( $file_real )
			|| ! is_readable( $file_real )
		) {
			$this->send_not_found();
		}

		$ext          = strtolower( (string) pathinfo( $file_real, PATHINFO_EXTENSION ) );
		$allowed_exts = self::parse_extensions( (string) dpc_get_option( 'dl_extensions', self::DEFAULT_EXTENSIONS ) );
		if ( empty( $allowed_exts ) ) {
			$allowed_exts = self::parse_extensions( self::DEFAULT_EXTENSIONS );
		}

		if ( '' === $ext || ! in_array( $ext, $allowed_exts, true ) ) {
			$this->send_not_found();
		}

		if ( ! is_user_logged_in() ) {
			$this->redirect_guest_to_auth_modal( $expected_prefix, $segments );
		}

		$this->stream_file( $file_real, $ext );
	}

	/**
	 * Redirect unauthenticated visitor back to referer (or home) with modal trigger query parameters.
	 *
	 * @param string             $expected_prefix Normalized URL path prefix ending with '/'.
	 * @param array<int, string> $segments        Validated relative path segments.
	 */
	private function redirect_guest_to_auth_modal( string $expected_prefix, array $segments ): never {
		$home_url  = function_exists( 'pll_home_url' ) ? (string) pll_home_url() : home_url( '/' );
		$site_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$referer   = wp_get_raw_referer();

		$redirect_base = $home_url;
		if ( is_string( $referer ) && '' !== $referer ) {
			$ref_host = strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) );
			$ref_path = rawurldecode( (string) wp_parse_url( $referer, PHP_URL_PATH ) );

			if (
				'' !== $ref_host
				&& $ref_host === $site_host
				&& ! str_starts_with( $ref_path, $expected_prefix )
				&& ! str_contains( $referer, 'dpc_dl_gateway' )
				&& ! str_contains( $referer, 'dpc_auth_required' )
			) {
				$redirect_base = $referer;
			}
		}

		$encoded_segments = array_map( 'rawurlencode', $segments );
		$target_url_path  = $expected_prefix . implode( '/', $encoded_segments );

		$redirect_url = add_query_arg(
			[
				'dpc_auth_required' => '1',
				'dpc_dl_target'     => $target_url_path,
			],
			$redirect_base
		);

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		wp_safe_redirect( $redirect_url, 302 );
		exit;
	}

	/**
	 * Stream validated file to authenticated user in 1 MB chunks.
	 */
	private function stream_file( string $file_real, string $ext ): never {
		$mime_type = $this->detect_mime_type( $file_real, $ext );

		$inline_exts = [ 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp' ];
		$disposition = in_array( $ext, $inline_exts, true ) ? 'inline' : 'attachment';

		$basename       = basename( $file_real );
		$ascii_fallback = (string) preg_replace( '/[^\x20-\x7E]/', '_', $basename );
		$ascii_fallback = str_replace( [ '"', '\\', ';', "\r", "\n" ], '_', $ascii_fallback );
		if ( '' === trim( $ascii_fallback, '_ .' ) ) {
			$ascii_fallback = 'download.' . $ext;
		}
		$rfc5987_name = rawurlencode( $basename );

		if ( PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		@ini_set( 'zlib.output_compression', 'Off' );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 0 );

		$filesize = filesize( $file_real );

		status_header( 200 );
		header( 'Content-Type: ' . $mime_type );
		header(
			sprintf(
				'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
				$disposition,
				$ascii_fallback,
				$rfc5987_name
			)
		);
		if ( false !== $filesize ) {
			header( 'Content-Length: ' . (string) $filesize );
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		header( 'Cache-Control: private, max-age=3600' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $file_real, 'rb' );
		if ( false === $handle ) {
			$this->send_not_found();
		}

		$chunk_size = 1048576; // 1 MB
		while ( ! feof( $handle ) ) {
			if ( connection_aborted() ) {
				break;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$buffer = fread( $handle, $chunk_size );
			if ( false === $buffer ) {
				break;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file streaming.
			echo $buffer;
			flush();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );
		exit;
	}

	/**
	 * Detect MIME type using WordPress mapping, explicit CAD/PDF fallbacks, and mime_content_type().
	 */
	private function detect_mime_type( string $file_real, string $ext ): string {
		$checked = wp_check_filetype( $file_real );
		if ( ! empty( $checked['type'] ) && is_string( $checked['type'] ) ) {
			return $checked['type'];
		}

		$fallback_map = [
			'pdf'  => 'application/pdf',
			'dwg'  => 'application/acad',
			'dxf'  => 'application/dxf',
			'rar'  => 'application/x-rar-compressed',
			'zip'  => 'application/zip',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt'  => 'application/vnd.ms-powerpoint',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		];

		if ( isset( $fallback_map[ $ext ] ) ) {
			return $fallback_map[ $ext ];
		}

		if ( function_exists( 'mime_content_type' ) ) {
			$detected = mime_content_type( $file_real );
			if ( is_string( $detected ) && '' !== $detected ) {
				return $detected;
			}
		}

		return 'application/octet-stream';
	}

	/**
	 * Send 404 response and terminate execution.
	 */
	private function send_not_found(): never {
		status_header( 404 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
		exit;
	}

	/**
	 * Admin AJAX handler: Regenerate .htaccess rules without reloading the settings page.
	 */
	public static function handle_ajax_regenerate_htaccess(): void {
		check_ajax_referer( 'dpc_dl_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Недостаточно прав для выполнения действия.', 'divi-pro-engagement' ) ], 403 );
		}

		$ok     = self::sync_htaccess_rules();
		$status = self::get_htaccess_status();

		if ( ! $ok && (bool) dpc_get_option( 'enable_download_gatekeeper', false ) && (bool) dpc_get_option( 'dl_htaccess_enable', false ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Не удалось записать .htaccess. Проверьте существование папки и права на запись.', 'divi-pro-engagement' ),
					'status'  => $status,
				]
			);
		}

		wp_send_json_success(
			[
				'message' => __( 'Правила .htaccess успешно обновлены.', 'divi-pro-engagement' ),
				'status'  => $status,
			]
		);
	}

	/**
	 * Admin AJAX handler: Perform live HEAD request test against a real file in the protected directory.
	 */
	public static function handle_ajax_test_protection(): void {
		check_ajax_referer( 'dpc_dl_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Недостаточно прав для выполнения действия.', 'divi-pro-engagement' ) ], 403 );
		}

		$protected_path = self::normalize_relative_path(
			(string) dpc_get_option( 'dl_protected_path', self::DEFAULT_PATH )
		);
		$base_real      = self::resolve_safe_uploads_dir( $protected_path );

		if ( '' === $base_real ) {
			wp_send_json_success(
				[
					'verdict' => 'warning',
					'message' => __( 'Защищаемая папка не найдена на сервере. Проверьте путь к директории.', 'divi-pro-engagement' ),
				]
			);
		}

		$allowed_exts = self::parse_extensions( (string) dpc_get_option( 'dl_extensions', self::DEFAULT_EXTENSIONS ) );
		if ( empty( $allowed_exts ) ) {
			$allowed_exts = self::parse_extensions( self::DEFAULT_EXTENSIONS );
		}

		$relative_file = self::find_first_protected_file( $base_real, $allowed_exts );
		if ( null === $relative_file ) {
			wp_send_json_success(
				[
					'verdict' => 'warning',
					'message' => __( 'В защищаемой папке не найдено ни одного файла с указанными расширениями для проведения теста.', 'divi-pro-engagement' ),
				]
			);
		}

		$uploads      = wp_upload_dir();
		$uploads_url  = isset( $uploads['baseurl'] ) ? untrailingslashit( (string) $uploads['baseurl'] ) : content_url( '/uploads' );
		$encoded_path = implode( '/', array_map( 'rawurlencode', explode( '/', $relative_file ) ) );
		$file_url     = trailingslashit( $uploads_url ) . $protected_path . '/' . $encoded_path;
		$filename     = basename( $relative_file );
		$file_ext     = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );

		$response = wp_remote_head(
			$file_url,
			[
				'timeout'     => 8,
				'redirection' => 0,
				'cookies'     => [],
				'sslverify'   => false,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_success(
				[
					'verdict' => 'warning',
					'message' => sprintf(
						/* translators: 1: filename, 2: error message */
						__( 'Ошибка при тестовом запросе к файлу %1$s: %2$s', 'divi-pro-engagement' ),
						$filename,
						$response->get_error_message()
					),
				]
			);
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );

		if ( 302 === $code && str_contains( $location, 'dpc_auth_required=1' ) ) {
			wp_send_json_success(
				[
					'verdict' => 'ok',
					'code'    => $code,
					'message' => sprintf(
						/* translators: %s: filename */
						__( 'Защита работает: PHP-шлюз перехватил файл %s (HTTP 302 → модалка авторизации).', 'divi-pro-engagement' ),
						$filename
					),
				]
			);
		}

		if ( 403 === $code ) {
			wp_send_json_success(
				[
					'verdict' => 'ok',
					'code'    => $code,
					'message' => sprintf(
						/* translators: %s: filename */
						__( 'Защита работает: Apache вернул HTTP 403 Forbidden для файла %s.', 'divi-pro-engagement' ),
						$filename
					),
				]
			);
		}

		if ( 200 === $code ) {
			wp_send_json_success(
				[
					'verdict' => 'danger',
					'code'    => $code,
					'message' => sprintf(
						/* translators: 1: filename, 2: extension */
						__( 'ВНИМАНИЕ! Файл %1$s доступен напрямую (HTTP 200) в обход .htaccess! Уберите расширение %2$s (а также pdf, zip, rar, dwg, dxf, doc, docx, xls, xlsx, ppt, pptx) из поля «Статические файлы» Nginx в панели хостинга и повторите тест.', 'divi-pro-engagement' ),
						$filename,
						$file_ext
					),
				]
			);
		}

		wp_send_json_success(
			[
				'verdict' => 'warning',
				'code'    => $code,
				'message' => sprintf(
					/* translators: 1: filename, 2: HTTP status code */
					__( 'Неожиданный ответ сервера для файла %1$s: HTTP %2$d.', 'divi-pro-engagement' ),
					$filename,
					$code
				),
			]
		);
	}

	/**
	 * Recursively find the first protected file inside $base_dir (preferring .pdf if present).
	 *
	 * @param string             $base_dir     Realpath of protected directory.
	 * @param array<int, string> $allowed_exts Allowed lowercase extensions.
	 */
	private static function find_first_protected_file( string $base_dir, array $allowed_exts ): ?string {
		$prefer_pdf  = in_array( 'pdf', $allowed_exts, true );
		$fallback    = null;
		$scanned     = 0;
		$max_entries = 500;
		$norm_base   = trailingslashit( wp_normalize_path( $base_dir ) );

		try {
			$dir_iterator = new \RecursiveDirectoryIterator(
				$base_dir,
				\FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
			);
			$filter       = new \RecursiveCallbackFilterIterator(
				$dir_iterator,
				static function ( \SplFileInfo $current ): bool {
					return ! str_starts_with( $current->getFilename(), '.' );
				}
			);
			$iterator     = new \RecursiveIteratorIterator( $filter, \RecursiveIteratorIterator::LEAVES_ONLY );

			foreach ( $iterator as $file_info ) {
				if ( ++$scanned > $max_entries ) {
					break;
				}
				if ( ! $file_info instanceof \SplFileInfo || ! $file_info->isFile() || ! $file_info->isReadable() ) {
					continue;
				}

				$ext = strtolower( $file_info->getExtension() );
				if ( '' === $ext || ! in_array( $ext, $allowed_exts, true ) ) {
					continue;
				}

				$norm_path = wp_normalize_path( $file_info->getPathname() );
				if ( ! str_starts_with( $norm_path, $norm_base ) ) {
					continue;
				}

				$rel = ltrim( substr( $norm_path, strlen( $norm_base ) ), '/' );
				if ( '' === $rel ) {
					continue;
				}

				if ( 'pdf' === $ext ) {
					return $rel;
				}

				if ( null === $fallback ) {
					$fallback = $rel;
					if ( ! $prefer_pdf ) {
						return $fallback;
					}
				}
			}
		} catch ( \Throwable ) {
			return $fallback;
		}

		return $fallback;
	}

	/**
	 * Remove our marker block from .htaccess in the given relative uploads directory.
	 */
	private static function clear_htaccess_block( string $relative_path ): void {
		$target_dir = self::resolve_safe_uploads_dir( $relative_path );
		if ( '' === $target_dir ) {
			return;
		}

		$htaccess_file = trailingslashit( $target_dir ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) || ! is_writable( $htaccess_file ) ) {
			return;
		}

		$existing = file_get_contents( $htaccess_file );
		if ( false === $existing || ! str_contains( $existing, self::HTACCESS_BEGIN ) ) {
			return;
		}

		$cleaned = self::remove_marker_block( $existing );
		if ( '' === trim( $cleaned ) ) {
			wp_delete_file( $htaccess_file );
		} else {
			file_put_contents( $htaccess_file, $cleaned, LOCK_EX );
		}
	}

	/**
	 * Resolve and validate an existing directory inside wp_upload_dir()['basedir'].
	 */
	private static function resolve_safe_uploads_dir( string $relative_path ): string {
		$clean_rel = self::normalize_relative_path( $relative_path );
		if ( '' === $clean_rel ) {
			return '';
		}

		$uploads = wp_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
		if ( '' === $basedir || ! is_dir( $basedir ) ) {
			return '';
		}

		$target_dir = trailingslashit( $basedir ) . $clean_rel;
		if ( ! is_dir( $target_dir ) ) {
			return '';
		}

		$real_base   = realpath( $basedir );
		$real_target = realpath( $target_dir );
		if ( false === $real_base || false === $real_target ) {
			return '';
		}

		$norm_base   = trailingslashit( wp_normalize_path( $real_base ) );
		$norm_target = trailingslashit( wp_normalize_path( $real_target ) );

		if ( ! str_starts_with( $norm_target, $norm_base ) ) {
			return '';
		}

		return $real_target;
	}

	/**
	 * Strip marker block from .htaccess content.
	 */
	private static function remove_marker_block( string $content ): string {
		$pattern = '/^' . preg_quote( self::HTACCESS_BEGIN, '/' ) . '.*?^' . preg_quote( self::HTACCESS_END, '/' ) . '\r?\n?/ms';
		$cleaned = (string) preg_replace( $pattern, '', $content );
		$cleaned = (string) preg_replace( "/\n{3,}/", "\n\n", $cleaned );
		return ltrim( $cleaned, "\r\n" );
	}

	/**
	 * Insert or update marker block inside .htaccess content.
	 */
	private static function upsert_marker_block( string $content, string $block ): string {
		$cleaned = trim( self::remove_marker_block( $content ) );
		if ( '' === $cleaned ) {
			return $block . "\n";
		}
		return $cleaned . "\n\n" . $block . "\n";
	}

	/**
	 * Render Download Gatekeeper authorization modal in wp_footer for guests.
	 */
	public function render_modal_html(): void {
		if ( ! (bool) dpc_get_option( 'enable_download_gatekeeper', false ) ) {
			return;
		}

		AuthManager::get_instance()->render_auth_modal_html( 'gatekeeper' );
	}
}
