<?php
declare(strict_types=1);

namespace DiviProEngagement\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Updater {
	private const GITHUB_REPO    = 'piratexnik/divi-pro-engagement';
	private const TRANSIENT_KEY  = 'dpc_github_release_info';
	private const CACHE_LIFETIME = 12 * HOUR_IN_SECONDS;

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_plugin_update' ] );
		add_filter( 'site_transient_update_plugins', [ $this, 'filter_site_transient_update_plugins' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info_popup' ], 20, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_source_folder' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ $this, 'purge_cache_after_update' ], 10, 2 );
		add_filter( 'plugin_action_links_' . DPC_BASENAME, [ $this, 'add_action_links' ] );
		add_action( 'admin_init', [ $this, 'check_for_updates' ] );
		add_action( 'admin_notices', [ $this, 'render_manual_check_notice' ] );
	}

	/**
	 * Hook into update transient before saving to inject new versions from GitHub CDN.
	 *
	 * @param mixed $transient Transient data object.
	 * @return mixed
	 */
	public function check_for_plugin_update( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( empty( $release['version'] ) || empty( $release['download_url'] ) ) {
			return $transient;
		}

		$remote_version = ltrim( (string) $release['version'], 'vV' );
		$local_version  = $this->get_installed_version();
		$item           = $this->build_update_item( $remote_version, (string) $release['download_url'] );

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = [];
		}

		if ( version_compare( $remote_version, $local_version, '>' ) ) {
			$transient->response[ DPC_BASENAME ] = $item;
			unset( $transient->no_update[ DPC_BASENAME ] );
		} else {
			unset( $transient->response[ DPC_BASENAME ] );
			$transient->no_update[ DPC_BASENAME ] = $item;
		}

		return $transient;
	}

	/**
	 * Build standard WordPress plugin update item object.
	 *
	 * @param string $remote_version Remote release version.
	 * @param string $download_url   Package zip URL.
	 * @return object
	 */
	private function build_update_item( string $remote_version, string $download_url ): object {
		$plugin_slug = dirname( DPC_BASENAME );

		return (object) [
			'id'            => $plugin_slug,
			'slug'          => $plugin_slug,
			'plugin'        => DPC_BASENAME,
			'new_version'   => $remote_version,
			'url'           => 'https://github.com/' . self::GITHUB_REPO,
			'package'       => $download_url,
			'icons'         => [],
			'banners'       => [],
			'banners_rtl'   => [],
			'tested'        => '',
			'requires_php'  => '8.1',
			'compatibility' => new \stdClass(),
		];
	}

	/**
	 * Filter update_plugins on read to ensure state consistency without any HTTP requests.
	 *
	 * @param mixed $transient Transient data object.
	 * @return mixed
	 */
	public function filter_site_transient_update_plugins( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		if ( isset( $transient->response[ DPC_BASENAME ] ) ) {
			$local_version  = $this->get_installed_version();
			$remote_version = (string) ( $transient->response[ DPC_BASENAME ]->new_version ?? '' );

			if ( '' !== $remote_version && version_compare( $remote_version, $local_version, '<=' ) ) {
				if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
					$transient->no_update = [];
				}
				$transient->no_update[ DPC_BASENAME ] = $transient->response[ DPC_BASENAME ];
				unset( $transient->response[ DPC_BASENAME ] );
			}
		}

		return $transient;
	}

	/**
	 * Read installed plugin version directly from disk bypassing internal memory cache.
	 *
	 * @return string
	 */
	public function get_installed_version(): string {
		$main_file = DPC_PATH . 'divi-pro-engagement.php';

		if ( file_exists( $main_file ) ) {
			$header_content = (string) file_get_contents( $main_file, false, null, 0, 4096 );
			if ( preg_match( '/^[ \t\/*#@]*Version:\s*([0-9\.]+)/mi', $header_content, $matches ) ) {
				return trim( $matches[1] );
			}
		}

		return DPC_VERSION;
	}

	/**
	 * Displays plugin information popup when clicking "View version details".
	 *
	 * @param mixed  $result The result object or false.
	 * @param string $action The type of information being requested.
	 * @param object $args   Plugin API arguments.
	 * @return mixed
	 */
	public function plugin_info_popup( mixed $result, string $action, object $args ): mixed {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		$plugin_slug = dirname( DPC_BASENAME );
		if ( ! isset( $args->slug ) || $args->slug !== $plugin_slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( empty( $release['version'] ) ) {
			return $result;
		}

		return (object) [
			'name'          => 'Divi Pro Engagement',
			'slug'          => $plugin_slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/piratexnik">Piratexnik</a>',
			'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
			'requires'      => '6.5',
			'requires_php'  => '8.1',
			'download_link' => $release['download_url'],
			'sections'      => [
				'description' => esc_html__( 'Advanced engagement modules for Divi 5: AJAX Comments, Reactions, Post Ratings with Schema.org, and Social Authentication.', 'divi-pro-engagement' ),
				'changelog'   => ! empty( $release['body'] ) ? nl2br( esc_html( $release['body'] ) ) : esc_html__( 'No changelog provided.', 'divi-pro-engagement' ),
			],
		];
	}

	/**
	 * Rename GitHub extracted folder (e.g. piratexnik-divi-pro-engagement-xyz) to 'divi-pro-engagement'.
	 *
	 * @param string      $source        File path of extracted archive source.
	 * @param string      $remote_source Remote source path.
	 * @param mixed       $upgrader      Upgrader instance.
	 * @param array|null  $hook_extra    Extra hook data.
	 * @return string|\WP_Error
	 */
	public function fix_source_folder( string $source, string $remote_source, mixed $upgrader, ?array $hook_extra = null ): mixed {
		global $wp_filesystem;

		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== DPC_BASENAME ) {
			return $source;
		}

		$correct_slug   = dirname( DPC_BASENAME );
		$correct_source = trailingslashit( $remote_source ) . $correct_slug . '/';

		if ( trailingslashit( $source ) === $correct_source ) {
			return $source;
		}

		if ( is_object( $wp_filesystem ) && $wp_filesystem->move( $source, $correct_source ) ) {
			return $correct_source;
		}

		return $source;
	}

	/**
	 * Purge release cache after plugin update finishes.
	 *
	 * @param mixed $upgrader Upgrader instance.
	 * @param array $options  Update data.
	 */
	public function purge_cache_after_update( mixed $upgrader, array $options ): void {
		if (
			isset( $options['action'], $options['type'] ) &&
			'update' === $options['action'] &&
			'plugin' === $options['type']
		) {
			delete_transient( self::TRANSIENT_KEY );
			delete_site_transient( 'update_plugins' );
			wp_clean_plugins_cache( true );
		}
	}

	/**
	 * Add "Check for updates" link to plugin action links.
	 *
	 * @param array<string, string> $links Current links.
	 * @return array<string, string>
	 */
	public function add_action_links( array $links ): array {
		$check_url = wp_nonce_url(
			admin_url( 'plugins.php?dpc_check_update=1' ),
			'dpc_check_update_nonce'
		);

		$update_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $check_url ),
			esc_html__( 'Проверить обновления', 'divi-pro-engagement' )
		);

		$links['dpc_check_update'] = $update_link;
		return $links;
	}

	/**
	 * Clear cache and force update check on manual trigger.
	 */
	public function check_for_updates(): void {
		if ( ! isset( $_GET['dpc_check_update'] ) ) {
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'dpc_check_update_nonce' ) ) {
			return;
		}

		// 1. Force purge our transient and WP update transients
		delete_transient( self::TRANSIENT_KEY );
		delete_site_transient( 'update_plugins' );
		wp_clean_plugins_cache( true );

		// 2. Query GitHub CDN directly with anti-cache
		$release = $this->get_latest_release( true );

		// 3. Inject result into update_plugins immediately so WP sees it without waiting
		if ( ! empty( $release['version'] ) && ! empty( $release['download_url'] ) ) {
			$remote_version = ltrim( (string) $release['version'], 'vV' );
			$local_version  = $this->get_installed_version();
			$item           = $this->build_update_item( $remote_version, (string) $release['download_url'] );

			$update_plugins = get_site_transient( 'update_plugins' );
			if ( ! is_object( $update_plugins ) ) {
				$update_plugins = new \stdClass();
			}
			if ( ! isset( $update_plugins->response ) || ! is_array( $update_plugins->response ) ) {
				$update_plugins->response = [];
			}
			if ( ! isset( $update_plugins->no_update ) || ! is_array( $update_plugins->no_update ) ) {
				$update_plugins->no_update = [];
			}
			if ( ! isset( $update_plugins->checked ) || ! is_array( $update_plugins->checked ) ) {
				$update_plugins->checked = [];
			}

			if ( version_compare( $remote_version, $local_version, '>' ) ) {
				$update_plugins->response[ DPC_BASENAME ] = $item;
				unset( $update_plugins->no_update[ DPC_BASENAME ] );
			} else {
				unset( $update_plugins->response[ DPC_BASENAME ] );
				$update_plugins->no_update[ DPC_BASENAME ] = $item;
			}

			$update_plugins->last_checked              = time();
			$update_plugins->checked[ DPC_BASENAME ] = $local_version;
			set_site_transient( 'update_plugins', $update_plugins );
		} else {
			wp_update_plugins();
		}

		wp_safe_redirect( admin_url( 'plugins.php?dpc_checked=1' ) );
		exit;
	}

	/**
	 * Display admin notice after manual update check.
	 */
	public function render_manual_check_notice(): void {
		if ( ! isset( $_GET['dpc_checked'] ) ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' .
			esc_html__( 'Проверка обновлений выполнена', 'divi-pro-engagement' ) .
			'</p></div>';
	}

	/**
	 * Fetch latest release from raw.githubusercontent.com CDN (unlimited, fast) or transient cache.
	 *
	 * @param bool $force_refresh Skip transient cache.
	 * @return array{version: string, download_url: string, body: string}
	 */
	private function get_latest_release( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				if ( ! empty( $cached['error'] ) ) {
					return [
						'version'      => '',
						'download_url' => '',
						'body'         => '',
					];
				}
				if ( ! empty( $cached['version'] ) && ! empty( $cached['download_url'] ) ) {
					return $cached;
				}
			}
		}

		$raw_url  = 'https://raw.githubusercontent.com/' . self::GITHUB_REPO . '/main/divi-pro-engagement.php?t=' . time();
		$response = wp_remote_get(
			$raw_url,
			[
				'timeout' => 5,
				'headers' => [
					'Cache-Control' => 'no-cache, no-store, must-revalidate',
					'User-Agent'    => 'WordPress/' . get_bloginfo( 'version' ) . '; DiviProEngagement/' . DPC_VERSION,
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache negative result for 10 minutes to protect shared hosting from repeated calls
			set_transient( self::TRANSIENT_KEY, [ 'error' => true ], 10 * MINUTE_IN_SECONDS );
			return [
				'version'      => '',
				'download_url' => '',
				'body'         => '',
			];
		}

		$body = wp_remote_retrieve_body( $response );
		if ( preg_match( '/\*\s*Version:\s*([0-9.]+)/i', $body, $matches ) ) {
			$remote_version = trim( $matches[1] );
			$release_data   = [
				'version'      => $remote_version,
				'download_url' => 'https://github.com/' . self::GITHUB_REPO . "/archive/refs/tags/v{$remote_version}.zip",
				'body'         => '',
			];

			set_transient( self::TRANSIENT_KEY, $release_data, self::CACHE_LIFETIME );
			return $release_data;
		}

		// Negative cache on regex parse failure
		set_transient( self::TRANSIENT_KEY, [ 'error' => true ], 10 * MINUTE_IN_SECONDS );
		return [
			'version'      => '',
			'download_url' => '',
			'body'         => '',
		];
	}
}
