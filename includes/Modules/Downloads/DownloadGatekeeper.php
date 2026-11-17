<?php
declare(strict_types=1);

namespace DiviProEngagement\Modules\Downloads;

use DiviProEngagement\Core\Init;
use DiviProEngagement\Modules\Auth\AuthManager;
use DiviProEngagement\Modules\Comments\CommentsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DownloadGatekeeper {
	public const DEFAULT_PATH       = 'joomla-legacy/dmdocuments';
	public const DEFAULT_EXTENSIONS = 'pdf,rar,zip,dwg,dxf,doc,docx,xls,xlsx';

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
				'toast_title' => '✓ Авторизація успішна! Завантаження розпочинається...',
				'toast_btn'   => 'Завантажити файл',
			],
			'ru' => [
				'title'       => '🔒 Доступ только для авторизованных пользователей',
				'description' => 'Скачивание технической документации, каталогов PDF и чертежей AutoCAD доступно после быстрой авторизации:',
				'close'       => 'Закрыть',
				'login_btn'   => 'Войти на сайт',
				'toast_title' => '✓ Вы успешно вошли! Загрузка начинается...',
				'toast_btn'   => 'Скачать файл',
			],
			'en' => [
				'title'       => '🔒 Access for Authorized Users Only',
				'description' => 'Downloading technical documentation, PDF catalogs, and AutoCAD drawings is available after quick sign-in:',
				'close'       => 'Close',
				'login_btn'   => 'Sign In',
				'toast_title' => '✓ Signed in successfully! Starting download...',
				'toast_btn'   => 'Download file',
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
			'protectedPath'       => $protected_path,
			'extensions'          => $extensions,
			'interceptAllUploads' => (bool) dpc_get_option( 'dl_intercept_all_uploads', false ),
			'uploadsUrl'          => $uploads_url,
			'isLoggedIn'          => is_user_logged_in(),
			'i18n'                => self::get_all_dictionaries(),
		];
	}

	/**
	 * Synchronize .htaccess protection rules in the protected uploads directory.
	 *
	 * @param array<string, mixed>|null $override_settings Optional fresh settings array during sanitize_option.
	 * @param string|null               $previous_path     Optional previous relative path to clean up if path changed.
	 */
	public static function sync_htaccess_rules( ?array $override_settings = null, ?string $previous_path = null ): void {
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

		$protected_path = self::normalize_relative_path( $raw_path );

		if ( null !== $previous_path ) {
			$prev_clean = self::normalize_relative_path( $previous_path );
			if ( '' !== $prev_clean && $prev_clean !== $protected_path ) {
				self::clear_htaccess_block( $prev_clean );
			}
		}

		if ( '' === $protected_path ) {
			return;
		}

		$target_dir = self::resolve_safe_uploads_dir( $protected_path );
		if ( '' === $target_dir ) {
			return;
		}

		$htaccess_file = trailingslashit( $target_dir ) . '.htaccess';

		if ( ! $enabled || ! $htaccess_enabled ) {
			self::clear_htaccess_block( $protected_path );
			return;
		}

		$extensions = self::parse_extensions( $raw_exts );
		if ( empty( $extensions ) ) {
			$extensions = self::parse_extensions( self::DEFAULT_EXTENSIONS );
		}

		$ext_pattern = implode( '|', $extensions );
		$rules_lines = [
			self::HTACCESS_BEGIN,
			'Options -Indexes',
			'<IfModule mod_headers.c>',
			'  Header set X-Robots-Tag "noindex, nofollow, noarchive"',
			'</IfModule>',
			'<IfModule mod_rewrite.c>',
			'  RewriteEngine On',
			'  RewriteCond %{HTTP_COOKIE} !wordpress_logged_in_ [NC]',
			'  RewriteRule \.(' . $ext_pattern . ')$ - [F,L,NC]',
			'</IfModule>',
			self::HTACCESS_END,
		];
		$block       = implode( "\n", $rules_lines );

		$existing = '';
		if ( file_exists( $htaccess_file ) ) {
			if ( ! is_writable( $htaccess_file ) ) {
				return;
			}
			$read = file_get_contents( $htaccess_file );
			if ( false !== $read ) {
				$existing = $read;
			}
		} elseif ( ! is_writable( $target_dir ) ) {
			return;
		}

		$updated = self::upsert_marker_block( $existing, $block );
		file_put_contents( $htaccess_file, $updated, LOCK_EX );
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
		if ( is_admin() || Init::is_visual_builder_active() || is_user_logged_in() ) {
			return;
		}

		if ( ! (bool) dpc_get_option( 'enable_download_gatekeeper', false ) ) {
			return;
		}

		$lang  = CommentsEngine::detect_lang_code();
		$dicts = self::get_all_dictionaries();
		$texts = $dicts[ $lang ] ?? $dicts['en'];

		$auth_html = '';
		if ( (bool) dpc_get_option( 'enable_social_auth', false ) ) {
			$auth_html = AuthManager::get_instance()->render_auth_box( 'shortcode' );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '/';
		$login_url   = wp_login_url( home_url( $request_uri ) );
		?>
		<div id="dpc-gatekeeper-modal" class="dpc-gatekeeper-modal" role="dialog" aria-modal="true" aria-labelledby="dpc-gatekeeper-title" aria-hidden="true">
			<div class="dpc-gatekeeper-modal__overlay" data-dpc-gk-close="1"></div>
			<div class="dpc-gatekeeper-modal__dialog">
				<button type="button" class="dpc-gatekeeper-modal__close" data-dpc-gk-close="1" data-dpc-gk-i18n-aria="close" aria-label="<?php echo esc_attr( $texts['close'] ); ?>">
					<span aria-hidden="true">&times;</span>
				</button>
				<h3 id="dpc-gatekeeper-title" class="dpc-gatekeeper-modal__title" data-dpc-gk-i18n="title"><?php echo esc_html( $texts['title'] ); ?></h3>
				<p class="dpc-gatekeeper-modal__desc" data-dpc-gk-i18n="description"><?php echo esc_html( $texts['description'] ); ?></p>
				<div class="dpc-gatekeeper-modal__auth">
					<?php if ( '' !== $auth_html ) : ?>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Output escaped inside AuthManager::render_auth_box().
						echo $auth_html;
						?>
					<?php else : ?>
						<a href="<?php echo esc_url( $login_url ); ?>" class="dpc-btn dpc-gatekeeper-login-fallback" data-dpc-gk-i18n="login_btn"><?php echo esc_html( $texts['login_btn'] ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}
}
