<?php
declare(strict_types=1);

namespace DiviProEngagement\Modules\PostRating;

use DiviProEngagement\Core\Init;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RatingEngine {
	private const HASH_BUFFER_LIMIT = 200;
	private const USER_VOTES_LIMIT  = 500;

	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'the_content', [ $this, 'auto_insert_rating' ], 20 );
		add_action( 'wp_head', [ $this, 'output_schema_json_ld' ], 20 );
		add_action( 'wp_ajax_dpc_rate_post', [ $this, 'handle_ajax_rate_post' ] );
		add_action( 'wp_ajax_nopriv_dpc_rate_post', [ $this, 'handle_ajax_rate_post' ] );
	}

	/**
	 * Automatically insert rating widget before or after post content.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function auto_insert_rating( string $content ): string {
		if ( is_admin() || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() || Init::is_visual_builder_active() ) {
			return $content;
		}

		$position = (string) dpc_get_option( 'rating_position', 'after' );
		if ( 'shortcode' === $position ) {
			return $content;
		}

		$widget = $this->render_rating_widget( (int) get_the_ID() );
		if ( 'before' === $position ) {
			return $widget . $content;
		}

		return $content . $widget;
	}

	/**
	 * Render the multilingual rating widget (Stars or Likes/Dislikes).
	 * Does not bake guest $_COOKIE votes into server HTML to prevent page-cache pollution (FlyingPress).
	 *
	 * @param int|null $post_id Optional post ID.
	 * @return string
	 */
	public function render_rating_widget( ?int $post_id = null ): string {
		if ( Init::is_visual_builder_active() ) {
			return '';
		}

		$post_id = $post_id ?: (int) get_the_ID();
		if ( $post_id <= 0 || post_password_required( $post_id ) ) {
			return '';
		}

		$rating_type   = (string) dpc_get_option( 'rating_type', 'stars' );
		$rating_access = (string) dpc_get_option( 'rating_access', 'all' );
		$is_locked     = ( 'logged_in' === $rating_access && ! is_user_logged_in() );
		$user_vote     = $this->get_logged_in_user_vote( $post_id );

		if ( ! in_array( $rating_type, [ 'stars', 'likes' ], true ) ) {
			$rating_type = 'stars';
		}

		ob_start();
		?>
		<div
			class="dpc-post-rating dpc-post-rating--<?php echo esc_attr( $rating_type ); ?><?php echo null !== $user_vote ? ' is-voted' : ''; ?><?php echo $is_locked ? ' is-locked' : ''; ?>"
			data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
			data-type="<?php echo esc_attr( $rating_type ); ?>"
			data-locked="<?php echo $is_locked ? '1' : '0'; ?>"
			data-user-vote="<?php echo esc_attr( (string) ( $user_vote ?? '' ) ); ?>"
		>
			<?php if ( 'likes' === $rating_type ) : ?>
				<?php
				$likes    = max( 0, (int) get_post_meta( $post_id, '_dpc_likes_count', true ) );
				$dislikes = max( 0, (int) get_post_meta( $post_id, '_dpc_dislikes_count', true ) );
				?>
				<div class="dpc-rating-likes">
					<button
						type="button"
						class="dpc-like-btn dpc-like-up<?php echo 'like' === (string) $user_vote ? ' is-active' : ''; ?>"
						data-value="like"
						aria-label="Like"
					>
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
							<path d="M14 9V5a3 3 0 0 0-3-3l-4 9v11h11.28a2 2 0 0 0 2-1.7l1.38-9a2 2 0 0 0-2-2.3zM7 22H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h3"/>
						</svg>
						<span class="dpc-likes-count"><?php echo esc_html( (string) $likes ); ?></span>
					</button>

					<button
						type="button"
						class="dpc-like-btn dpc-like-down<?php echo 'dislike' === (string) $user_vote ? ' is-active' : ''; ?>"
						data-value="dislike"
						aria-label="Dislike"
					>
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
							<path d="M10 15v4a3 3 0 0 0 3 3l4-9V2H5.72a2 2 0 0 0-2 1.7l-1.38 9a2 2 0 0 0 2 2.3zm7-13h2.67A2.31 2.31 0 0 1 22 4v7a2.31 2.31 0 0 1-2.33 2H17"/>
						</svg>
						<span class="dpc-dislikes-count"><?php echo esc_html( (string) $dislikes ); ?></span>
					</button>
				</div>
			<?php else : ?>
				<?php
				$avg          = (float) get_post_meta( $post_id, '_dpc_rating_avg', true );
				$count        = max( 0, (int) get_post_meta( $post_id, '_dpc_rating_count', true ) );
				$active_stars = is_numeric( $user_vote ) && (int) $user_vote > 0 ? (int) $user_vote : (int) round( $avg );
				?>
				<div class="dpc-rating-stars" role="radiogroup" aria-label="Rating" data-avg-stars="<?php echo esc_attr( (string) (int) round( $avg ) ); ?>">
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<button
							type="button"
							class="dpc-star-btn<?php echo $i <= $active_stars ? ' is-active' : ''; ?>"
							data-value="<?php echo esc_attr( (string) $i ); ?>"
							aria-label="<?php echo esc_attr( (string) $i ); ?>"
						>
							<svg viewBox="0 0 24 24" width="22" height="22" xmlns="http://www.w3.org/2000/svg">
								<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</button>
					<?php endfor; ?>
				</div>

				<div class="dpc-rating-meta">
					<strong class="dpc-rating-avg"><?php echo esc_html( number_format( $avg, 1, '.', '' ) ); ?></strong>
					<span class="dpc-rating-count-wrap">
						(<svg class="dpc-rating-user-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
							<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
							<circle cx="12" cy="7" r="4"/>
						</svg><span class="dpc-rating-count"><?php echo esc_html( (string) $count ); ?></span>)
					</span>
				</div>
			<?php endif; ?>

			<span class="dpc-rating-lock-tooltip" aria-hidden="true">🔒</span>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Handle AJAX post rating request.
	 */
	public function handle_ajax_rate_post(): void {
		if ( is_user_logged_in() && ! check_ajax_referer( 'dpc_frontend_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'code' => 'invalid_nonce' ], 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$raw_val = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['value'] ) ) : '';

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! ( $post instanceof \WP_Post ) || 'publish' !== $post->post_status || post_password_required( $post_id ) ) {
			wp_send_json_error( [ 'code' => 'invalid_post' ], 400 );
		}

		$rating_access = (string) dpc_get_option( 'rating_access', 'all' );
		if ( 'logged_in' === $rating_access && ! is_user_logged_in() ) {
			wp_send_json_error( [ 'code' => 'auth_required' ], 403 );
		}

		$rating_type = (string) dpc_get_option( 'rating_type', 'stars' );
		if ( 'likes' === $rating_type ) {
			if ( ! in_array( $raw_val, [ 'like', 'dislike' ], true ) ) {
				wp_send_json_error( [ 'code' => 'invalid_value' ], 400 );
			}
			$result = $this->process_like_vote( $post_id, $raw_val );
		} else {
			$stars = absint( $raw_val );
			if ( $stars < 1 || $stars > 5 ) {
				wp_send_json_error( [ 'code' => 'invalid_value' ], 400 );
			}
			$result = $this->process_star_vote( $post_id, $stars );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Process a 1-5 star vote with anti-fraud protection.
	 *
	 * @param int $post_id Post ID.
	 * @param int $stars   Star rating (1..5).
	 * @return array<string, mixed>
	 */
	private function process_star_vote( int $post_id, int $stars ): array {
		$count = max( 0, (int) get_post_meta( $post_id, '_dpc_rating_count', true ) );
		$sum   = max( 0, (int) get_post_meta( $post_id, '_dpc_rating_sum', true ) );

		if ( is_user_logged_in() ) {
			$user_id    = get_current_user_id();
			$user_votes = get_user_meta( $user_id, '_dpc_post_votes', true );
			if ( ! is_array( $user_votes ) ) {
				$user_votes = [];
			}

			$prev_vote = isset( $user_votes[ $post_id ] ) && is_numeric( $user_votes[ $post_id ] )
				? (int) $user_votes[ $post_id ]
				: 0;

			if ( $prev_vote >= 1 && $prev_vote <= 5 && $count > 0 ) {
				$sum = max( 0, $sum - $prev_vote + $stars );
			} else {
				++$count;
				$sum += $stars;
			}

			unset( $user_votes[ $post_id ] );
			$user_votes[ $post_id ] = $stars;
			if ( count( $user_votes ) > self::USER_VOTES_LIMIT ) {
				$user_votes = array_slice( $user_votes, -self::USER_VOTES_LIMIT, null, true );
			}
			update_user_meta( $user_id, '_dpc_post_votes', $user_votes );
			$this->set_vote_cookie( $post_id, (string) $stars );
		} else {
			if ( $this->has_guest_voted( $post_id ) ) {
				$avg           = $count > 0 ? round( $sum / $count, 1 ) : 0.0;
				$existing_vote = $this->get_guest_cookie_vote( $post_id );
				$returned_vote = is_numeric( $existing_vote ) && (int) $existing_vote >= 1 && (int) $existing_vote <= 5
					? (int) $existing_vote
					: $stars;

				return [
					'type'          => 'stars',
					'avg'           => number_format( $avg, 1, '.', '' ),
					'count'         => $count,
					'user_vote'     => $returned_vote,
					'already_voted' => true,
				];
			}

			++$count;
			$sum += $stars;
			$this->record_guest_vote( $post_id, (string) $stars );
		}

		$avg = $count > 0 ? round( $sum / $count, 1 ) : 0.0;

		update_post_meta( $post_id, '_dpc_rating_sum', $sum );
		update_post_meta( $post_id, '_dpc_rating_count', $count );
		update_post_meta( $post_id, '_dpc_rating_avg', $avg );

		return [
			'type'          => 'stars',
			'avg'           => number_format( $avg, 1, '.', '' ),
			'count'         => $count,
			'user_vote'     => $stars,
			'already_voted' => false,
		];
	}

	/**
	 * Process a Like / Dislike vote with anti-fraud protection.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $vote    'like' or 'dislike'.
	 * @return array<string, mixed>
	 */
	private function process_like_vote( int $post_id, string $vote ): array {
		$likes    = max( 0, (int) get_post_meta( $post_id, '_dpc_likes_count', true ) );
		$dislikes = max( 0, (int) get_post_meta( $post_id, '_dpc_dislikes_count', true ) );

		if ( is_user_logged_in() ) {
			$user_id    = get_current_user_id();
			$user_votes = get_user_meta( $user_id, '_dpc_post_votes', true );
			if ( ! is_array( $user_votes ) ) {
				$user_votes = [];
			}

			$prev_vote = isset( $user_votes[ $post_id ] ) ? (string) $user_votes[ $post_id ] : '';

			if ( $prev_vote === $vote ) {
				return [
					'type'          => 'likes',
					'likes'         => $likes,
					'dislikes'      => $dislikes,
					'user_vote'     => $vote,
					'already_voted' => true,
				];
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

			unset( $user_votes[ $post_id ] );
			$user_votes[ $post_id ] = $vote;
			if ( count( $user_votes ) > self::USER_VOTES_LIMIT ) {
				$user_votes = array_slice( $user_votes, -self::USER_VOTES_LIMIT, null, true );
			}
			update_user_meta( $user_id, '_dpc_post_votes', $user_votes );
			$this->set_vote_cookie( $post_id, $vote );
		} else {
			if ( $this->has_guest_voted( $post_id ) ) {
				$existing_vote = $this->get_guest_cookie_vote( $post_id );
				$returned_vote = in_array( $existing_vote, [ 'like', 'dislike' ], true ) ? $existing_vote : $vote;

				return [
					'type'          => 'likes',
					'likes'         => $likes,
					'dislikes'      => $dislikes,
					'user_vote'     => $returned_vote,
					'already_voted' => true,
				];
			}

			if ( 'like' === $vote ) {
				++$likes;
			} else {
				++$dislikes;
			}

			$this->record_guest_vote( $post_id, $vote );
		}

		update_post_meta( $post_id, '_dpc_likes_count', $likes );
		update_post_meta( $post_id, '_dpc_dislikes_count', $dislikes );

		return [
			'type'          => 'likes',
			'likes'         => $likes,
			'dislikes'      => $dislikes,
			'user_vote'     => $vote,
			'already_voted' => false,
		];
	}

	/**
	 * Output Schema.org AggregateRating JSON-LD in wp_head.
	 */
	public function output_schema_json_ld(): void {
		if ( Init::is_visual_builder_active() || ! is_singular() || ! (bool) dpc_get_option( 'rating_schema_enable', 1 ) ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 || 'publish' !== get_post_status( $post_id ) || post_password_required( $post_id ) ) {
			return;
		}

		$rating_type = (string) dpc_get_option( 'rating_type', 'stars' );

		if ( 'likes' === $rating_type ) {
			$likes    = max( 0, (int) get_post_meta( $post_id, '_dpc_likes_count', true ) );
			$dislikes = max( 0, (int) get_post_meta( $post_id, '_dpc_dislikes_count', true ) );
			$count    = $likes + $dislikes;
			$avg      = $count > 0 ? round( 1 + 4 * ( $likes / $count ), 1 ) : 0.0;
		} else {
			$count = max( 0, (int) get_post_meta( $post_id, '_dpc_rating_count', true ) );
			$avg   = (float) get_post_meta( $post_id, '_dpc_rating_avg', true );
		}

		if ( $count <= 0 || $avg <= 0 ) {
			return;
		}

		$schema = [
			'@context'        => 'https://schema.org',
			'@type'           => 'CreativeWorkSeries',
			'name'            => get_the_title( $post_id ),
			'aggregateRating' => [
				'@type'       => 'AggregateRating',
				'ratingValue' => number_format( $avg, 1, '.', '' ),
				'bestRating'  => '5',
				'worstRating' => '1',
				'ratingCount' => (string) $count,
			],
		];

		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}

	/**
	 * Get the logged-in user's stored vote for a post (never reads $_COOKIE on server HTML render).
	 *
	 * @param int $post_id Post ID.
	 * @return string|int|null
	 */
	private function get_logged_in_user_vote( int $post_id ): string|int|null {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$votes = get_user_meta( get_current_user_id(), '_dpc_post_votes', true );
		if ( is_array( $votes ) && isset( $votes[ $post_id ] ) ) {
			return $votes[ $post_id ];
		}

		return null;
	}

	/**
	 * Read guest vote from cookie during AJAX requests only.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function get_guest_cookie_vote( int $post_id ): string {
		$cookie_name = 'dpc_rated_' . $post_id;
		if ( isset( $_COOKIE[ $cookie_name ] ) ) {
			return sanitize_text_field( wp_unslash( (string) $_COOKIE[ $cookie_name ] ) );
		}

		return '';
	}

	/**
	 * Check whether a guest has already voted via cookie or circular hash buffer.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function has_guest_voted( int $post_id ): bool {
		if ( '' !== $this->get_guest_cookie_vote( $post_id ) ) {
			return true;
		}

		$hashes = get_post_meta( $post_id, '_dpc_rating_hashes', true );
		if ( ! is_array( $hashes ) ) {
			return false;
		}

		return in_array( Init::get_visitor_short_hash(), $hashes, true );
	}

	/**
	 * Record guest vote in circular buffer (max 200 hashes) and set cookie.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $value   Vote value.
	 */
	private function record_guest_vote( int $post_id, string $value ): void {
		$hashes = get_post_meta( $post_id, '_dpc_rating_hashes', true );
		$hashes = Init::append_visitor_hash( $hashes, self::HASH_BUFFER_LIMIT );

		update_post_meta( $post_id, '_dpc_rating_hashes', $hashes );
		$this->set_vote_cookie( $post_id, $value );
	}

	/**
	 * Set browser cookie for client-side vote highlighting.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $value   Vote value.
	 */
	private function set_vote_cookie( int $post_id, string $value ): void {
		if ( ! headers_sent() ) {
			setcookie(
				'dpc_rated_' . $post_id,
				$value,
				time() + YEAR_IN_SECONDS,
				COOKIEPATH ?: '/',
				COOKIE_DOMAIN ?: '',
				is_ssl(),
				false
			);
		}
	}

	/**
	 * Import ratings from kk Star Ratings plugin postmeta fields (_kksr_casts, _kksr_ratings, _kksr_avg).
	 *
	 * @return int Number of posts migrated.
	 */
	public static function import_from_kk_star_ratings(): int {
		global $wpdb;

		// Query all distinct post IDs having _kksr_casts
		$post_ids = $wpdb->get_col(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_kksr_casts'"
		);

		if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
			return 0;
		}

		$imported_count = 0;

		foreach ( $post_ids as $pid ) {
			$post_id = absint( $pid );
			if ( $post_id <= 0 ) {
				continue;
			}

			$count_raw = get_post_meta( $post_id, '_kksr_casts', true );
			$count     = is_scalar( $count_raw ) ? absint( (string) $count_raw ) : 0;

			if ( $count <= 0 ) {
				continue;
			}

			$sum_raw = get_post_meta( $post_id, '_kksr_ratings', true );
			$sum     = ( is_scalar( $sum_raw ) && is_numeric( $sum_raw ) ) ? (float) $sum_raw : 0.0;

			$avg_raw = get_post_meta( $post_id, '_kksr_avg', true );
			if ( is_scalar( $avg_raw ) && '' !== $avg_raw && is_numeric( $avg_raw ) && (float) $avg_raw > 0 ) {
				$avg = round( (float) $avg_raw, 2 );
			} elseif ( $sum > 0.0 ) {
				$avg = round( $sum / $count, 2 );
			} else {
				$avg = 5.0;
			}

			// Clamp between 1.0 and 5.0
			$avg = max( 1.0, min( 5.0, $avg ) );
			$sum = (int) round( $avg * $count );

			update_post_meta( $post_id, '_dpc_rating_count', $count );
			update_post_meta( $post_id, '_dpc_rating_sum', $sum );
			update_post_meta( $post_id, '_dpc_rating_avg', $avg );

			// Convert to Likes and Dislikes if currently empty
			$existing_likes    = get_post_meta( $post_id, '_dpc_likes_count', true );
			$existing_dislikes = get_post_meta( $post_id, '_dpc_dislikes_count', true );

			if ( '' === $existing_likes && '' === $existing_dislikes ) {
				$like_ratio     = max( 0.0, min( 1.0, ( $avg - 1.0 ) / 4.0 ) );
				$likes_count    = (int) round( $count * $like_ratio );
				$dislikes_count = max( 0, $count - $likes_count );

				update_post_meta( $post_id, '_dpc_likes_count', $likes_count );
				update_post_meta( $post_id, '_dpc_dislikes_count', $dislikes_count );
			}

			++$imported_count;
		}

		return $imported_count;
	}
}
