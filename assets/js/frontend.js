/**
 * Divi Pro Engagement - Frontend Scripts
 */
jQuery(document).ready(function($) {
	'use strict';

	var ajaxUrl = (window.dpcFrontend && window.dpcFrontend.ajaxUrl) ? window.dpcFrontend.ajaxUrl : '/wp-admin/admin-ajax.php';
	var nonce = (window.dpcFrontend && window.dpcFrontend.nonce) ? window.dpcFrontend.nonce : '';

	// =========================================================================
	// 1. Telegram Direct JS Auth (Desktop & Mobile)
	// =========================================================================
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

	// =========================================================================
	// 2. User Avatar Profile Dropdown (250ms Hover Delay + Click + Outside Close)
	// =========================================================================
	$(document).on('mouseenter', '.dpc-user-logged, .dpc-user-dropdown', function() {
		if (window.innerWidth < 768) {
			return;
		}
		var $container = $(this).closest('.dpc-user-logged');
		var timer = $container.data('dpcCloseTimer');
		if (timer) {
			clearTimeout(timer);
			$container.removeData('dpcCloseTimer');
		}
		$container.addClass('is-open');
		$container.find('.dpc-user-avatar-trigger').attr('aria-expanded', 'true');
	});

	$(document).on('mouseleave', '.dpc-user-logged', function() {
		if (window.innerWidth < 768) {
			return;
		}
		var $container = $(this);
		var existingTimer = $container.data('dpcCloseTimer');
		if (existingTimer) {
			clearTimeout(existingTimer);
		}
		var timer = setTimeout(function() {
			$container.removeClass('is-open');
			$container.find('.dpc-user-avatar-trigger').attr('aria-expanded', 'false');
			$container.removeData('dpcCloseTimer');
		}, 250);
		$container.data('dpcCloseTimer', timer);
	});

	$(document).on('click', '.dpc-user-avatar-trigger', function(e) {
		e.preventDefault();
		e.stopPropagation();

		var $container = $(this).closest('.dpc-user-logged');
		var timer = $container.data('dpcCloseTimer');
		if (timer) {
			clearTimeout(timer);
			$container.removeData('dpcCloseTimer');
		}

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

	// =========================================================================
	// 3. Post Rating: Stars Hover & AJAX Voting
	// =========================================================================
	function triggerLockedShake($el) {
		$el.removeClass('is-shaking');
		if ($el[0]) {
			void $el[0].offsetWidth;
		}
		$el.addClass('is-shaking show-lock');
		setTimeout(function() {
			$el.removeClass('is-shaking show-lock');
		}, 1600);
	}

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

	// =========================================================================
	// 4. Comments Module: Reply, Cancel Reply, AJAX Submit, Sort, Vote
	// =========================================================================
	function resetCommentFormToTop() {
		var $respond = $('#dpc-respond');
		var $topSlot = $('.dpc-comments-top-form-slot');
		if ($respond.length && $topSlot.length) {
			$('#dpc-comment-parent').val('0');
			$respond.find('.dpc-reply-indicator').hide();
			$respond.find('.dpc-reply-indicator__name').text('');
			$topSlot.append($respond);
		}
	}

	$(document).on('click', '.dpc-comment-reply-btn', function(e) {
		e.preventDefault();

		var $btn = $(this);
		var commentId = $btn.attr('data-comment-id') || '0';
		var authorName = $btn.attr('data-author') || '';
		var $commentItem = $btn.closest('.dpc-comment-item');
		var $replySlot = $commentItem.find('> .dpc-comment-card > .dpc-comment-card__body > .dpc-reply-form-slot');
		var $respond = $('#dpc-respond');

		if (!$respond.length || !$replySlot.length) {
			return;
		}

		$('#dpc-comment-parent').val(commentId);
		$respond.find('.dpc-reply-indicator__name').text('@' + authorName);
		$respond.find('.dpc-reply-indicator').css('display', 'inline-flex');
		$respond.find('.dpc-form-notice').hide();

		$replySlot.append($respond);
		$('#dpc-comment-textarea').trigger('focus');
	});

	$(document).on('click', '.dpc-cancel-reply-btn', function(e) {
		e.preventDefault();
		resetCommentFormToTop();
	});

	// Submit Comment via AJAX
	$(document).on('submit', '#dpc-comment-form', function(e) {
		e.preventDefault();

		var $form = $(this);
		if ($form.hasClass('is-submitting')) {
			return;
		}

		var $notice = $form.find('.dpc-form-notice');
		var $submitBtn = $form.find('.dpc-submit-comment-btn');
		$notice.hide().removeClass('is-success is-error').text('');

		var payload = $form.serializeArray();
		payload.push({ name: 'action', value: 'dpc_submit_comment' });
		payload.push({ name: 'nonce', value: nonce });

		$form.addClass('is-submitting');
		$submitBtn.prop('disabled', true);

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: $.param(payload)
		}).done(function(res) {
			if (!res || !res.success || !res.data) {
				var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Error';
				$notice.addClass('is-error').text(errMsg).fadeIn(150);
				return;
			}

			var data = res.data;
			$form.find('#dpc-comment-textarea').val('');

			if (window.turnstile && typeof window.turnstile.reset === 'function') {
				try {
					window.turnstile.reset();
				} catch (err) {}
			}

			if (data.status === 'approved' && data.html) {
				var parentId = parseInt(data.parent_id, 10) || 0;
				var $newComment = $(data.html).addClass('dpc-comment-just-added');

				if (parentId > 0) {
					var $parentLi = $('#comment-' + parentId);
					if ($parentLi.length) {
						var $childrenUl = $parentLi.children('ul.children');
						if (!$childrenUl.length) {
							$childrenUl = $('<ul class="children"></ul>');
							$parentLi.append($childrenUl);
						}
						$childrenUl.append($newComment);
					} else {
						$('.dpc-comment-list').prepend($newComment);
					}
					resetCommentFormToTop();
				} else {
					$('.dpc-comment-list').prepend($newComment);
				}

				if (typeof data.total_count !== 'undefined') {
					$('.dpc-comments-total').text(data.total_count);
				}
			} else if (data.status === 'moderation') {
				$notice.addClass('is-success').text(data.message).fadeIn(180);
				setTimeout(function() {
					resetCommentFormToTop();
				}, 2200);
			}
		}).fail(function(xhr) {
			var msg = 'Error';
			if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				msg = xhr.responseJSON.data.message;
			}
			$notice.addClass('is-error').text(msg).fadeIn(150);
		}).always(function() {
			$form.removeClass('is-submitting');
			$submitBtn.prop('disabled', false);
		});
	});

	// Sort Comments via AJAX
	$(document).on('click', '.dpc-sort-btn', function(e) {
		e.preventDefault();

		var $btn = $(this);
		var $section = $btn.closest('#dpc-comments');
		if ($btn.hasClass('is-active') || $section.hasClass('is-loading')) {
			return;
		}

		var sort = $btn.attr('data-sort') || 'newest';
		var postId = parseInt($section.attr('data-post-id'), 10) || 0;
		if (!postId) {
			return;
		}

		resetCommentFormToTop();

		$section.find('.dpc-sort-btn').removeClass('is-active');
		$btn.addClass('is-active');
		$section.addClass('is-loading');

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'dpc_load_comments',
				nonce: nonce,
				post_id: postId,
				sort: sort
			}
		}).done(function(res) {
			if (res && res.success && res.data) {
				$section.find('.dpc-comment-list').html(res.data.html);
				if (typeof res.data.total_count !== 'undefined') {
					$section.find('.dpc-comments-total').text(res.data.total_count);
				}
			}
		}).always(function() {
			$section.removeClass('is-loading');
		});
	});

	// Vote on Comment (Like / Dislike)
	$(document).on('click', '.dpc-c-vote-btn', function(e) {
		e.preventDefault();

		var $btn = $(this);
		var $wrap = $btn.closest('.dpc-comment-votes');
		if (!$wrap.length || $wrap.hasClass('is-loading')) {
			return;
		}

		if ($wrap.attr('data-locked') === '1' || $wrap.hasClass('is-locked')) {
			triggerLockedShake($wrap);
			return;
		}

		var commentId = parseInt($wrap.attr('data-comment-id'), 10) || 0;
		var vote = $btn.attr('data-vote');
		if (!commentId || !vote) {
			return;
		}

		$wrap.addClass('is-loading');

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'dpc_vote_comment',
				nonce: nonce,
				comment_id: commentId,
				vote: vote
			}
		}).done(function(res) {
			if (!res || !res.success || !res.data) {
				if (res && res.data && res.data.code === 'auth_required') {
					triggerLockedShake($wrap);
				}
				return;
			}

			var data = res.data;
			$wrap.find('.dpc-c-like .count').text(data.likes);
			$wrap.find('.dpc-c-dislike .count').text(data.dislikes);
			$wrap.find('.dpc-c-like').toggleClass('is-active', data.user_vote === 'like');
			$wrap.find('.dpc-c-dislike').toggleClass('is-active', data.user_vote === 'dislike');
		}).fail(function(xhr) {
			if (xhr && xhr.status === 403) {
				triggerLockedShake($wrap);
			}
		}).always(function() {
			$wrap.removeClass('is-loading');
		});
	});

	// =========================================================================
	// 5. Floating Comment Nudge Widget
	// =========================================================================
	var $nudge = $('.dpc-comment-nudge');
	var $commentsSection = $('#dpc-comments');

	if ($nudge.length && $commentsSection.length) {
		var nudgeDismissed = false;
		var commentsReached = false;
		var delaySec = parseInt($nudge.attr('data-delay'), 10) || 15;

		function checkCommentsInView() {
			if (nudgeDismissed) {
				return;
			}
			var rect = $commentsSection[0].getBoundingClientRect();
			if (rect.top < window.innerHeight - 80) {
				commentsReached = true;
				$nudge.removeClass('is-visible').attr('aria-hidden', 'true');
			}
		}

		$(window).on('scroll.dpcNudge', checkCommentsInView);
		checkCommentsInView();

		setTimeout(function() {
			checkCommentsInView();
			if (!nudgeDismissed && !commentsReached) {
				$nudge.addClass('is-visible').attr('aria-hidden', 'false');
			}
		}, delaySec * 1000);

		$(document).on('click', '.dpc-comment-nudge__action', function(e) {
			e.preventDefault();
			nudgeDismissed = true;
			$nudge.removeClass('is-visible').attr('aria-hidden', 'true');

			var targetEl = document.getElementById('dpc-respond') || document.getElementById('dpc-comments');
			if (targetEl && typeof targetEl.scrollIntoView === 'function') {
				targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}
			setTimeout(function() {
				$('#dpc-comment-textarea').trigger('focus');
			}, 450);
		});

		$(document).on('click', '.dpc-comment-nudge__close', function(e) {
			e.preventDefault();
			e.stopPropagation();
			nudgeDismissed = true;
			$nudge.removeClass('is-visible').attr('aria-hidden', 'true');
		});
	}
});
