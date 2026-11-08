/**
 * Divi Pro Engagement - Frontend Scripts
 */
jQuery(document).ready(function($) {
	'use strict';

	// 1. Telegram Direct JS Auth (Desktop & Mobile)
	$(document).on('click', '.dpc-btn-telegram', function(e) {
		e.preventDefault();

		var $btn = $(this);
		var botId = $btn.attr('data-bot-id');
		var redirectTo = $btn.attr('data-redirect') || window.location.href;
		var baseCallback = (window.dpcFrontend && window.dpcFrontend.telegramCallbackUrl)
			? window.dpcFrontend.telegramCallbackUrl
			: '/wp-json/dpc/v1/auth/telegram';

		if (!botId || !window.Telegram || !window.Telegram.Login || typeof window.Telegram.Login.auth !== 'function') {
			return;
		}

		window.Telegram.Login.auth(
			{ bot_id: botId, request_access: 'write' },
			function(user) {
				if (!user) {
					return;
				}

				var params = new URLSearchParams();
				var fields = ['id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date', 'hash'];

				for (var i = 0; i < fields.length; i++) {
					var key = fields[i];
					if (typeof user[key] !== 'undefined' && user[key] !== null) {
						params.set(key, String(user[key]));
					}
				}

				if (redirectTo) {
					params.set('redirect_to', redirectTo);
				}

				var separator = baseCallback.indexOf('?') !== -1 ? '&' : '?';
				window.location.href = baseCallback + separator + params.toString();
			}
		);
	});

	// 2. User Avatar Profile Dropdown Toggle & Outside Click Close
	$(document).on('click', '.dpc-user-avatar-trigger', function(e) {
		e.preventDefault();
		e.stopPropagation();

		var $container = $(this).closest('.dpc-user-logged');
		var isOpen = $container.hasClass('is-open');

		$('.dpc-user-logged.is-open').not($container).removeClass('is-open')
			.find('.dpc-user-avatar-trigger').attr('aria-expanded', 'false');

		$container.toggleClass('is-open', !isOpen);
		$(this).attr('aria-expanded', !isOpen ? 'true' : 'false');
	});

	$(document).on('click', function(e) {
		if (!$(e.target).closest('.dpc-user-logged').length) {
			$('.dpc-user-logged.is-open').removeClass('is-open')
				.find('.dpc-user-avatar-trigger').attr('aria-expanded', 'false');
		}
	});

	$(document).on('keydown', function(e) {
		if (e.key === 'Escape') {
			$('.dpc-user-logged.is-open').removeClass('is-open')
				.find('.dpc-user-avatar-trigger').attr('aria-expanded', 'false');
		}
	});

	// 3. Post Rating: Stars Hover Preview
	$(document).on('mouseenter', '.dpc-post-rating:not(.is-locked) .dpc-star-btn', function() {
		var $btn = $(this);
		var hoverVal = parseInt($btn.attr('data-value'), 10) || 0;
		var $starsWrap = $btn.closest('.dpc-rating-stars');

		$starsWrap.addClass('is-hovering');
		$starsWrap.find('.dpc-star-btn').each(function() {
			var starVal = parseInt($(this).attr('data-value'), 10) || 0;
			$(this).toggleClass('is-hover', starVal <= hoverVal);
		});
	});

	$(document).on('mouseleave', '.dpc-rating-stars', function() {
		$(this).removeClass('is-hovering').find('.dpc-star-btn').removeClass('is-hover');
	});

	// 4. Post Rating: Submit Vote (Stars & Likes/Dislikes)
	function triggerLockedShake($widget) {
		$widget.removeClass('is-shaking');
		if ($widget[0]) {
			void $widget[0].offsetWidth;
		}
		$widget.addClass('is-shaking show-lock');
		setTimeout(function() {
			$widget.removeClass('is-shaking show-lock');
		}, 1600);
	}

	$(document).on('click', '.dpc-star-btn, .dpc-like-btn', function(e) {
		e.preventDefault();

		var $btn = $(this);
		var $widget = $btn.closest('.dpc-post-rating');

		if (!$widget.length || $widget.hasClass('is-loading')) {
			return;
		}

		if ($widget.attr('data-locked') === '1' || $widget.hasClass('is-locked')) {
			triggerLockedShake($widget);
			return;
		}

		var postId = parseInt($widget.attr('data-post-id'), 10) || 0;
		var value = $btn.attr('data-value');
		var ajaxUrl = (window.dpcFrontend && window.dpcFrontend.ajaxUrl) ? window.dpcFrontend.ajaxUrl : '/wp-admin/admin-ajax.php';
		var nonce = (window.dpcFrontend && window.dpcFrontend.nonce) ? window.dpcFrontend.nonce : '';

		if (!postId || !value) {
			return;
		}

		$widget.addClass('is-loading');

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'dpc_rate_post',
				nonce: nonce,
				post_id: postId,
				value: value
			}
		}).done(function(res) {
			if (!res || !res.success || !res.data) {
				if (res && res.data && res.data.code === 'auth_required') {
					triggerLockedShake($widget);
				}
				return;
			}

			var data = res.data;
			var $allWidgets = $('.dpc-post-rating[data-post-id="' + postId + '"]');

			$allWidgets.addClass('is-voted').attr('data-user-vote', String(data.user_vote));

			if (data.type === 'stars') {
				var voteStars = parseInt(data.user_vote, 10) || 0;
				$allWidgets.find('.dpc-rating-avg').text(data.avg);
				$allWidgets.find('.dpc-rating-count').text(data.count);
				$allWidgets.find('.dpc-star-btn').each(function() {
					var sVal = parseInt($(this).attr('data-value'), 10) || 0;
					$(this).toggleClass('is-active', sVal <= voteStars);
				});
			} else if (data.type === 'likes') {
				$allWidgets.find('.dpc-likes-count').text(data.likes);
				$allWidgets.find('.dpc-dislikes-count').text(data.dislikes);
				$allWidgets.find('.dpc-like-up').toggleClass('is-active', data.user_vote === 'like');
				$allWidgets.find('.dpc-like-down').toggleClass('is-active', data.user_vote === 'dislike');
			}
		}).fail(function(xhr) {
			if (xhr && xhr.status === 403) {
				triggerLockedShake($widget);
			}
		}).always(function() {
			$widget.removeClass('is-loading');
		});
	});
});
