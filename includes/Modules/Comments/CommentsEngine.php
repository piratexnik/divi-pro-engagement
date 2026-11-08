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
		add_action( 'wp_footer', [ $this, 'render_floating_nudge' ], 30 );

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
	 * Render complete modern comments section (Form on top + Sortable Header + Comment Tree).
	 *
	 * @param int|null $post_id Optional post ID.
	 * @return string
	 */
	public function render_comments_section( ?int $post_id = null ): string {
		if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
			return '';
		}

		$post_id = $post_id ?: (int) get_the_ID();
		if ( $post_id <= 0 || post_password_required( $post_id ) ) {
			return '';
		}

		$comments_open = comments_open( $post_id );
		$total_count   = (int) get_comments_number( $post_id );

		if ( ! $comments_open && 0 === $total_count ) {
			return '';
		}

		$skin = (string) dpc_get_option( 'comments_skin', 'light' );
		if ( ! in_array( $skin, [ 'light', 'dark' ], true ) ) {
			$skin = 'light';
		}

		$i18n          = $this->get_localized_strings();
		$turnstile_on  = (bool) dpc_get_option( 'antispam_turnstile_enable', 0 );
		$turnstile_key = (string) dpc_get_option( 'turnstile_site_key', '' );
		$turnstile_sec = (string) dpc_get_option( 'turnstile_secret_key', '' );
		$honeypot_on   = (bool) dpc_get_option( 'antispam_honeypot', 1 );

		ob_start();
		?>
		<section id="dpc-comments" class="dpc-comments-area dpc-comments-skin-<?php echo esc_attr( $skin ); ?>" data-post-id="<?php echo esc_attr( (string) $post_id ); ?>">
			<?php if ( $comments_open ) : ?>
				<div class="dpc-comments-top-form-slot">
					<div id="dpc-respond" class="dpc-comment-respond">
						<div class="dpc-respond-top-bar">
							<?php if ( (bool) dpc_get_option( 'enable_social_auth', false ) && (bool) dpc_get_option( 'auth_show_in_comments', true ) ) : ?>
								<div class="dpc-respond-auth">
									<?php
									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render_auth_box().
									echo AuthManager::get_instance()->render_auth_box( 'comments' );
									?>
								</div>
							<?php endif; ?>

							<div class="dpc-reply-indicator" style="display:none;">
								<span class="dpc-reply-indicator__badge">
									<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/></svg>
									<span class="dpc-reply-indicator__name"></span>
								</span>
								<button type="button" class="dpc-cancel-reply-btn" aria-label="Cancel">&times;</button>
							</div>
						</div>

						<form id="dpc-comment-form" class="dpc-comment-form" method="post" novalidate>
							<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>" />
							<input type="hidden" name="comment_parent" id="dpc-comment-parent" value="0" />

							<?php if ( ! is_user_logged_in() ) : ?>
								<div class="dpc-form-row dpc-form-row--author">
									<input
										type="text"
										name="author"
										class="dpc-input dpc-author-input"
										placeholder="<?php echo esc_attr( $i18n['name_placeholder'] ); ?>"
										required
										maxlength="100"
										autocomplete="name"
									/>
								</div>
							<?php endif; ?>

							<div class="dpc-form-row dpc-form-row--comment">
								<textarea
									name="comment"
									id="dpc-comment-textarea"
									class="dpc-textarea"
									rows="3"
									placeholder="<?php echo esc_attr( $i18n['comment_placeholder'] ); ?>"
									required
									maxlength="5000"
								></textarea>
							</div>

							<?php if ( $honeypot_on ) : ?>
								<div class="dpc-hp-wrap" aria-hidden="true">
									<input type="text" name="dpc_hp_field" value="" tabindex="-1" autocomplete="off" />
								</div>
							<?php endif; ?>

							<div class="dpc-form-footer">
								<?php if ( ! is_user_logged_in() && $turnstile_on && '' !== $turnstile_key && '' !== $turnstile_sec ) : ?>
									<div class="dpc-turnstile-wrap">
										<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $turnstile_key ); ?>" data-theme="<?php echo esc_attr( $skin ); ?>"></div>
									</div>
								<?php endif; ?>

								<button type="submit" class="dpc-submit-comment-btn">
									<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
										<line x1="22" y1="2" x2="11" y2="13"/>
										<polygon points="22 2 15 22 11 13 2 9 22 2"/>
									</svg>
									<span><?php echo esc_html( $i18n['submit_btn'] ); ?></span>
								</button>
							</div>

							<div class="dpc-form-notice" role="status" aria-live="polite" style="display:none;"></div>
						</form>
					</div>
				</div>
			<?php endif; ?>

			<div class="dpc-comments-header">
				<div class="dpc-comments-header__count">
					<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
					</svg>
					<span class="dpc-comments-total"><?php echo esc_html( (string) $total_count ); ?></span>
				</div>

				<div class="dpc-comments-sort" role="tablist" aria-label="Sort comments">
					<button type="button" class="dpc-sort-btn" data-sort="popular" title="Popular" aria-label="Popular">
						<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>
						</svg>
					</button>
					<button type="button" class="dpc-sort-btn is-active" data-sort="newest" title="Newest" aria-label="Newest">
						<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
						</svg>
					</button>
					<button type="button" class="dpc-sort-btn" data-sort="oldest" title="Oldest" aria-label="Oldest">
						<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<circle cx="12" cy="12" r="10"/>
							<polyline points="12 6 12 12 16 14"/>
						</svg>
					</button>
				</div>
			</div>

			<ul class="dpc-comment-list">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside get_sorted_comments_html().
				echo $this->get_sorted_comments_html( $post_id, 'newest' );
				?>
			</ul>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Fetch and render comments sorted by 'popular', 'newest', or 'oldest'.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $sort    Sort mode.
	 * @return string
	 */
	public function get_sorted_comments_html( int $post_id, string $sort = 'newest' ): string {
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

		ob_start();
		wp_list_comments(
			[
				'style'      => 'ul',
				'max_depth'  => 5,
				'short_ping' => true,
				'callback'   => [ $this, 'render_comment_item' ],
			],
			$sorted_comments
		);
		return (string) ob_get_clean();
	}

	/**
	 * Custom callback for rendering a single comment card.
	 *
	 * @param \WP_Comment          $comment Comment object.
	 * @param array<string, mixed> $args    Display arguments.
	 * @param int                  $depth   Comment nesting depth.
	 */
	public function render_comment_item( \WP_Comment $comment, array $args, int $depth ): void {
		$comment_id  = (int) $comment->comment_ID;
		$post_id     = (int) $comment->comment_post_ID;
		$user_id     = (int) $comment->user_id;
		$parent_id   = (int) $comment->comment_parent;
		$author_name = get_comment_author( $comment );
		$avatar_url  = get_avatar_url( $comment, [ 'size' => 88 ] );

		$badge = '';
		if ( $user_id > 0 && user_can( $user_id, 'manage_options' ) ) {
			$badge = 'ADMIN';
		} elseif ( $user_id > 0 && $user_id === (int) get_post_field( 'post_author', $post_id ) ) {
			$badge = 'AUTHOR';
		}

		$comment_timestamp = (int) get_comment_time( 'U', true, $comment );
		$time_ago          = human_time_diff( $comment_timestamp, time() );

		$parent_author = '';
		if ( $parent_id > 0 ) {
			$parent_comment = get_comment( $parent_id );
			if ( $parent_comment instanceof \WP_Comment ) {
				$parent_author = get_comment_author( $parent_comment );
			}
		}

		$likes       = max( 0, (int) get_comment_meta( $comment_id, '_dpc_comment_likes', true ) );
		$dislikes    = max( 0, (int) get_comment_meta( $comment_id, '_dpc_comment_dislikes', true ) );
		$user_vote   = $this->get_visitor_comment_vote( $comment_id );
		$vote_access = (string) dpc_get_option( 'comments_vote_access', 'all' );
		$is_locked   = ( 'logged_in' === $vote_access && ! is_user_logged_in() );
		?>
		<li id="comment-<?php echo esc_attr( (string) $comment_id ); ?>" <?php comment_class( 'dpc-comment-item', $comment ); ?> data-comment-id="<?php echo esc_attr( (string) $comment_id ); ?>">
			<article class="dpc-comment-card">
				<div class="dpc-comment-card__avatar">
					<img src="<?php echo esc_url( $avatar_url ); ?>" alt="<?php echo esc_attr( $author_name ); ?>" width="44" height="44" loading="lazy" />
				</div>

				<div class="dpc-comment-card__body">
					<header class="dpc-comment-card__meta">
						<span class="dpc-comment-author"><?php echo esc_html( $author_name ); ?></span>

						<?php if ( '' !== $badge ) : ?>
							<span class="dpc-comment-badge dpc-comment-badge--<?php echo esc_attr( strtolower( $badge ) ); ?>"><?php echo esc_html( $badge ); ?></span>
						<?php endif; ?>

						<?php if ( '' !== $parent_author ) : ?>
							<span class="dpc-comment-parent-tag">&rarrhk; @<?php echo esc_html( $parent_author ); ?></span>
						<?php endif; ?>

						<time class="dpc-comment-time" datetime="<?php echo esc_attr( get_comment_date( 'c', $comment ) ); ?>">
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
								<span>Reply</span>
							</button>
						<?php endif; ?>
					</footer>

					<div class="dpc-reply-form-slot"></div>
				</div>
			</article>
		<?php
		// Note: WordPress Walker_Comment automatically closes the </li> tag.
	}

	/**
	 * Handle AJAX comment submission.
	 */
	public function handle_ajax_submit_comment(): void {
		check_ajax_referer( 'dpc_frontend_nonce', 'nonce' );

		$i18n    = $this->get_localized_strings();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$parent  = isset( $_POST['comment_parent'] ) ? absint( $_POST['comment_parent'] ) : 0;
		$content = isset( $_POST['comment'] ) ? trim( wp_kses_post( wp_unslash( (string) $_POST['comment'] ) ) ) : '';

		if ( $post_id <= 0 || ! comments_open( $post_id ) ) {
			wp_send_json_error( [ 'message' => $i18n['error_closed'] ], 400 );
		}

		if ( '' === $content ) {
			wp_send_json_error( [ 'message' => $i18n['error_empty'] ], 400 );
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
					wp_send_json_error( [ 'message' => $i18n['error_turnstile'] ], 403 );
				}
			}
		}

		$ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( $is_logged_in ) {
			$user = wp_get_current_user();

			$comment_data = [
				'comment_post_ID'      => $post_id,
				'comment_author'       => $user->display_name,
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
				wp_send_json_error( [ 'message' => $i18n['error_generic'] ], 500 );
			}

			$comment_obj = get_comment( $comment_id );
			$html        = '';

			if ( $comment_obj instanceof \WP_Comment ) {
				ob_start();
				$this->render_comment_item( $comment_obj, [], 1 );
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
			wp_send_json_error( [ 'message' => $i18n['error_name'] ], 400 );
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
			wp_send_json_error( [ 'message' => $i18n['error_generic'] ], 500 );
		}

		wp_notify_moderator( $comment_id );

		wp_send_json_success(
			[
				'status'  => 'moderation',
				'message' => $i18n['moderation_msg'],
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

		if ( $post_id <= 0 ) {
			wp_send_json_error( [ 'code' => 'invalid_post' ], 400 );
		}

		if ( ! in_array( $sort, [ 'popular', 'newest', 'oldest' ], true ) ) {
			$sort = 'newest';
		}

		$html = $this->get_sorted_comments_html( $post_id, $sort );

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
	 * Render floating nudge button in wp_footer when enabled.
	 */
	public function render_floating_nudge(): void {
		if ( ! is_singular() || ! comments_open() ) {
			return;
		}

		if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
			return;
		}

		if ( ! (bool) dpc_get_option( 'comments_nudge_enable', 0 ) ) {
			return;
		}

		$position = (string) dpc_get_option( 'comments_nudge_position', 'right' );
		if ( ! in_array( $position, [ 'left', 'right' ], true ) ) {
			$position = 'right';
		}

		$delay       = max( 1, min( 300, (int) dpc_get_option( 'comments_nudge_delay', 15 ) ) );
		$custom_text = trim( (string) dpc_get_option( 'comments_nudge_text', '' ) );
		$i18n        = $this->get_localized_strings();
		$nudge_text  = '' !== $custom_text ? $custom_text : $i18n['nudge_text'];
		?>
		<div class="dpc-comment-nudge dpc-nudge--<?php echo esc_attr( $position ); ?>" data-delay="<?php echo esc_attr( (string) $delay ); ?>" aria-hidden="true">
			<button type="button" class="dpc-comment-nudge__action">
				<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
				</svg>
				<span><?php echo esc_html( $nudge_text ); ?></span>
			</button>
			<button type="button" class="dpc-comment-nudge__close" aria-label="Close">&times;</button>
		</div>
		<?php
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

	/**
	 * Auto-detect site language (UK / RU / EN) for minimal UI strings.
	 *
	 * @return array<string, string>
	 */
	private function get_localized_strings(): array {
		$locale = strtolower( get_locale() );

		if ( str_starts_with( $locale, 'uk' ) ) {
			return [
				'name_placeholder'    => "Ім'я *",
				'comment_placeholder' => 'Напишіть коментар...',
				'submit_btn'          => 'Надіслати',
				'moderation_msg'      => '✓ Дякуємо! Ваш коментар надіслано на перевірку модератором.',
				'nudge_text'          => 'Залишити коментар',
				'error_empty'         => 'Введіть текст коментаря.',
				'error_name'          => "Вкажіть ваше ім'я.",
				'error_turnstile'     => 'Перевірка антиспам не пройдена.',
				'error_closed'        => 'Коментарі закриті.',
				'error_generic'       => 'Помилка збереження коментаря.',
			];
		}

		if ( str_starts_with( $locale, 'ru' ) ) {
			return [
				'name_placeholder'    => 'Имя *',
				'comment_placeholder' => 'Напишите комментарий...',
				'submit_btn'          => 'Отправить',
				'moderation_msg'      => '✓ Спасибо! Ваш комментарий отправлен на проверку модератором.',
				'nudge_text'          => 'Оставить комментарий',
				'error_empty'         => 'Введите текст комментария.',
				'error_name'          => 'Укажите ваше имя.',
				'error_turnstile'     => 'Проверка антиспама не пройдена.',
				'error_closed'        => 'Комментарии закрыты.',
				'error_generic'       => 'Ошибка сохранения комментария.',
			];
		}

		return [
			'name_placeholder'    => 'Name *',
			'comment_placeholder' => 'Write a comment...',
			'submit_btn'          => 'Send',
			'moderation_msg'      => '✓ Thank you! Your comment is awaiting moderation.',
			'nudge_text'          => 'Leave a comment',
			'error_empty'         => 'Please enter a comment.',
			'error_name'          => 'Please enter your name.',
			'error_turnstile'     => 'Anti-spam verification failed.',
			'error_closed'        => 'Comments are closed.',
			'error_generic'       => 'Failed to save comment.',
		];
	}
}
