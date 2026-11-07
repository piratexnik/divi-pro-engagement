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
	 * Hook into update transient before saving to inject new versions from GitHub.
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
		$plugin_slug    = dirname( DPC_BASENAME );

		$item = (object) [
			'id'            => $plugin_slug,
			'slug'          => $plugin_slug,
			'plugin'        => DPC_BASENAME,
			'new_version'   => $remote_version,
			'url'           => 'https://github.com/' . self::GITHUB_REPO,
			'package'       => $release['download_url'],
			'icons'         => [],
			'banners'       => [],
			'banners_rtl'   => [],
			'tested'        => '',
			'requires_php'  => '8.1',
			'compatibility' => new \stdClass(),
		];

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
	 * Filter update_plugins on read to ensure state consistency.
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
	private function get_installed_version(): string {
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

		// 2. Query GitHub immediately with anti-cache
		$release = $this->get_latest_release( true );

		// 3. Inject result into update_plugins immediately so WP sees it without waiting
		if ( ! empty( $release['version'] ) && ! empty( $release['download_url'] ) ) {
			$remote_version = ltrim( (string) $release['version'], 'vV' );
			$local_version  = $this->get_installed_version();
			$plugin_slug    = dirname( DPC_BASENAME );

			$item = (object) [
				'id'            => $plugin_slug,
				'slug'          => $plugin_slug,
				'plugin'        => DPC_BASENAME,
				'new_version'   => $remote_version,
				'url'           => 'https://github.com/' . self::GITHUB_REPO,
				'package'       => $release['download_url'],
				'icons'         => [],
				'banners'       => [],
				'banners_rtl'   => [],
				'tested'        => '',
				'requires_php'  => '8.1',
				'compatibility' => new \stdClass(),
			];

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

			if ( version_compare( $remote_version, $local_version, '>' ) ) {
				$update_plugins->response[ DPC_BASENAME ] = $item;
				unset( $update_plugins->no_update[ DPC_BASENAME ] );
			} else {
				unset( $update_plugins->response[ DPC_BASENAME ] );
				$update_plugins->no_update[ DPC_BASENAME ] = $item;
			}

			$update_plugins->last_checked = time();
			$update_plugins->checked      = [ DPC_BASENAME => $local_version ];
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
	 * Fetch latest release from GitHub API or cache.
	 *
	 * @param bool $force_refresh Skip transient cache.
	 * @return array{version: string, download_url: string, body: string}
	 */
	private function get_latest_release( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
				return $cached;
			}
		}

		$headers = [
			'Accept'        => 'application/vnd.github.v3+json',
			'Cache-Control' => 'no-cache, no-store, must-revalidate',
			'User-Agent'    => 'WordPress/' . get_bloginfo( 'version' ) . '; DiviProEngagement/' . DPC_VERSION,
		];

		$release_data = [
			'version'      => '',
			'download_url' => '',
			'body'         => '',
		];

		// 1. Check Releases
		$url      = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest?t=' . time();
		$response = wp_remote_get(
			$url,
			[
				'timeout' => 10,
				'headers' => $headers,
			]
		);

		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $data ) && ! empty( $data['tag_name'] ) ) {
				$tag            = (string) $data['tag_name'];
				$remote_version = ltrim( $tag, 'vV' );
				$download_url   = "https://github.com/" . self::GITHUB_REPO . "/archive/refs/tags/{$tag}.zip";

				if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
					foreach ( $data['assets'] as $asset ) {
						if ( isset( $asset['name'], $asset['browser_download_url'] ) && str_ends_with( (string) $asset['name'], '.zip' ) ) {
							$download_url = (string) $asset['browser_download_url'];
							break;
						}
					}
				}

				$release_data = [
					'version'      => $remote_version,
					'download_url' => $download_url,
					'body'         => (string) ( $data['body'] ?? '' ),
				];
			}
		}

		// 2. Fallback to tags if releases are not published yet
		if ( empty( $release_data['version'] ) ) {
			$tags_url      = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/tags?t=' . time();
			$tags_response = wp_remote_get(
				$tags_url,
				[
					'timeout' => 10,
					'headers' => $headers,
				]
			);

			if ( ! is_wp_error( $tags_response ) && 200 === wp_remote_retrieve_response_code( $tags_response ) ) {
				$tags_data = json_decode( wp_remote_retrieve_body( $tags_response ), true );
				if ( is_array( $tags_data ) && ! empty( $tags_data[0]['name'] ) ) {
					$tag            = (string) $tags_data[0]['name'];
					$remote_version = ltrim( $tag, 'vV' );
					$download_url   = "https://github.com/" . self::GITHUB_REPO . "/archive/refs/tags/{$tag}.zip";

					$release_data = [
						'version'      => $remote_version,
						'download_url' => $download_url,
						'body'         => '',
					];
				}
			}
		}

		if ( ! empty( $release_data['version'] ) ) {
			set_transient( self::TRANSIENT_KEY, $release_data, self::CACHE_LIFETIME );
		}

		return $release_data;
	}
}
