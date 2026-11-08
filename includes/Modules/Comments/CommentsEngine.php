<?php
declare(strict_types=1);

namespace DiviProEngagement\Modules\Comments;

use DiviProEngagement\Modules\Auth\AuthManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CommentsEngine {
	private const HASH_BUFFER_LIMIT = 200;

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'comments_template', [ $this, 'override_comments_template' ], 999 );
		add_filter( 'get_avatar_url', [ AuthManager::get_instance(), 'filter_avatar_url' ], 10, 3 );
		add_filter( 'pre_get_avatar_data', [ AuthManager::get_instance(), 'filter_avatar_data' ], 10, 2 );
		add_filter( 'kses_allowed_protocols', [ AuthManager::get_instance(), 'allow_data_protocol' ] );

		add_action( 'wp_ajax_dpc_submit_comment', [ $this, 'handle_ajax_submit_comment' ] );
		add_action( 'wp_ajax_nopriv_dpc_submit_comment', [ $this, 'handle_ajax_submit_comment' ] );

		add_action( 'wp_ajax_dpc_load_comments', [ $this, 'handle_ajax_load_comments' ] );
		add_action( 'wp_ajax_nopriv_dpc_load_comments', [ $this, 'handle_ajax_load_comments' ] );

		add_action( 'wp_ajax_dpc_vote_comment', [ $this, 'handle_ajax_vote_comment' ] );
		add_action( 'wp_ajax_nopriv_dpc_vote_comment', [ $this, 'handle_ajax_vote_comment' ] );
	}

	/**
	 * Override default WordPress / Divi 5 comments template.
	 *
	 * @param string $template Default template path.
	 * @return string
	 */
	public function override_comments_template( string $template ): string {
		if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
			return $template;
		}

		$custom_template = DPC_PATH . 'includes/Modules/Comments/comments-template.php';
		if ( file_exists( $custom_template ) ) {
			return $custom_template;
		}

		return $template;
	}

	/**
	 * Detect current page language code ('uk', 'ru', or 'en').
	 *
	 * @param string|null $lang_override Optional language code passed from frontend DOM <html lang>.
	 * @return string
	 */
	public static function detect_lang_code( ?string $lang_override = null ): string {
		$raw = '';

		if ( null !== $lang_override && '' !== trim( $lang_override ) ) {
			$raw = strtolower( trim( $lang_override ) );
		} elseif ( function_exists( 'pll_current_language' ) ) {
			$pll = pll_current_language( 'slug' );
			if ( is_string( $pll ) && '' !== $pll ) {
				$raw = strtolower( $pll );
			}
		}

		if ( '' === $raw && defined( 'ICL_LANGUAGE_CODE' ) && is_string( ICL_LANGUAGE_CODE ) ) {
			$raw = strtolower( ICL_LANGUAGE_CODE );
		}

		if ( '' === $raw ) {
			$raw = strtolower( get_locale() );
		}

		if ( str_starts_with( $raw, 'uk' ) || str_starts_with( $raw, 'ua' ) ) {
			return 'uk';
		}

		if ( str_starts_with( $raw, 'ru' ) ) {
			return 'ru';
		}

		return 'en';
	}

	/**
	 * Get localized UI dictionary strictly matching the current page language.
	 *
	 * @param string|null $lang_override Optional language override ('uk', 'ru', 'en').
	 * @return array<string, string>
	 */
	public static function get_strings( ?string $lang_override = null ): array {
		$lang = self::detect_lang_code( $lang_override );

		if ( 'uk' === $lang ) {
			return [
				'lang'            => 'uk',
				'name'            => "Ім'я *",
				'placeholder'     => 'Напишіть коментар...',
				'submit'          => 'Надіслати',
				'reply'           => 'Відповісти',
				'cancel_reply'    => 'Скасувати',
				'sort_popular'    => 'Популярні',
				'sort_newest'     => 'Нові',
				'sort_oldest'     => 'Старі',
				'author_badge'    => 'Автор',
				'nudge'           => 'Залишити коментар',
				'moderation'      => '✓ Дякуємо! Коментар надіслано на модерацію.',
				'error_empty'     => 'Введіть текст коментаря.',
				'error_name'      => "Вкажіть ваше ім'я.",
				'error_turnstile' => 'Перевірка антиспам не пройдена.',
				'error_closed'    => 'Коментарі закриті.',
				'error_generic'   => 'Помилка збереження коментаря.',
			];
		}

		if ( 'ru' === $lang ) {
			return [
				'lang'            => 'ru',
				'name'            => 'Имя *',
				'placeholder'     => 'Напишите комментарий...',
				'submit'          => 'Отправить',
				'reply'           => 'Ответить',
				'cancel_reply'    => 'Отменить',
				'sort_popular'    => 'Популярные',
				'sort_newest'     => 'Новые',
				'sort_oldest'     => 'Старые',
				'author_badge'    => 'Автор',
				'nudge'           => 'Оставить комментарий',
				'moderation'      => '✓ Спасибо! Комментарий отправлен на модерацию.',
				'error_empty'     => 'Введите текст комментария.',
				'error_name'      => 'Укажите ваше имя.',
				'error_turnstile' => 'Проверка антиспама не пройдена.',
				'error_closed'    => 'Комментарии закрыты.',
				'error_generic'   => 'Ошибка сохранения комментария.',
			];
		}

		return [
			'lang'            => 'en',
			'name'            => 'Name *',
			'placeholder'     => 'Write a comment...',
			'submit'          => 'Send',
			'reply'           => 'Reply',
			'cancel_reply'    => 'Cancel',
			'sort_popular'    => 'Popular',
			'sort_newest'     => 'Newest',
			'sort_oldest'     => 'Oldest',
			'author_badge'    => 'Author',
			'nudge'           => 'Leave a comment',
			'moderation'      => '✓ Thank you! Your comment is awaiting moderation.',
			'error_empty'     => 'Please enter a comment.',
			'error_name'      => 'Please enter your name.',
			'error_turnstile' => 'Anti-spam verification failed.',
			'error_closed'    => 'Comments are closed.',
			'error_generic'   => 'Failed to save comment.',
		];
	}

	/**
	 * Format relative time strictly in the detected page language without mixing locales.
	 *
	 * @param int         $timestamp     Comment GMT unix timestamp.
	 * @param string|null $lang_override Optional language override.
	 * @return string
	 */
	public static function format_relative_time( int $timestamp, ?string $lang_override = null ): string {
		$lang = self::detect_lang_code( $lang_override );
		$diff = max( 1, time() - $timestamp );

		if ( $diff < 60 ) {
			return match ( $lang ) {
				'uk'    => 'щойно',
				'ru'    => 'только что',
				default => 'just now',
			};
		}

		$minutes = (int) floor( $diff / 60 );
		if ( $minutes < 60 ) {
			return match ( $lang ) {
				'uk'    => $minutes . ' хв тому',
				'ru'    => $minutes . ' мин назад',
				default => $minutes . 'm ago',
			};
		}

		$hours = (int) floor( $diff / 3600 );
		if ( $hours < 24 ) {
			return match ( $lang ) {
				'uk'    => $hours . ' год тому',
				'ru'    => $hours . ' ч назад',
				default => $hours . 'h ago',
			};
		}

		$days = (int) floor( $diff / 86400 );
		if ( $days < 30 ) {
			return match ( $lang ) {
				'uk'    => $days . ' дн тому',
				'ru'    => $days . ' дн назад',
				default => $days . 'd ago',
			};
		}

		$months = (int) floor( $days / 30 );
		if ( $months < 12 ) {
			return match ( $lang ) {
				'uk'    => $months . ' міс тому',
				'ru'    => $months . ' мес назад',
				default => $months . 'mo ago',
			};
		}

		$years = max( 1, (int) floor( $days / 365 ) );
		return match ( $lang ) {
			'uk'    => $years . ' р тому',
			'ru'    => $years . ' г назад',
			default => $years . 'y ago',
		};
	}

	/**
	 * Safely retrieve public display name for a comment without leaking admin user_login.
	 *
	 * @param \WP_Comment $comment       Comment object.
	 * @param string|null $lang_override Optional language override.
	 * @return string
	 */
	public function get_safe_author_name( \WP_Comment $comment, ?string $lang_override = null ): string {
		$strings = self::get_strings( $lang_override );
		$user_id = (int) $comment->user_id;
		$name    = trim( (string) get_comment_author( $comment ) );

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user instanceof \WP_User ) {
				$display_name = trim( (string) $user->display_name );
				if ( '' !== $display_name ) {
					$name = $display_name;
				}

				if ( user_can( $user, 'manage_options' ) ) {
					if ( 'admin' === strtolower( $name ) || ( 'admin' === strtolower( (string) $user->user_login ) && strtolower( $name ) === strtolower( (string) $user->user_login ) ) ) {
						$name = $strings['author_badge'];
					}
				}
			}
		}

		if ( '' === $name || 'admin' === strtolower( $name ) ) {
			return $strings['author_badge'];
		}

		return $name;
	}

	/**
	 * Fetch and render comments sorted by 'popular', 'newest', or 'oldest'.
	 *
	 * @param int         $post_id       Post ID.
	 * @param string      $sort          Sort mode.
	 * @param string|null $lang_override Optional language override.
	 * @return string
	 */
	public function get_sorted_comments_html( int $post_id, string $sort = 'newest', ?string $lang_override = null ): string {
		$comments = get_comments(
			[
				'post_id' => $post_id,
				'status'  => 'approve',
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
			]
		);

		if ( empty( $comments ) || ! is_array( $comments ) ) {
			return '';
		}

		$top_level = [];
		$children  = [];

		foreach ( $comments as $c ) {
			if ( ! ( $c instanceof \WP_Comment ) ) {
				continue;
			}
			if ( 0 === (int) $c->comment_parent ) {
				$top_level[] = $c;
			} else {
				$children[] = $c;
			}
		}

		if ( 'popular' === $sort ) {
			usort(
				$top_level,
				static function ( \WP_Comment $a, \WP_Comment $b ): int {
					$likes_a = (int) get_comment_meta( (int) $a->comment_ID, '_dpc_comment_likes', true );
					$likes_b = (int) get_comment_meta( (int) $b->comment_ID, '_dpc_comment_likes', true );
					if ( $likes_a !== $likes_b ) {
						return $likes_b <=> $likes_a;
					}
					return strcmp( (string) $b->comment_date_gmt, (string) $a->comment_date_gmt );
				}
			);
		} elseif ( 'newest' === $sort ) {
			$top_level = array_reverse( $top_level );
		}

		$sorted_comments = array_merge( $top_level, $children );

		$force_depth = static fn() => 5;
		add_filter( 'option_thread_comments', '__return_true' );
		add_filter( 'option_thread_comments_depth', $force_depth );

		ob_start();
		wp_list_comments(
			[
				'style'      => 'ul',
				'max_depth'  => 5,
				'short_ping' => true,
				'callback'   => function ( \WP_Comment $comment, array $args, int $depth ) use ( $lang_override ): void {
					$this->render_comment_item( $comment, $args, $depth, $lang_override );
				},
			],
			$sorted_comments
		);
		$html_out = (string) ob_get_clean();

		remove_filter( 'option_thread_comments', '__return_true' );
		remove_filter( 'option_thread_comments_depth', $force_depth );

		return $html_out;
	}

	/**
	 * Calculate 1-indexed nesting depth of a comment (clamped between 1 and 5).
	 *
	 * @param \WP_Comment $comment Comment object.
	 * @return int
	 */
	private function calculate_comment_depth( \WP_Comment $comment ): int {
		$depth   = 1;
		$curr_id = (int) $comment->comment_parent;
		while ( $curr_id > 0 && $depth < 5 ) {
			++$depth;
			$parent_obj = get_comment( $curr_id );
			if ( ! ( $parent_obj instanceof \WP_Comment ) ) {
				break;
			}
			$curr_id = (int) $parent_obj->comment_parent;
		}
		return $depth;
	}

	/**
	 * Custom callback for rendering a single comment card.
	 *
	 * @param \WP_Comment          $comment       Comment object.
	 * @param array<string, mixed> $args          Display arguments.
	 * @param int                  $depth         Comment nesting depth.
	 * @param string|null          $lang_override Optional language override.
	 */
	public function render_comment_item( \WP_Comment $comment, array $args, int $depth, ?string $lang_override = null ): void {
		$strings     = self::get_strings( $lang_override );
		$comment_id  = (int) $comment->comment_ID;
		$post_id     = (int) $comment->comment_post_ID;
		$user_id     = (int) $comment->user_id;
		$parent_id   = (int) $comment->comment_parent;
		$author_name = $this->get_safe_author_name( $comment, $lang_override );
		$avatar_url  = get_avatar_url( $comment, [ 'size' => 88 ] );

		if ( $depth <= 1 && $parent_id > 0 ) {
			$depth = $this->calculate_comment_depth( $comment );
		}
		$depth = max( 1, min( 5, $depth ) );

		$is_verified_author = false;
		if ( $user_id > 0 ) {
			if ( user_can( $user_id, 'manage_options' ) || $user_id === (int) get_post_field( 'post_author', $post_id ) ) {
				$is_verified_author = true;
			}
		}

		$comment_timestamp = (int) get_comment_time( 'U', true, $comment );
		$time_ago          = self::format_relative_time( $comment_timestamp, $lang_override );

		$parent_author = '';
		if ( $parent_id > 0 ) {
			$parent_comment = get_comment( $parent_id );
			if ( $parent_comment instanceof \WP_Comment ) {
				$parent_author = $this->get_safe_author_name( $parent_comment, $lang_override );
			}
		}

		$likes       = max( 0, (int) get_comment_meta( $comment_id, '_dpc_comment_likes', true ) );
		$dislikes    = max( 0, (int) get_comment_meta( $comment_id, '_dpc_comment_dislikes', true ) );
		$user_vote   = $this->get_visitor_comment_vote( $comment_id );
		$vote_access = (string) dpc_get_option( 'comments_vote_access', 'all' );
		$is_locked   = ( 'logged_in' === $vote_access && ! is_user_logged_in() );
		$safe_avatar = str_starts_with( $avatar_url, 'data:image/svg+xml' ) ? $avatar_url : esc_url( $avatar_url );
		$depth_class = 'dpc-comment-item depth-' . $depth . ' dpc-depth-' . $depth;
		?>
		<li id="comment-<?php echo esc_attr( (string) $comment_id ); ?>" <?php comment_class( $depth_class, $comment ); ?> data-comment-id="<?php echo esc_attr( (string) $comment_id ); ?>" data-depth="<?php echo esc_attr( (string) $depth ); ?>">
			<article class="dpc-comment-card">
				<div class="dpc-comment-card__avatar">
					<img src="<?php echo esc_attr( $safe_avatar ); ?>" alt="<?php echo esc_attr( $author_name ); ?>" width="44" height="44" loading="lazy" />
				</div>

				<div class="dpc-comment-card__body">
					<header class="dpc-comment-card__meta">
						<span class="dpc-comment-author"><?php echo esc_html( $author_name ); ?></span>

						<?php if ( $is_verified_author ) : ?>
							<span class="dpc-badge-author">
								<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<polyline points="20 6 9 17 4 12"/>
								</svg>
								<span data-i18n="author_badge"><?php echo esc_html( $strings['author_badge'] ); ?></span>
							</span>
						<?php endif; ?>

						<?php if ( '' !== $parent_author ) : ?>
							<span class="dpc-comment-parent-tag">&rarrhk; @<?php echo esc_html( $parent_author ); ?></span>
						<?php endif; ?>

						<time
							class="dpc-comment-time"
							datetime="<?php echo esc_attr( get_comment_date( 'c', $comment ) ); ?>"
							data-timestamp="<?php echo esc_attr( (string) $comment_timestamp ); ?>"
						>
							<?php echo esc_html( $time_ago ); ?>
						</time>
					</header>

					<div class="dpc-comment-card__text">
						<?php echo wp_kses_post( wpautop( get_comment_text( $comment ) ) ); ?>
					</div>

					<footer class="dpc-comment-card__footer">
						<div class="dpc-comment-votes<?php echo $is_locked ? ' is-locked' : ''; ?>" data-comment-id="<?php echo esc_attr( (string) $comment_id ); ?>" data-locked="<?php echo $is_locked ? '1' : '0'; ?>">
							<button type="button" class="dpc-c-vote-btn dpc-c-like<?php echo 'like' === $user_vote ? ' is-active' : ''; ?>" data-vote="like" aria-label="Like">
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/>
								</svg>
								<span class="count"><?php echo esc_html( (string) $likes ); ?></span>
							</button>

							<button type="button" class="dpc-c-vote-btn dpc-c-dislike<?php echo 'dislike' === $user_vote ? ' is-active' : ''; ?>" data-vote="dislike" aria-label="Dislike">
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"/>
								</svg>
								<span class="count"><?php echo esc_html( (string) $dislikes ); ?></span>
							</button>

							<span class="dpc-c-lock-tooltip" aria-hidden="true">🔒</span>
						</div>

						<?php if ( comments_open( $post_id ) ) : ?>
							<button
								type="button"
								class="dpc-comment-reply-btn"
								data-comment-id="<?php echo esc_attr( (string) $comment_id ); ?>"
								data-author="<?php echo esc_attr( $author_name ); ?>"
							>
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<polyline points="9 14 4 9 9 4"/>
									<path d="M20 20v-7a4 4 0 0 0-4-4H4"/>
								</svg>
								<span data-i18n="reply"><?php echo esc_html( $strings['reply'] ); ?></span>
							</button>
						<?php endif; ?>
					</footer>

					<div class="dpc-reply-form-slot"></div>
				</div>
			</article>
		<?php
	}

	/**
	 * Handle AJAX comment submission.
	 */
	public function handle_ajax_submit_comment(): void {
		check_ajax_referer( 'dpc_frontend_nonce', 'nonce' );

		$lang    = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['lang'] ) ) : null;
		$strings = self::get_strings( $lang );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$parent  = isset( $_POST['comment_parent'] ) ? absint( $_POST['comment_parent'] ) : 0;
		$content = isset( $_POST['comment'] ) ? trim( wp_kses_post( wp_unslash( (string) $_POST['comment'] ) ) ) : '';

		if ( $post_id <= 0 || ! comments_open( $post_id ) ) {
			wp_send_json_error( [ 'message' => $strings['error_closed'] ], 400 );
		}

		if ( '' === $content ) {
			wp_send_json_error( [ 'message' => $strings['error_empty'] ], 400 );
		}

		// 1. AntiSpam: Honeypot check
		if ( (bool) dpc_get_option( 'antispam_honeypot', 1 ) ) {
			$hp = isset( $_POST['dpc_hp_field'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['dpc_hp_field'] ) ) : '';
			if ( '' !== $hp ) {
				wp_send_json_error( [ 'message' => 'Spam detected.' ], 403 );
			}
		}

		$is_logged_in = is_user_logged_in();

		// 2. AntiSpam: Cloudflare Turnstile check for guests
		if ( ! $is_logged_in && (bool) dpc_get_option( 'antispam_turnstile_enable', 0 ) ) {
			$site_key   = (string) dpc_get_option( 'turnstile_site_key', '' );
			$secret_key = (string) dpc_get_option( 'turnstile_secret_key', '' );

			if ( '' !== $site_key && '' !== $secret_key ) {
				$token = isset( $_POST['cf-turnstile-response'] )
					? sanitize_text_field( wp_unslash( (string) $_POST['cf-turnstile-response'] ) )
					: '';

				if ( ! $this->verify_turnstile_token( $token, $secret_key ) ) {
					wp_send_json_error( [ 'message' => $strings['error_turnstile'] ], 403 );
				}
			}
		}

		$ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( $is_logged_in ) {
			$user           = wp_get_current_user();
			$comment_author = trim( (string) $user->display_name );
			if ( '' === $comment_author || ( user_can( $user, 'manage_options' ) && 'admin' === strtolower( $comment_author ) ) ) {
				$comment_author = $strings['author_badge'];
			}

			$comment_data = [
				'comment_post_ID'      => $post_id,
				'comment_author'       => $comment_author,
				'comment_author_email' => $user->user_email,
				'comment_author_url'   => $user->user_url,
				'comment_content'      => $content,
				'comment_type'         => 'comment',
				'comment_parent'       => $parent,
				'user_id'              => $user->ID,
				'comment_author_IP'    => $ip,
				'comment_agent'        => $user_agent,
				'comment_approved'     => 1,
			];

			$comment_id = wp_insert_comment( wp_slash( $comment_data ) );
			if ( ! $comment_id ) {
				wp_send_json_error( [ 'message' => $strings['error_generic'] ], 500 );
			}

			$comment_obj = get_comment( $comment_id );
			$html        = '';

			if ( $comment_obj instanceof \WP_Comment ) {
				$calc_depth = $this->calculate_comment_depth( $comment_obj );
				ob_start();
				$this->render_comment_item( $comment_obj, [], $calc_depth, $lang );
				echo '</li>';
				$html = (string) ob_get_clean();
			}

			wp_send_json_success(
				[
					'status'      => 'approved',
					'html'        => $html,
					'parent_id'   => $parent,
					'total_count' => (int) get_comments_number( $post_id ),
				]
			);
		}

		// Guest comment: Name only, no email required, forced moderation (comment_approved = 0)
		$author_name = isset( $_POST['author'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['author'] ) ) : '';
		if ( '' === $author_name ) {
			wp_send_json_error( [ 'message' => $strings['error_name'] ], 400 );
		}

		add_filter( 'pre_option_require_name_email', '__return_zero' );

		$comment_data = [
			'comment_post_ID'      => $post_id,
			'comment_author'       => $author_name,
			'comment_author_email' => '',
			'comment_author_url'   => '',
			'comment_content'      => $content,
			'comment_type'         => 'comment',
			'comment_parent'       => $parent,
			'user_id'              => 0,
			'comment_author_IP'    => $ip,
			'comment_agent'        => $user_agent,
			'comment_approved'     => 0,
		];

		$comment_id = wp_insert_comment( wp_slash( $comment_data ) );

		remove_filter( 'pre_option_require_name_email', '__return_zero' );

		if ( ! $comment_id ) {
			wp_send_json_error( [ 'message' => $strings['error_generic'] ], 500 );
		}

		wp_notify_moderator( $comment_id );

		wp_send_json_success(
			[
				'status'  => 'moderation',
				'message' => $strings['moderation'],
			]
		);
	}

	/**
	 * Handle AJAX comment sorting.
	 */
	public function handle_ajax_load_comments(): void {
		check_ajax_referer( 'dpc_frontend_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$sort    = isset( $_POST['sort'] ) ? sanitize_key( wp_unslash( (string) $_POST['sort'] ) ) : 'newest';
		$lang    = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['lang'] ) ) : null;

		if ( $post_id <= 0 ) {
			wp_send_json_error( [ 'code' => 'invalid_post' ], 400 );
		}

		if ( ! in_array( $sort, [ 'popular', 'newest', 'oldest' ], true ) ) {
			$sort = 'newest';
		}

		$html = $this->get_sorted_comments_html( $post_id, $sort, $lang );

		wp_send_json_success(
			[
				'html'        => $html,
				'total_count' => (int) get_comments_number( $post_id ),
			]
		);
	}

	/**
	 * Handle AJAX comment voting (Like / Dislike).
	 */
	public function handle_ajax_vote_comment(): void {
		check_ajax_referer( 'dpc_frontend_nonce', 'nonce' );

		$comment_id = isset( $_POST['comment_id'] ) ? absint( $_POST['comment_id'] ) : 0;
		$vote       = isset( $_POST['vote'] ) ? sanitize_key( wp_unslash( (string) $_POST['vote'] ) ) : '';

		if ( $comment_id <= 0 || ! get_comment( $comment_id ) || ! in_array( $vote, [ 'like', 'dislike' ], true ) ) {
			wp_send_json_error( [ 'code' => 'invalid_request' ], 400 );
		}

		$vote_access = (string) dpc_get_option( 'comments_vote_access', 'all' );
		if ( 'logged_in' === $vote_access && ! is_user_logged_in() ) {
			wp_send_json_error( [ 'code' => 'auth_required' ], 403 );
		}

		$likes    = max( 0, (int) get_comment_meta( $comment_id, '_dpc_comment_likes', true ) );
		$dislikes = max( 0, (int) get_comment_meta( $comment_id, '_dpc_comment_dislikes', true ) );

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			$votes   = get_user_meta( $user_id, '_dpc_comment_votes', true );
			if ( ! is_array( $votes ) ) {
				$votes = [];
			}

			$prev_vote = isset( $votes[ $comment_id ] ) ? (string) $votes[ $comment_id ] : '';
			if ( $prev_vote === $vote ) {
				wp_send_json_success(
					[
						'likes'     => $likes,
						'dislikes'  => $dislikes,
						'user_vote' => $vote,
					]
				);
			}

			if ( 'like' === $prev_vote && 'dislike' === $vote ) {
				$likes = max( 0, $likes - 1 );
				++$dislikes;
			} elseif ( 'dislike' === $prev_vote && 'like' === $vote ) {
				$dislikes = max( 0, $dislikes - 1 );
				++$likes;
			} else {
				if ( 'like' === $vote ) {
					++$likes;
				} else {
					++$dislikes;
				}
			}

			$votes[ $comment_id ] = $vote;
			update_user_meta( $user_id, '_dpc_comment_votes', $votes );
		} else {
			if ( $this->has_guest_voted_comment( $comment_id ) ) {
				wp_send_json_success(
					[
						'likes'     => $likes,
						'dislikes'  => $dislikes,
						'user_vote' => $this->get_visitor_comment_vote( $comment_id ) ?: $vote,
					]
				);
			}

			if ( 'like' === $vote ) {
				++$likes;
			} else {
				++$dislikes;
			}

			$this->record_guest_comment_vote( $comment_id, $vote );
		}

		update_comment_meta( $comment_id, '_dpc_comment_likes', $likes );
		update_comment_meta( $comment_id, '_dpc_comment_dislikes', $dislikes );

		wp_send_json_success(
			[
				'likes'     => $likes,
				'dislikes'  => $dislikes,
				'user_vote' => $vote,
			]
		);
	}

	/**
	 * Verify Cloudflare Turnstile token via siteverify endpoint.
	 *
	 * @param string $token      Client token.
	 * @param string $secret_key Secret key.
	 * @return bool
	 */
	private function verify_turnstile_token( string $token, string $secret_key ): bool {
		if ( '' === $token ) {
			return false;
		}

		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			[
				'timeout' => 10,
				'body'    => [
					'secret'   => $secret_key,
					'response' => $token,
					'remoteip' => $ip,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) && ! empty( $data['success'] );
	}

	/**
	 * Get visitor's existing vote on a comment.
	 *
	 * @param int $comment_id Comment ID.
	 * @return string
	 */
	private function get_visitor_comment_vote( int $comment_id ): string {
		if ( is_user_logged_in() ) {
			$votes = get_user_meta( get_current_user_id(), '_dpc_comment_votes', true );
			if ( is_array( $votes ) && isset( $votes[ $comment_id ] ) ) {
				return (string) $votes[ $comment_id ];
			}
			return '';
		}

		$cookie_name = 'dpc_c_voted_' . $comment_id;
		if ( isset( $_COOKIE[ $cookie_name ] ) ) {
			return sanitize_key( wp_unslash( (string) $_COOKIE[ $cookie_name ] ) );
		}

		return '';
	}

	/**
	 * Check whether guest has already voted on a comment.
	 *
	 * @param int $comment_id Comment ID.
	 * @return bool
	 */
	private function has_guest_voted_comment( int $comment_id ): bool {
		$cookie_name = 'dpc_c_voted_' . $comment_id;
		if ( isset( $_COOKIE[ $cookie_name ] ) ) {
			return true;
		}

		$hashes = get_comment_meta( $comment_id, '_dpc_comment_hashes', true );
		if ( ! is_array( $hashes ) ) {
			return false;
		}

		return in_array( $this->get_visitor_short_hash(), $hashes, true );
	}

	/**
	 * Record guest comment vote in 200-item circular buffer and set cookie.
	 *
	 * @param int    $comment_id Comment ID.
	 * @param string $vote       'like' or 'dislike'.
	 */
	private function record_guest_comment_vote( int $comment_id, string $vote ): void {
		$hashes = get_comment_meta( $comment_id, '_dpc_comment_hashes', true );
		if ( ! is_array( $hashes ) ) {
			$hashes = [];
		}

		$short_hash = $this->get_visitor_short_hash();
		if ( ! in_array( $short_hash, $hashes, true ) ) {
			$hashes[] = $short_hash;
		}

		if ( count( $hashes ) > self::HASH_BUFFER_LIMIT ) {
			$hashes = array_slice( $hashes, -self::HASH_BUFFER_LIMIT );
		}

		update_comment_meta( $comment_id, '_dpc_comment_hashes', $hashes );

		if ( ! headers_sent() ) {
			setcookie(
				'dpc_c_voted_' . $comment_id,
				$vote,
				time() + YEAR_IN_SECONDS,
				COOKIEPATH ?: '/',
				COOKIE_DOMAIN ?: '',
				is_ssl(),
				false
			);
		}
	}

	/**
	 * Generate 10-character visitor hash from IP and User-Agent.
	 *
	 * @return string
	 */
	private function get_visitor_short_hash(): string {
		$ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		return substr( md5( $ip . $user_agent ), 0, 10 );
	}
}
