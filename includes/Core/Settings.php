<?php
declare(strict_types=1);

namespace DiviProEngagement\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {
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
	}

	public function register_menu_page(): void {
		add_options_page(
			esc_html__( 'Divi Engagement', 'divi-pro-engagement' ),
			esc_html__( 'Divi Engagement', 'divi-pro-engagement' ),
			'manage_options',
			'dpc-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			'dpc_settings_group',
			'dpc_settings',
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => [],
			]
		);

		add_settings_section(
			'dpc_main_section',
			esc_html__( 'Основные модули', 'divi-pro-engagement' ),
			[ $this, 'render_main_section_description' ],
			'dpc-settings'
		);

		add_settings_field(
			'enable_comments',
			esc_html__( 'Улучшение комментариев Divi 5', 'divi-pro-engagement' ),
			[ $this, 'render_checkbox_field' ],
			'dpc-settings',
			'dpc_main_section',
			[
				'id'          => 'enable_comments',
				'label'       => esc_html__( 'Включить улучшение комментариев Divi 5 (AJAX, Лайки, Антиспам).', 'divi-pro-engagement' ),
			]
		);

		add_settings_field(
			'enable_post_rating',
			esc_html__( 'Рейтинг статей', 'divi-pro-engagement' ),
			[ $this, 'render_checkbox_field' ],
			'dpc-settings',
			'dpc_main_section',
			[
				'id'          => 'enable_post_rating',
				'label'       => esc_html__( 'Включить рейтинг статей (Schema.org + шорткод [dpc_post_rating]).', 'divi-pro-engagement' ),
			]
		);

		add_settings_field(
			'enable_social_auth',
			esc_html__( 'Авторизация через соцсети', 'divi-pro-engagement' ),
			[ $this, 'render_checkbox_field' ],
			'dpc-settings',
			'dpc_main_section',
			[
				'id'          => 'enable_social_auth',
				'label'       => esc_html__( 'Включить авторизацию через соцсети (шорткод [dpc_social_login]).', 'divi-pro-engagement' ),
			]
		);
	}

	public function sanitize_settings( mixed $input ): array {
		$sanitized = [];
		if ( ! is_array( $input ) ) {
			return $sanitized;
		}

		$checkbox_keys = [
			'enable_comments',
			'enable_post_rating',
			'enable_social_auth',
		];

		foreach ( $checkbox_keys as $key ) {
			$sanitized[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
		}

		return $sanitized;
	}

	public function render_main_section_description(): void {
		echo '<p>' . esc_html__( 'Управляйте модулями вовлеченности для сайта и шаблонов Divi 5.', 'divi-pro-engagement' ) . '</p>';
	}

	/**
	 * @param array<string, string> $args Field arguments.
	 */
	public function render_checkbox_field( array $args ): void {
		$key = $args['id'];
		$label = $args['label'];
		$value = (int) dpc_get_option( $key, 0 );
		?>
		<label for="<?php echo esc_attr( $key ); ?>">
			<input
				type="checkbox"
				id="<?php echo esc_attr( $key ); ?>"
				name="dpc_settings[<?php echo esc_attr( $key ); ?>]"
				value="1"
				<?php checked( 1, $value ); ?>
			/>
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'dpc_settings_group' );
				do_settings_sections( 'dpc-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
