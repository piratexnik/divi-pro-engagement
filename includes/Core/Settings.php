<?php
declare(strict_types=1);

namespace DiviProEngagement\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
	private const OPTION_NAME = 'dpc_settings';
	private const PAGE_SLUG   = 'dpc-settings';

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . DPC_BASENAME, [ $this, 'add_settings_action_link' ] );
	}

	public function register_menu_page(): void {
		add_menu_page(
			esc_html__( 'Divi Engagement', 'divi-pro-engagement' ),
			esc_html__( 'Divi Engagement', 'divi-pro-engagement' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_settings_page' ],
			'dashicons-star-filled',
			58
		);
	}

	public function register_settings(): void {
		register_setting(
			'dpc_settings_group',
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => [],
			]
		);
	}

	/**
	 * Add "Settings" link to the plugin action links on plugins.php.
	 *
	 * @param array<string, string> $links Current plugin action links.
	 * @return array<string, string>
	 */
	public function add_settings_action_link( array $links ): array {
		$settings_url  = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $settings_url ),
			esc_html__( 'Настройки', 'divi-pro-engagement' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Sanitization callback preserving settings from other tabs.
	 *
	 * @param mixed $input Submitted form array.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( mixed $input ): array {
		$existing = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		$current_tab = isset( $_POST['dpc_current_tab'] )
			? sanitize_key( wp_unslash( (string) $_POST['dpc_current_tab'] ) )
			: 'general';

		$input_data = is_array( $input ) ? $input : [];
		$tab_fields = $this->get_tab_fields_schema();

		if ( isset( $tab_fields[ $current_tab ] ) ) {
			foreach ( $tab_fields[ $current_tab ] as $field_key => $field_type ) {
				if ( 'checkbox' === $field_type ) {
					$existing[ $field_key ] = ! empty( $input_data[ $field_key ] ) ? 1 : 0;
				} elseif ( 'slug' === $field_type ) {
					$raw_slug = isset( $input_data[ $field_key ] )
						? sanitize_text_field( wp_unslash( (string) $input_data[ $field_key ] ) )
						: '';
					$clean_slug = sanitize_title( trim( $raw_slug, "/ \t\n\r\0\x0B" ) );
					if ( in_array( $clean_slug, [ 'wp-admin', 'wp-login', 'wp-login.php', 'admin', 'login' ], true ) && 'wp-login.php' === $clean_slug ) {
						$clean_slug = '';
					}
					$existing[ $field_key ] = $clean_slug;
				} elseif ( 'role' === $field_type ) {
					$role = isset( $input_data[ $field_key ] )
						? sanitize_key( wp_unslash( (string) $input_data[ $field_key ] ) )
						: 'subscriber';
					$allowed_roles = $this->get_allowed_roles();
					if ( 'administrator' === $role || ! isset( $allowed_roles[ $role ] ) ) {
						$role = 'subscriber';
					}
					$existing[ $field_key ] = $role;
				} else {
					$existing[ $field_key ] = isset( $input_data[ $field_key ] )
						? sanitize_text_field( wp_unslash( (string) $input_data[ $field_key ] ) )
						: '';
				}
			}
		}

		return $existing;
	}

	/**
	 * Field types schema per tab.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_tab_fields_schema(): array {
		return [
			'general'  => [
				'enable_social_auth'  => 'checkbox',
				'enable_post_rating'  => 'checkbox',
				'enable_comments'     => 'checkbox',
				'enable_custom_login' => 'checkbox',
				'custom_login_slug'   => 'slug',
				'hide_admin_bar'      => 'checkbox',
			],
			'auth'     => [
				'auth_show_in_comments'       => 'checkbox',
				'auth_select_account'         => 'checkbox',
				'auth_auto_link_email'        => 'checkbox',
				'auth_disable_admin'          => 'checkbox',
				'auth_default_role'           => 'role',
				'auth_shortcode_style'        => 'text',
				'auth_shortcode_skin'         => 'text',
				'auth_comments_style'         => 'text',
				'auth_comments_skin'          => 'text',
				'auth_google_enable'          => 'checkbox',
				'auth_google_client_id'       => 'text',
				'auth_google_client_secret'   => 'text',
				'auth_telegram_enable'        => 'checkbox',
				'auth_telegram_bot_username'  => 'text',
				'auth_telegram_bot_token'     => 'text',
				'auth_facebook_enable'        => 'checkbox',
				'auth_facebook_app_id'        => 'text',
				'auth_facebook_app_secret'    => 'text',
				'auth_discord_enable'         => 'checkbox',
				'auth_discord_client_id'      => 'text',
				'auth_discord_client_secret'  => 'text',
				'auth_twitter_enable'         => 'checkbox',
				'auth_twitter_client_id'      => 'text',
				'auth_twitter_client_secret'  => 'text',
			],
			'rating'   => [
				'rating_type'          => 'text',
				'rating_access'        => 'text',
				'rating_position'      => 'text',
				'rating_schema_enable' => 'checkbox',
			],
			'comments' => [
				'comments_vote_access'      => 'text',
				'antispam_honeypot'         => 'checkbox',
				'antispam_turnstile_enable' => 'checkbox',
				'turnstile_site_key'        => 'text',
				'turnstile_secret_key'      => 'text',
			],
		];
	}

	/**
	 * Get all editable roles except administrator.
	 *
	 * @return array<string, string>
	 */
	private function get_allowed_roles(): array {
		$wp_roles = wp_roles();
		$names    = $wp_roles instanceof \WP_Roles ? $wp_roles->get_names() : [];
		unset( $names['administrator'] );

		if ( empty( $names ) ) {
			$names['subscriber'] = 'Subscriber';
		}

		return $names;
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'general';
		$valid_tabs  = [ 'general', 'auth', 'rating', 'comments' ];
		if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
			$current_tab = 'general';
		}

		$tabs = [
			'general'  => esc_html__( 'Основные модули', 'divi-pro-engagement' ),
			'auth'     => esc_html__( 'Соц. Авторизация', 'divi-pro-engagement' ),
			'rating'   => esc_html__( 'Рейтинг статей', 'divi-pro-engagement' ),
			'comments' => esc_html__( 'Комментарии и Антиспам', 'divi-pro-engagement' ),
		];

		$this->render_admin_styles();
		?>
		<div class="wrap dpc-wrap">
			<h1><?php echo esc_html__( 'Divi Pro Engagement — Настройки', 'divi-pro-engagement' ); ?></h1>

			<?php settings_errors(); ?>

			<nav class="nav-tab-wrapper wp-clearfix dpc-nav-tabs">
				<?php foreach ( $tabs as $tab_id => $tab_title ) : ?>
					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab_id ) ); ?>"
						class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>"
					>
						<?php echo esc_html( $tab_title ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form action="options.php" method="post" class="dpc-settings-form">
				<?php
				settings_fields( 'dpc_settings_group' );
				echo '<input type="hidden" name="dpc_current_tab" value="' . esc_attr( $current_tab ) . '">';

				switch ( $current_tab ) {
					case 'auth':
						$this->render_auth_tab();
						break;
					case 'rating':
						$this->render_rating_tab();
						break;
					case 'comments':
						$this->render_comments_tab();
						break;
					case 'general':
					default:
						$this->render_general_tab();
						break;
				}

				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	private function render_general_tab(): void {
		$enable_social_auth  = (int) dpc_get_option( 'enable_social_auth', 0 );
		$enable_post_rating  = (int) dpc_get_option( 'enable_post_rating', 0 );
		$enable_comments     = (int) dpc_get_option( 'enable_comments', 0 );
		$enable_custom_login = (int) dpc_get_option( 'enable_custom_login', 0 );
		$custom_login_slug   = (string) dpc_get_option( 'custom_login_slug', '' );
		$hide_admin_bar      = (int) dpc_get_option( 'hide_admin_bar', 0 );
		?>
		<div class="dpc-card">
			<h2><?php echo esc_html__( 'Управление модулями', 'divi-pro-engagement' ); ?></h2>
			<p class="dpc-card-subtitle"><?php echo esc_html__( 'Активируйте необходимые модули для интеграции с Divi 5.', 'divi-pro-engagement' ); ?></p>

			<div class="dpc-field-row">
				<div class="dpc-field-label">
					<strong><?php echo esc_html__( 'Соц. авторизация', 'divi-pro-engagement' ); ?></strong>
					<span><?php echo esc_html__( 'Вход и регистрация в один клик через Google, Telegram, Facebook, Discord.', 'divi-pro-engagement' ); ?></span>
				</div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'enable_social_auth', $enable_social_auth ); ?>
				</div>
			</div>

			<div class="dpc-field-row">
				<div class="dpc-field-label">
					<strong><?php echo esc_html__( 'Рейтинг статей', 'divi-pro-engagement' ); ?></strong>
					<span><?php echo esc_html__( 'Оценка публикаций (звезды/лайки) с микроразметкой Schema.org для сниппетов Google.', 'divi-pro-engagement' ); ?></span>
				</div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'enable_post_rating', $enable_post_rating ); ?>
				</div>
			</div>

			<div class="dpc-field-row">
				<div class="dpc-field-label">
					<strong><?php echo esc_html__( 'Улучшение комментариев Divi 5', 'divi-pro-engagement' ); ?></strong>
					<span><?php echo esc_html__( 'AJAX отправка, голосование за комментарии (лайк/дизлайк) и двойная защита от спама.', 'divi-pro-engagement' ); ?></span>
				</div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'enable_comments', $enable_comments ); ?>
				</div>
			</div>
		</div>

		<div class="dpc-card">
			<h2><?php echo esc_html__( 'Безопасность и Админ-панель', 'divi-pro-engagement' ); ?></h2>
			<p class="dpc-card-subtitle"><?php echo esc_html__( 'Защита страницы входа от брутфорс-ботов и ограничение админ-панели для подписчиков.', 'divi-pro-engagement' ); ?></p>

			<div class="dpc-field-row">
				<div class="dpc-field-label">
					<strong><?php echo esc_html__( 'Включить изменение адреса страницы входа wp-login.php', 'divi-pro-engagement' ); ?></strong>
					<span><?php echo esc_html__( 'Прямой доступ к /wp-login.php и /wp-admin/ для неавторизованных посетителей будет возвращать ошибку 404.', 'divi-pro-engagement' ); ?></span>
				</div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'enable_custom_login', $enable_custom_login ); ?>
				</div>
			</div>

			<div class="dpc-input-row" style="margin-bottom: 12px;">
				<label for="custom_login_slug"><?php echo esc_html__( 'Свой адрес входа (например: alogin или secret-enter)', 'divi-pro-engagement' ); ?></label>
				<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
					<code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code>
					<input
						type="text"
						id="custom_login_slug"
						name="dpc_settings[custom_login_slug]"
						placeholder="alogin"
						value="<?php echo esc_attr( $custom_login_slug ); ?>"
						class="regular-text"
						style="max-width: 240px;"
					/>
				</div>
			</div>

			<div class="dpc-field-row">
				<div class="dpc-field-label">
					<strong><?php echo esc_html__( 'Скрыть верхнюю черную панель WordPress (Admin Bar) для подписчиков', 'divi-pro-engagement' ); ?></strong>
					<span><?php echo esc_html__( 'Отключает верхний тулбар на фронтенде и закрывает доступ в /wp-admin/ для пользователей без права редактирования записей.', 'divi-pro-engagement' ); ?></span>
				</div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'hide_admin_bar', $hide_admin_bar ); ?>
				</div>
			</div>
		</div>

		<div class="dpc-card dpc-info-card">
			<h3><?php echo esc_html__( 'Доступные шорткоды', 'divi-pro-engagement' ); ?></h3>
			<p><?php echo esc_html__( 'Вы можете вставлять шорткоды в текстовые или кодовые модули макета Divi 5:', 'divi-pro-engagement' ); ?></p>
			<ul class="dpc-shortcode-list">
				<li>
					<code>[dpc_social_login]</code> — <?php echo esc_html__( 'Кнопки входа через включенные соцсети.', 'divi-pro-engagement' ); ?>
				</li>
				<li>
					<code>[dpc_post_rating]</code> — <?php echo esc_html__( 'Виджет рейтинга статьи для ручного размещения в любом месте шаблона статьи.', 'divi-pro-engagement' ); ?>
				</li>
			</ul>
		</div>
		<?php
	}

	private function render_auth_tab(): void {
		$base_callback         = home_url( '/wp-json/dpc/v1/auth/' );
		$auth_show_in_comments = (int) dpc_get_option( 'auth_show_in_comments', 1 );
		$auth_select_account   = (int) dpc_get_option( 'auth_select_account', 1 );
		$auth_auto_link_email  = (int) dpc_get_option( 'auth_auto_link_email', 1 );
		$auth_disable_admin    = (int) dpc_get_option( 'auth_disable_admin', 1 );
		$auth_default_role     = (string) dpc_get_option( 'auth_default_role', 'subscriber' );
		$auth_shortcode_style  = (string) dpc_get_option( 'auth_shortcode_style', 'inline' );
		$auth_shortcode_skin   = (string) dpc_get_option( 'auth_shortcode_skin', 'brand' );
		$auth_comments_style   = (string) dpc_get_option( 'auth_comments_style', 'inline' );
		$auth_comments_skin    = (string) dpc_get_option( 'auth_comments_skin', 'brand' );
		$allowed_roles         = $this->get_allowed_roles();
		?>
		<div class="dpc-card dpc-info-card">
			<h3><?php echo esc_html__( 'Callback / Redirect URL для OAuth провайдеров', 'divi-pro-engagement' ); ?></h3>
			<p><?php echo esc_html__( 'Укажите эти URL в панелях разработчиков Google Cloud, Meta Developer, Discord и X Developer Portal:', 'divi-pro-engagement' ); ?></p>
			<div class="dpc-code-box">
				<div><strong>Google OAuth Redirect URI:</strong> <code><?php echo esc_url( $base_callback . 'google' ); ?></code></div>
				<div><strong>Discord Redirect URI:</strong> <code><?php echo esc_url( $base_callback . 'discord' ); ?></code></div>
				<div><strong>Facebook OAuth Redirect URI:</strong> <code><?php echo esc_url( $base_callback . 'facebook' ); ?></code></div>
				<div><strong>X (Twitter) OAuth 2.0 Redirect URI:</strong> <code><?php echo esc_url( $base_callback . 'twitter' ); ?></code></div>
			</div>
		</div>

		<!-- Design & Multilingual UI -->
		<div class="dpc-card">
			<h2><?php echo esc_html__( 'Дизайн и отображение (Мультиязычный UI)', 'divi-pro-engagement' ); ?></h2>
			<p class="dpc-card-subtitle"><?php echo esc_html__( 'Кнопки не содержат языковых фраз и идеально подходят для мультиязычных сайтов. Настройте вид отдельно для шорткода и формы комментариев.', 'divi-pro-engagement' ); ?></p>

			<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
				<div style="background:#f8fafc; padding:16px; border:1px solid #e2e8f0; border-radius:8px;">
					<h3 style="margin-top:0; font-size:15px;"><?php echo esc_html__( '1. Шорткод [dpc_social_login]', 'divi-pro-engagement' ); ?></h3>
					<div class="dpc-input-row">
						<label for="auth_shortcode_style"><?php echo esc_html__( 'Макет кнопок (Style)', 'divi-pro-engagement' ); ?></label>
						<select id="auth_shortcode_style" name="dpc_settings[auth_shortcode_style]" class="regular-text">
							<option value="icon" <?php selected( 'icon', $auth_shortcode_style ); ?>><?php echo esc_html__( 'Только иконки в ряд (Icon Only)', 'divi-pro-engagement' ); ?></option>
							<option value="inline" <?php selected( 'inline', $auth_shortcode_style ); ?>><?php echo esc_html__( 'Кнопки с текстом в ряд (Inline)', 'divi-pro-engagement' ); ?></option>
							<option value="column" <?php selected( 'column', $auth_shortcode_style ); ?>><?php echo esc_html__( 'Кнопки столбиком (Column)', 'divi-pro-engagement' ); ?></option>
							<option value="fullwidth" <?php selected( 'fullwidth', $auth_shortcode_style ); ?>><?php echo esc_html__( 'Столбиком на всю ширину (Fullwidth)', 'divi-pro-engagement' ); ?></option>
						</select>
					</div>
					<div class="dpc-input-row">
						<label for="auth_shortcode_skin"><?php echo esc_html__( 'Цветовая тема (Skin)', 'divi-pro-engagement' ); ?></label>
						<select id="auth_shortcode_skin" name="dpc_settings[auth_shortcode_skin]" class="regular-text">
							<option value="brand" <?php selected( 'brand', $auth_shortcode_skin ); ?>><?php echo esc_html__( 'Фирменные цвета (Brand)', 'divi-pro-engagement' ); ?></option>
							<option value="dark" <?php selected( 'dark', $auth_shortcode_skin ); ?>><?php echo esc_html__( 'Темная тема (Dark)', 'divi-pro-engagement' ); ?></option>
							<option value="light" <?php selected( 'light', $auth_shortcode_skin ); ?>><?php echo esc_html__( 'Светлая минималистичная (Light)', 'divi-pro-engagement' ); ?></option>
						</select>
					</div>
				</div>

				<div style="background:#f8fafc; padding:16px; border:1px solid #e2e8f0; border-radius:8px;">
					<h3 style="margin-top:0; font-size:15px;"><?php echo esc_html__( '2. Форма комментариев Divi', 'divi-pro-engagement' ); ?></h3>
					<div class="dpc-input-row">
						<label for="auth_comments_style"><?php echo esc_html__( 'Макет кнопок (Style)', 'divi-pro-engagement' ); ?></label>
						<select id="auth_comments_style" name="dpc_settings[auth_comments_style]" class="regular-text">
							<option value="icon" <?php selected( 'icon', $auth_comments_style ); ?>><?php echo esc_html__( 'Только иконки в ряд (Icon Only)', 'divi-pro-engagement' ); ?></option>
							<option value="inline" <?php selected( 'inline', $auth_comments_style ); ?>><?php echo esc_html__( 'Кнопки с текстом в ряд (Inline)', 'divi-pro-engagement' ); ?></option>
							<option value="column" <?php selected( 'column', $auth_comments_style ); ?>><?php echo esc_html__( 'Кнопки столбиком (Column)', 'divi-pro-engagement' ); ?></option>
							<option value="fullwidth" <?php selected( 'fullwidth', $auth_comments_style ); ?>><?php echo esc_html__( 'Столбиком на всю ширину (Fullwidth)', 'divi-pro-engagement' ); ?></option>
						</select>
					</div>
					<div class="dpc-input-row">
						<label for="auth_comments_skin"><?php echo esc_html__( 'Цветовая тема (Skin)', 'divi-pro-engagement' ); ?></label>
						<select id="auth_comments_skin" name="dpc_settings[auth_comments_skin]" class="regular-text">
							<option value="brand" <?php selected( 'brand', $auth_comments_skin ); ?>><?php echo esc_html__( 'Фирменные цвета (Brand)', 'divi-pro-engagement' ); ?></option>
							<option value="dark" <?php selected( 'dark', $auth_comments_skin ); ?>><?php echo esc_html__( 'Темная тема (Dark)', 'divi-pro-engagement' ); ?></option>
							<option value="light" <?php selected( 'light', $auth_comments_skin ); ?>><?php echo esc_html__( 'Светлая минималистичная (Light)', 'divi-pro-engagement' ); ?></option>
						</select>
					</div>
				</div>
			</div>
		</div>

		<!-- PRO Auth Settings -->
		<div class="dpc-card">
			<h2><?php echo esc_html__( 'PRO-настройки входа', 'divi-pro-engagement' ); ?></h2>

			<div class="dpc-field-row">
				<div class="dpc-field-label">
					<strong><?php echo esc_html__( 'Автоматически выводить кнопки соцсетей в форме комментариев Divi', 'divi-pro-engagement' ); ?></strong>
					<span><?php echo esc_html__( 'Выводит блок соцсетей над полями формы комментариев на страницах записей. Шорткод [dpc_social_login] остается доступным в любом случае.', 'divi-pro-engagement' ); ?></span>
				</div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'auth_show_in_comments', $auth_show_in_comments ); ?>
				</div>
			</div>

			<div class="dpc-field-row">
				<div class="dpc-field-label">
					<strong><?php echo esc_html__( 'Всегда показывать выбор аккаунта при входе через Google', 'divi-pro-engagement' ); ?></strong>
					<span><?php echo esc_html__( 'Передает параметр prompt=select_account, чтобы пользователь мог выбрать нужный Google-аккаунт.', 'divi-pro-engagement' ); ?></span>
				</div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'auth_select_account', $auth_select_account ); ?>
				</div>
			</div>

			<div class="dpc-field-row">
				<div class="dpc-field-label">
					<strong><?php echo esc_html__( 'Автоматически привязывать соцсеть к существующему аккаунту при совпадении Email', 'divi-pro-engagement' ); ?></strong>
					<span><?php echo esc_html__( 'Если на сайте уже есть пользователь с таким Email, вход привяжет соцсеть к его профилю без создания дубля.', 'divi-pro-engagement' ); ?></span>
				</div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'auth_auto_link_email', $auth_auto_link_email ); ?>
				</div>
			</div>

			<div class="dpc-field-row">
				<div class="dpc-field-label">
					<strong><?php echo esc_html__( 'Запретить вход через соцсети для Администраторов в целях безопасности', 'divi-pro-engagement' ); ?></strong>
					<span><?php echo esc_html__( 'Блокирует авторизацию через OAuth для аккаунтов с правами администратора (manage_options).', 'divi-pro-engagement' ); ?></span>
				</div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'auth_disable_admin', $auth_disable_admin ); ?>
				</div>
			</div>

			<div class="dpc-input-row">
				<label for="auth_default_role"><strong><?php echo esc_html__( 'Роль новых пользователей при регистрации', 'divi-pro-engagement' ); ?></strong></label>
				<select id="auth_default_role" name="dpc_settings[auth_default_role]" class="regular-text">
					<?php foreach ( $allowed_roles as $role_key => $role_name ) : ?>
						<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $role_key, $auth_default_role ); ?>>
							<?php echo esc_html( translate_user_role( $role_name ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
		</div>

		<!-- Google -->
		<div class="dpc-card">
			<h2>Google OAuth</h2>
			<div class="dpc-field-row">
				<div class="dpc-field-label"><strong><?php echo esc_html__( 'Включить Google авторизацию', 'divi-pro-engagement' ); ?></strong></div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'auth_google_enable', (int) dpc_get_option( 'auth_google_enable', 0 ) ); ?>
				</div>
			</div>
			<div class="dpc-input-row">
				<label for="auth_google_client_id">Google Client ID</label>
				<input type="text" id="auth_google_client_id" name="dpc_settings[auth_google_client_id]" value="<?php echo esc_attr( (string) dpc_get_option( 'auth_google_client_id', '' ) ); ?>" class="regular-text">
			</div>
			<div class="dpc-input-row">
				<label for="auth_google_client_secret">Google Client Secret</label>
				<input type="password" id="auth_google_client_secret" name="dpc_settings[auth_google_client_secret]" value="<?php echo esc_attr( (string) dpc_get_option( 'auth_google_client_secret', '' ) ); ?>" class="regular-text">
			</div>
		</div>

		<!-- Telegram -->
		<div class="dpc-card">
			<h2>Telegram Login</h2>
			<div class="dpc-field-row">
				<div class="dpc-field-label"><strong><?php echo esc_html__( 'Включить Telegram авторизацию', 'divi-pro-engagement' ); ?></strong></div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'auth_telegram_enable', (int) dpc_get_option( 'auth_telegram_enable', 0 ) ); ?>
				</div>
			</div>
			<div class="dpc-input-row">
				<label for="auth_telegram_bot_username"><?php echo esc_html__( 'Имя бота (Bot Username)', 'divi-pro-engagement' ); ?></label>
				<input type="text" id="auth_telegram_bot_username" name="dpc_settings[auth_telegram_bot_username]" placeholder="MySiteAuthBot" value="<?php echo esc_attr( (string) dpc_get_option( 'auth_telegram_bot_username', '' ) ); ?>" class="regular-text">
			</div>
			<div class="dpc-input-row">
				<label for="auth_telegram_bot_token"><?php echo esc_html__( 'Токен бота (Bot Token)', 'divi-pro-engagement' ); ?></label>
				<input type="password" id="auth_telegram_bot_token" name="dpc_settings[auth_telegram_bot_token]" value="<?php echo esc_attr( (string) dpc_get_option( 'auth_telegram_bot_token', '' ) ); ?>" class="regular-text">
			</div>
		</div>

		<!-- Discord -->
		<div class="dpc-card">
			<h2>Discord OAuth</h2>
			<div class="dpc-field-row">
				<div class="dpc-field-label"><strong><?php echo esc_html__( 'Включить Discord авторизацию', 'divi-pro-engagement' ); ?></strong></div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'auth_discord_enable', (int) dpc_get_option( 'auth_discord_enable', 0 ) ); ?>
				</div>
			</div>
			<div class="dpc-input-row">
				<label for="auth_discord_client_id">Discord Client ID</label>
				<input type="text" id="auth_discord_client_id" name="dpc_settings[auth_discord_client_id]" value="<?php echo esc_attr( (string) dpc_get_option( 'auth_discord_client_id', '' ) ); ?>" class="regular-text">
			</div>
			<div class="dpc-input-row">
				<label for="auth_discord_client_secret">Discord Client Secret</label>
				<input type="password" id="auth_discord_client_secret" name="dpc_settings[auth_discord_client_secret]" value="<?php echo esc_attr( (string) dpc_get_option( 'auth_discord_client_secret', '' ) ); ?>" class="regular-text">
			</div>
		</div>

		<!-- X (Twitter) -->
		<div class="dpc-card">
			<h2>X (Twitter) OAuth 2.0</h2>
			<div class="dpc-field-row">
				<div class="dpc-field-label"><strong><?php echo esc_html__( 'Включить X (Twitter) авторизацию', 'divi-pro-engagement' ); ?></strong></div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'auth_twitter_enable', (int) dpc_get_option( 'auth_twitter_enable', 0 ) ); ?>
				</div>
			</div>
			<div class="dpc-input-row">
				<label for="auth_twitter_client_id">X OAuth 2.0 Client ID</label>
				<input type="text" id="auth_twitter_client_id" name="dpc_settings[auth_twitter_client_id]" value="<?php echo esc_attr( (string) dpc_get_option( 'auth_twitter_client_id', '' ) ); ?>" class="regular-text">
			</div>
			<div class="dpc-input-row">
				<label for="auth_twitter_client_secret">X OAuth 2.0 Client Secret</label>
				<input type="password" id="auth_twitter_client_secret" name="dpc_settings[auth_twitter_client_secret]" value="<?php echo esc_attr( (string) dpc_get_option( 'auth_twitter_client_secret', '' ) ); ?>" class="regular-text">
			</div>
		</div>

		<!-- Facebook -->
		<div class="dpc-card">
			<h2>Facebook Login</h2>
			<div class="dpc-field-row">
				<div class="dpc-field-label"><strong><?php echo esc_html__( 'Включить Facebook авторизацию', 'divi-pro-engagement' ); ?></strong></div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'auth_facebook_enable', (int) dpc_get_option( 'auth_facebook_enable', 0 ) ); ?>
				</div>
			</div>
			<div class="dpc-input-row">
				<label for="auth_facebook_app_id">Facebook App ID</label>
				<input type="text" id="auth_facebook_app_id" name="dpc_settings[auth_facebook_app_id]" value="<?php echo esc_attr( (string) dpc_get_option( 'auth_facebook_app_id', '' ) ); ?>" class="regular-text">
			</div>
			<div class="dpc-input-row">
				<label for="auth_facebook_app_secret">Facebook App Secret</label>
				<input type="password" id="auth_facebook_app_secret" name="dpc_settings[auth_facebook_app_secret]" value="<?php echo esc_attr( (string) dpc_get_option( 'auth_facebook_app_secret', '' ) ); ?>" class="regular-text">
			</div>
		</div>
		<?php
	}

	private function render_rating_tab(): void {
		$rating_type          = (string) dpc_get_option( 'rating_type', 'stars' );
		$rating_access        = (string) dpc_get_option( 'rating_access', 'all' );
		$rating_position      = (string) dpc_get_option( 'rating_position', 'after' );
		$rating_schema_enable = (int) dpc_get_option( 'rating_schema_enable', 1 );
		?>
		<div class="dpc-card">
			<h2><?php echo esc_html__( 'Настройки рейтинга статей', 'divi-pro-engagement' ); ?></h2>

			<div class="dpc-input-row">
				<label for="rating_type"><strong><?php echo esc_html__( 'Тип рейтинга', 'divi-pro-engagement' ); ?></strong></label>
				<select id="rating_type" name="dpc_settings[rating_type]" class="regular-text">
					<option value="stars" <?php selected( 'stars', $rating_type ); ?>><?php echo esc_html__( '5 Звезд (Классический)', 'divi-pro-engagement' ); ?></option>
					<option value="likes" <?php selected( 'likes', $rating_type ); ?>><?php echo esc_html__( 'Лайк / Дизлайк', 'divi-pro-engagement' ); ?></option>
				</select>
			</div>

			<div class="dpc-input-row">
				<label for="rating_access"><strong><?php echo esc_html__( 'Кто может голосовать', 'divi-pro-engagement' ); ?></strong></label>
				<select id="rating_access" name="dpc_settings[rating_access]" class="regular-text">
					<option value="all" <?php selected( 'all', $rating_access ); ?>><?php echo esc_html__( 'Все пользователи (по IP / Cookie)', 'divi-pro-engagement' ); ?></option>
					<option value="logged_in" <?php selected( 'logged_in', $rating_access ); ?>><?php echo esc_html__( 'Только авторизованные пользователи', 'divi-pro-engagement' ); ?></option>
				</select>
			</div>

			<div class="dpc-input-row">
				<label for="rating_position"><strong><?php echo esc_html__( 'Автоматическое размещение в статье', 'divi-pro-engagement' ); ?></strong></label>
				<select id="rating_position" name="dpc_settings[rating_position]" class="regular-text">
					<option value="shortcode" <?php selected( 'shortcode', $rating_position ); ?>><?php echo esc_html__( 'Только шорткод [dpc_post_rating]', 'divi-pro-engagement' ); ?></option>
					<option value="before" <?php selected( 'before', $rating_position ); ?>><?php echo esc_html__( 'Перед контентом статьи', 'divi-pro-engagement' ); ?></option>
					<option value="after" <?php selected( 'after', $rating_position ); ?>><?php echo esc_html__( 'После контента статьи', 'divi-pro-engagement' ); ?></option>
				</select>
			</div>

			<div class="dpc-field-row" style="margin-top: 15px;">
				<div class="dpc-field-label">
					<strong><?php echo esc_html__( 'Микроразметка Schema.org', 'divi-pro-engagement' ); ?></strong>
					<span><?php echo esc_html__( 'Включить микроразметку Schema.org AggregateRating в формате JSON-LD для сниппетов Google.', 'divi-pro-engagement' ); ?></span>
				</div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'rating_schema_enable', $rating_schema_enable ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_comments_tab(): void {
		$comments_vote_access      = (string) dpc_get_option( 'comments_vote_access', 'all' );
		$antispam_honeypot         = (int) dpc_get_option( 'antispam_honeypot', 1 );
		$antispam_turnstile_enable = (int) dpc_get_option( 'antispam_turnstile_enable', 0 );
		$turnstile_site_key        = (string) dpc_get_option( 'turnstile_site_key', '' );
		$turnstile_secret_key      = (string) dpc_get_option( 'turnstile_secret_key', '' );
		?>
		<div class="dpc-card">
			<h2><?php echo esc_html__( 'Голосование за комментарии', 'divi-pro-engagement' ); ?></h2>
			<div class="dpc-input-row">
				<label for="comments_vote_access"><strong><?php echo esc_html__( 'Кто может ставить лайки/дизлайки комментариям', 'divi-pro-engagement' ); ?></strong></label>
				<select id="comments_vote_access" name="dpc_settings[comments_vote_access]" class="regular-text">
					<option value="all" <?php selected( 'all', $comments_vote_access ); ?>><?php echo esc_html__( 'Все могут лайкать комментарии (IP + Cookie)', 'divi-pro-engagement' ); ?></option>
					<option value="logged_in" <?php selected( 'logged_in', $comments_vote_access ); ?>><?php echo esc_html__( 'Только авторизованные пользователи', 'divi-pro-engagement' ); ?></option>
				</select>
			</div>
		</div>

		<div class="dpc-card">
			<h2><?php echo esc_html__( 'Защита от спама', 'divi-pro-engagement' ); ?></h2>

			<div class="dpc-field-row">
				<div class="dpc-field-label">
					<strong><?php echo esc_html__( 'Невидимая ловушка (Honeypot)', 'divi-pro-engagement' ); ?></strong>
					<span><?php echo esc_html__( 'Скрытые поля формы, отсекающие спам-ботов без раздражения реальных пользователей.', 'divi-pro-engagement' ); ?></span>
				</div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'antispam_honeypot', $antispam_honeypot ); ?>
				</div>
			</div>

			<div class="dpc-field-row">
				<div class="dpc-field-label">
					<strong><?php echo esc_html__( 'Cloudflare Turnstile', 'divi-pro-engagement' ); ?></strong>
					<span><?php echo esc_html__( 'Интеллектуальная замена Google reCAPTCHA без капч с картинками.', 'divi-pro-engagement' ); ?></span>
				</div>
				<div class="dpc-field-input">
					<?php $this->render_toggle( 'antispam_turnstile_enable', $antispam_turnstile_enable ); ?>
				</div>
			</div>

			<div class="dpc-input-row">
				<label for="turnstile_site_key">Turnstile Site Key</label>
				<input type="text" id="turnstile_site_key" name="dpc_settings[turnstile_site_key]" value="<?php echo esc_attr( $turnstile_site_key ); ?>" class="regular-text">
			</div>

			<div class="dpc-input-row">
				<label for="turnstile_secret_key">Turnstile Secret Key</label>
				<input type="password" id="turnstile_secret_key" name="dpc_settings[turnstile_secret_key]" value="<?php echo esc_attr( $turnstile_secret_key ); ?>" class="regular-text">
			</div>
		</div>
		<?php
	}

	private function render_toggle( string $key, int $value ): void {
		?>
		<label class="dpc-switch" for="<?php echo esc_attr( $key ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( $key ); ?>"
				name="dpc_settings[<?php echo esc_attr( $key ); ?>]"
				value="1"
				<?php checked( 1, $value ); ?>
			/>
			<span class="dpc-slider"></span>
		</label>
		<?php
	}

	private function render_admin_styles(): void {
		?>
		<style>
			.dpc-wrap {
				max-width: 900px;
				margin-top: 20px;
			}
			.dpc-nav-tabs {
				margin-bottom: 20px;
			}
			.dpc-card {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 8px;
				padding: 24px;
				margin-bottom: 20px;
				box-shadow: 0 1px 3px rgba(0,0,0,0.04);
			}
			.dpc-card h2 {
				margin-top: 0;
				margin-bottom: 8px;
				font-size: 1.25rem;
				color: #1d2327;
			}
			.dpc-card-subtitle {
				color: #646970;
				margin-top: 0;
				margin-bottom: 20px;
			}
			.dpc-field-row {
				display: flex;
				justify-content: space-between;
				align-items: center;
				padding: 14px 0;
				border-bottom: 1px solid #f0f0f1;
			}
			.dpc-field-row:last-child {
				border-bottom: none;
			}
			.dpc-field-label {
				display: flex;
				flex-direction: column;
				gap: 4px;
				max-width: 80%;
			}
			.dpc-field-label strong {
				font-size: 14px;
				color: #1d2327;
			}
			.dpc-field-label span {
				font-size: 13px;
				color: #646970;
			}
			.dpc-input-row {
				margin-top: 15px;
				display: flex;
				flex-direction: column;
				gap: 6px;
			}
			.dpc-input-row label {
				font-weight: 600;
				color: #1d2327;
			}
			.dpc-input-row input.regular-text,
			.dpc-input-row select.regular-text {
				max-width: 450px;
				width: 100%;
				padding: 6px 10px;
				border-radius: 4px;
			}
			.dpc-info-card {
				background: #f6f7f7;
				border-left: 4px solid #2271b1;
			}
			.dpc-info-card h3 {
				margin-top: 0;
				color: #1d2327;
			}
			.dpc-code-box {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				padding: 12px;
				margin-top: 10px;
				display: flex;
				flex-direction: column;
				gap: 8px;
			}
			.dpc-code-box code {
				word-break: break-all;
				background: #edf2f7;
				padding: 2px 6px;
				border-radius: 3px;
			}
			.dpc-shortcode-list {
				margin-top: 10px;
				margin-left: 20px;
				list-style-type: disc;
			}
			.dpc-shortcode-list code {
				font-weight: 600;
				background: #fff;
				padding: 2px 6px;
				border: 1px solid #dcdcde;
				border-radius: 4px;
			}
			/* Switch Toggle */
			.dpc-switch {
				position: relative;
				display: inline-block;
				width: 44px;
				height: 24px;
				flex-shrink: 0;
			}
			.dpc-switch input {
				opacity: 0;
				width: 0;
				height: 0;
			}
			.dpc-slider {
				position: absolute;
				cursor: pointer;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				background-color: #c3c4c7;
				transition: .25s ease-in-out;
				border-radius: 24px;
			}
			.dpc-slider:before {
				position: absolute;
				content: "";
				height: 18px;
				width: 18px;
				left: 3px;
				bottom: 3px;
				background-color: white;
				transition: .25s ease-in-out;
				border-radius: 50%;
				box-shadow: 0 1px 3px rgba(0,0,0,0.2);
			}
			.dpc-switch input:checked + .dpc-slider {
				background-color: #2271b1;
			}
			.dpc-switch input:focus + .dpc-slider {
				box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2271b1;
			}
			.dpc-switch input:checked + .dpc-slider:before {
				transform: translateX(20px);
			}
		</style>
		<?php
	}
}
