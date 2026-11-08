<?php
declare(strict_types=1);

use DiviProEngagement\Modules\Auth\AuthManager;
use DiviProEngagement\Modules\Comments\CommentsEngine;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
	return;
}

$dpc_post_id = (int) get_the_ID();
if ( $dpc_post_id <= 0 || post_password_required( $dpc_post_id ) ) {
	return;
}

$dpc_comments_open = comments_open( $dpc_post_id );
$dpc_total_count   = (int) get_comments_number( $dpc_post_id );

if ( ! $dpc_comments_open && 0 === $dpc_total_count ) {
	return;
}

$dpc_engine        = CommentsEngine::get_instance();
$dpc_strings       = CommentsEngine::get_strings();
$dpc_skin             = (string) dpc_get_option( 'comments_skin', 'light' );
$dpc_form_position    = (string) dpc_get_option( 'comments_form_position', 'bottom' );
$dpc_custom_font_size = trim( (string) dpc_get_option( 'comments_custom_font_size', '' ) );
$dpc_turnstile_on     = (bool) dpc_get_option( 'antispam_turnstile_enable', 0 );
$dpc_turnstile_key = (string) dpc_get_option( 'turnstile_site_key', '' );
$dpc_turnstile_sec = (string) dpc_get_option( 'turnstile_secret_key', '' );
$dpc_honeypot_on   = (bool) dpc_get_option( 'antispam_honeypot', 1 );

if ( ! in_array( $dpc_skin, [ 'light', 'dark' ], true ) ) {
	$dpc_skin = 'light';
}
if ( ! in_array( $dpc_form_position, [ 'bottom', 'top' ], true ) ) {
	$dpc_form_position = 'bottom';
}

ob_start();
if ( $dpc_comments_open ) :
	?>
	<div class="dpc-comments-form-slot">
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
				</div>
			</div>

			<form id="dpc-comment-form" class="dpc-comment-form" method="post" novalidate>
				<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $dpc_post_id ); ?>" />
				<input type="hidden" name="comment_parent" id="dpc-comment-parent" value="0" />

				<?php if ( ! is_user_logged_in() ) : ?>
					<div class="dpc-form-row dpc-form-row--author">
						<input
							type="text"
							name="author"
							class="dpc-input dpc-author-input"
							placeholder="<?php echo esc_attr( $dpc_strings['name'] ); ?>"
							data-i18n-placeholder="name"
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
						placeholder="<?php echo esc_attr( $dpc_strings['placeholder'] ); ?>"
						data-i18n-placeholder="placeholder"
						required
						maxlength="5000"
					></textarea>
				</div>

				<?php if ( $dpc_honeypot_on ) : ?>
					<div class="dpc-hp-wrap" aria-hidden="true">
						<input type="text" name="dpc_hp_field" value="" tabindex="-1" autocomplete="off" />
					</div>
				<?php endif; ?>

				<div class="dpc-form-footer">
					<?php if ( ! is_user_logged_in() && $dpc_turnstile_on && '' !== $dpc_turnstile_key && '' !== $dpc_turnstile_sec ) : ?>
						<div class="dpc-turnstile-wrap">
							<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $dpc_turnstile_key ); ?>" data-theme="<?php echo esc_attr( $dpc_skin ); ?>"></div>
						</div>
					<?php endif; ?>

					<button type="button" class="dpc-cancel-reply-btn" style="display:none;">
						<span data-i18n="cancel_reply"><?php echo esc_html( $dpc_strings['cancel_reply'] ); ?></span>
					</button>

					<button type="submit" class="dpc-submit-comment-btn">
						<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<line x1="22" y1="2" x2="11" y2="13"/>
							<polygon points="22 2 15 22 11 13 2 9 22 2"/>
						</svg>
						<span data-i18n="submit"><?php echo esc_html( $dpc_strings['submit'] ); ?></span>
					</button>
				</div>

				<div class="dpc-form-notice" role="status" aria-live="polite" style="display:none;"></div>
			</form>
		</div>
	</div>
	<?php
endif;
$dpc_form_html = (string) ob_get_clean();
?>

<section id="dpc-comments" class="dpc-comments-area dpc-comments-skin-<?php echo esc_attr( $dpc_skin ); ?> dpc-form-pos-<?php echo esc_attr( $dpc_form_position ); ?>" data-post-id="<?php echo esc_attr( (string) $dpc_post_id ); ?>"<?php echo '' !== $dpc_custom_font_size ? ' style="--dpc-custom-font-size: ' . esc_attr( $dpc_custom_font_size ) . ';"' : ''; ?>>
	<div id="comments" aria-hidden="true"></div>
	<?php
	if ( 'top' === $dpc_form_position ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		echo $dpc_form_html;
	}
	?>

	<div class="dpc-comments-header">
		<div class="dpc-comments-header__count">
			<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
			</svg>
			<span class="dpc-comments-total"><?php echo esc_html( (string) $dpc_total_count ); ?></span>
		</div>

		<div class="dpc-comments-sort" role="tablist">
			<button
				type="button"
				class="dpc-sort-btn"
				data-sort="popular"
				data-i18n-title="sort_popular"
				title="<?php echo esc_attr( $dpc_strings['sort_popular'] ); ?>"
				aria-label="<?php echo esc_attr( $dpc_strings['sort_popular'] ); ?>"
			>
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>
				</svg>
			</button>
			<button
				type="button"
				class="dpc-sort-btn is-active"
				data-sort="newest"
				data-i18n-title="sort_newest"
				title="<?php echo esc_attr( $dpc_strings['sort_newest'] ); ?>"
				aria-label="<?php echo esc_attr( $dpc_strings['sort_newest'] ); ?>"
			>
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
				</svg>
			</button>
			<button
				type="button"
				class="dpc-sort-btn"
				data-sort="oldest"
				data-i18n-title="sort_oldest"
				title="<?php echo esc_attr( $dpc_strings['sort_oldest'] ); ?>"
				aria-label="<?php echo esc_attr( $dpc_strings['sort_oldest'] ); ?>"
			>
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
		echo $dpc_engine->get_sorted_comments_html( $dpc_post_id, 'newest' );
		?>
	</ul>

	<?php
	if ( 'bottom' === $dpc_form_position ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		echo $dpc_form_html;
	}
	?>

	<?php if ( $dpc_comments_open && (bool) dpc_get_option( 'comments_nudge_enable', 0 ) ) : ?>
		<?php
		$dpc_nudge_pos = (string) dpc_get_option( 'comments_nudge_position', 'right' );
		if ( ! in_array( $dpc_nudge_pos, [ 'left', 'right' ], true ) ) {
			$dpc_nudge_pos = 'right';
		}
		$dpc_nudge_delay  = max( 1, min( 300, (int) dpc_get_option( 'comments_nudge_delay', 15 ) ) );
		$dpc_custom_nudge = trim( (string) dpc_get_option( 'comments_nudge_text', '' ) );
		$dpc_nudge_label  = '' !== $dpc_custom_nudge ? $dpc_custom_nudge : $dpc_strings['nudge'];
		?>
		<div
			class="dpc-comment-nudge dpc-nudge--<?php echo esc_attr( $dpc_nudge_pos ); ?>"
			data-delay="<?php echo esc_attr( (string) $dpc_nudge_delay ); ?>"
			data-custom-text="<?php echo '' !== $dpc_custom_nudge ? '1' : '0'; ?>"
			aria-hidden="true"
		>
			<button type="button" class="dpc-comment-nudge__action">
				<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
				</svg>
				<span <?php echo '' === $dpc_custom_nudge ? 'data-i18n="nudge"' : ''; ?>><?php echo esc_html( $dpc_nudge_label ); ?></span>
			</button>
			<button type="button" class="dpc-comment-nudge__close" aria-label="Close">&times;</button>
		</div>
	<?php endif; ?>
</section>
