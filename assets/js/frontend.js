/**
 * Divi Pro Engagement - Frontend Scripts
 */
jQuery(document).ready(function($) {
	'use strict';

	var ajaxUrl = (window.dpcFrontend && window.dpcFrontend.ajaxUrl) ? window.dpcFrontend.ajaxUrl : '/wp-admin/admin-ajax.php';
	var nonce = (window.dpcFrontend && window.dpcFrontend.nonce) ? window.dpcFrontend.nonce : '';

	// =========================================================================
	// 0. Page Language Detection (<html lang>), Dictionary & Client Vote Sync
	// =========================================================================
	var defaultI18nDict = {
		uk: {
			comments_title: 'Коментарі',
			name: "Ім'я *",
			placeholder: 'Напишіть коментар...',
			submit: 'Надіслати',
			reply: 'Відповісти',
			cancel_reply: 'Скасувати',
			sort_popular: 'Популярні',
			sort_newest: 'Нові',
			sort_oldest: 'Старі',
			author_badge: 'Автор',
			nudge: 'Залишити коментар',
			moderation: '✓ Дякуємо! Коментар надіслано на модерацію.'
		},
		ru: {
			comments_title: 'Комментарии',
			name: 'Имя *',
			placeholder: 'Напишите комментарий...',
			submit: 'Отправить',
			reply: 'Ответить',
			cancel_reply: 'Отменить',
			sort_popular: 'Популярные',
			sort_newest: 'Новые',
			sort_oldest: 'Старые',
			author_badge: 'Автор',
			nudge: 'Оставить комментарий',
			moderation: '✓ Спасибо! Комментарий отправлен на модерацию.'
		},
		en: {
			comments_title: 'Comments',
			name: 'Name *',
			placeholder: 'Write a comment...',
			submit: 'Send',
			reply: 'Reply',
			cancel_reply: 'Cancel',
			sort_popular: 'Popular',
			sort_newest: 'Newest',
			sort_oldest: 'Oldest',
			author_badge: 'Author',
			nudge: 'Leave a comment',
			moderation: '✓ Thank you! Your comment is awaiting moderation.'
		}
	};

	var i18nDict = (window.dpcFrontend && window.dpcFrontend.i18n) ? window.dpcFrontend.i18n : defaultI18nDict;

	function getCookie(name) {
		if (!document.cookie) {
			return '';
		}
		var prefix = name + '=';
		var parts = document.cookie.split(';');
		for (var i = 0; i < parts.length; i++) {
			var part = parts[i].trim();
			if (part.indexOf(prefix) === 0) {
				try {
					return decodeURIComponent(part.substring(prefix.length));
				} catch (e) {
					return part.substring(prefix.length);
				}
			}
		}
		return '';
	}

	function getStoredVote(key) {
		var val = getCookie(key);
		if (val) {
			return val;
		}
		try {
			if (window.localStorage) {
				return window.localStorage.getItem(key) || '';
			}
		} catch (e) {}
		return '';
	}

	function setStoredVote(key, value) {
		try {
			if (window.localStorage) {
				window.localStorage.setItem(key, String(value));
			}
		} catch (e) {}
	}

	function syncClientVotes() {
		$('.dpc-post-rating[data-post-id]').each(function() {
			var $widget = $(this);
			var postId = parseInt($widget.attr('data-post-id'), 10) || 0;
			if (!postId) {
				return;
			}
			var savedVote = getStoredVote('dpc_rated_' + postId) || $widget.attr('data-user-vote') || '';
			if (!savedVote) {
				return;
			}
			$widget.addClass('is-voted').attr('data-user-vote', String(savedVote));
			var type = $widget.attr('data-type') || 'stars';
			if (type === 'likes') {
				$widget.find('.dpc-like-up').toggleClass('is-active', savedVote === 'like');
				$widget.find('.dpc-like-down').toggleClass('is-active', savedVote === 'dislike');
			} else {
				var voteStars = parseInt(savedVote, 10) || 0;
				if (voteStars >= 1 && voteStars <= 5) {
					$widget.find('.dpc-star-btn').each(function() {
						var sVal = parseInt($(this).attr('data-value'), 10) || 0;
						$(this).toggleClass('is-active', sVal <= voteStars);
					});
				}
			}
		});

		$('.dpc-comment-votes[data-comment-id]').each(function() {
			var $wrap = $(this);
			var commentId = parseInt($wrap.attr('data-comment-id'), 10) || 0;
			if (!commentId) {
				return;
			}
			var cVote = getStoredVote('dpc_c_voted_' + commentId);
			if (cVote === 'like' || cVote === 'dislike') {
				$wrap.find('.dpc-c-like').toggleClass('is-active', cVote === 'like');
				$wrap.find('.dpc-c-dislike').toggleClass('is-active', cVote === 'dislike');
			}
		});
	}

	var nonceRefreshPromise = null;

	function refreshNonce() {
		if (nonceRefreshPromise) {
			return nonceRefreshPromise;
		}
		nonceRefreshPromise = $.ajax({
			url: ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: { action: 'dpc_refresh_nonce' }
		}).then(function(res) {
			if (res && res.success && res.data && res.data.nonce) {
				nonce = res.data.nonce;
				if (window.dpcFrontend) {
					window.dpcFrontend.nonce = nonce;
				}
			}
			return nonce;
		}).always(function() {
			nonceRefreshPromise = null;
		});
		return nonceRefreshPromise;
	}

	function ajaxWithNonceRetry(buildDataFn) {
		var deferred = $.Deferred();

		function execute(isRetry) {
			$.ajax({
				url: ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: buildDataFn(nonce)
			}).done(function(res) {
				if (!isRetry && res && !res.success && res.data && res.data.code === 'invalid_nonce') {
					refreshNonce().done(function() {
						execute(true);
					}).fail(function(xhr) {
						deferred.reject(xhr);
					});
					return;
				}
				deferred.resolve(res);
			}).fail(function(xhr) {
				var isInvalidNonce = xhr && xhr.status === 403 && (
					!xhr.responseJSON ||
					!xhr.responseJSON.data ||
					xhr.responseJSON.data.code === 'invalid_nonce'
				);
				if (!isRetry && isInvalidNonce) {
					refreshNonce().done(function() {
						execute(true);
					}).fail(function() {
						deferred.reject(xhr);
					});
					return;
				}
				deferred.reject(xhr);
			});
		}

		execute(false);
		return deferred.promise();
	}

	function getPageLang() {
		var htmlLang = (document.documentElement && document.documentElement.lang)
			? document.documentElement.lang.toLowerCase().trim()
			: '';
		if (htmlLang.indexOf('uk') === 0 || htmlLang.indexOf('ua') === 0) {
			return 'uk';
		}
		if (htmlLang.indexOf('ru') === 0) {
			return 'ru';
		}
		return 'en';
	}

	function formatRelativeTime(timestamp, lang) {
		var nowSec = Math.floor(Date.now() / 1000);
		var diff = Math.max(1, nowSec - timestamp);

		if (diff < 60) {
			return lang === 'uk' ? 'щойно' : (lang === 'ru' ? 'только что' : 'just now');
		}
		var m = Math.floor(diff / 60);
		if (m < 60) {
			return lang === 'uk' ? (m + ' хв тому') : (lang === 'ru' ? (m + ' мин назад') : (m + 'm ago'));
		}
		var h = Math.floor(diff / 3600);
		if (h < 24) {
			return lang === 'uk' ? (h + ' год тому') : (lang === 'ru' ? (h + ' ч назад') : (h + 'h ago'));
		}
		var d = Math.floor(diff / 86400);
		if (d < 30) {
			return lang === 'uk' ? (d + ' дн тому') : (lang === 'ru' ? (d + ' дн назад') : (d + 'd ago'));
		}
		var mo = Math.floor(d / 30);
		if (mo < 12) {
			return lang === 'uk' ? (mo + ' міс тому') : (lang === 'ru' ? (mo + ' мес назад') : (mo + 'mo ago'));
		}
		var y = Math.max(1, Math.floor(d / 365));
		return lang === 'uk' ? (y + ' р тому') : (lang === 'ru' ? (y + ' г назад') : (y + 'y ago'));
	}

	function syncCommentsLanguage() {
		if (!document.documentElement || !document.documentElement.lang) {
			return;
		}
		var lang = getPageLang();
		var strings = i18nDict[lang] || i18nDict.en;

		$('[data-i18n]').each(function() {
			var key = $(this).attr('data-i18n');
			if (key && strings[key]) {
				$(this).text(strings[key]);
			}
		});

		$('[data-i18n-placeholder]').each(function() {
			var key = $(this).attr('data-i18n-placeholder');
			if (key && strings[key]) {
				$(this).attr('placeholder', strings[key]);
			}
		});

		$('[data-i18n-title]').each(function() {
			var key = $(this).attr('data-i18n-title');
			if (key && strings[key]) {
				$(this).attr('title', strings[key]).attr('aria-label', strings[key]);
			}
		});

		$('.dpc-comment-time[data-timestamp]').each(function() {
			var ts = parseInt($(this).attr('data-timestamp'), 10) || 0;
			if (ts > 0) {
				$(this).text(formatRelativeTime(ts, lang));
			}
		});
	}

	syncCommentsLanguage();
	syncClientVotes();

	// =========================================================================
	// 0.1 Scroll Position Persistence (Auth & Logout)
	// =========================================================================
	function getCleanCurrentUrl() {
		return window.location.href.split('#')[0];
	}

	function updateRedirectToParam(url, newTarget) {
		try {
			var parsed = new URL(url, window.location.origin);
			parsed.searchParams.set('redirect_to', newTarget);
			return parsed.toString();
		} catch (e) {
			return url;
		}
	}

	function getPendingDownloadUrl() {
		try {
			if (window.sessionStorage) {
				return window.sessionStorage.getItem('dpc_pending_download_url') || '';
			}
		} catch (e) {}
		return '';
	}

	function setPendingDownloadUrl(url) {
		try {
			if (window.sessionStorage) {
				if (url) {
					window.sessionStorage.setItem('dpc_pending_download_url', url);
				} else {
					window.sessionStorage.removeItem('dpc_pending_download_url');
				}
			}
		} catch (e) {}
	}

	function saveScrollRestoreState(inComments, pendingUrlOverride) {
		try {
			if (window.sessionStorage) {
				var pendingUrl = typeof pendingUrlOverride === 'string'
					? pendingUrlOverride
					: getPendingDownloadUrl();
				var state = {
					path: window.location.pathname + window.location.search,
					scrollY: Math.round(window.scrollY || window.pageYOffset || 0),
					inComments: !!inComments,
					pendingDownloadUrl: pendingUrl,
					timestamp: Date.now()
				};
				window.sessionStorage.setItem('dpc_scroll_restore', JSON.stringify(state));
			}
		} catch (e) {}
	}

	function restoreScrollPosition() {
		var rawData = null;
		try {
			if (window.sessionStorage) {
				rawData = window.sessionStorage.getItem('dpc_scroll_restore');
				window.sessionStorage.removeItem('dpc_scroll_restore');
			}
		} catch (e) {}

		if (!rawData) {
			return null;
		}

		var data = null;
		try {
			data = JSON.parse(rawData);
		} catch (e) {
			return null;
		}

		if (!data || typeof data.scrollY !== 'number' || !data.path || !data.timestamp) {
			return null;
		}

		var currentPath = window.location.pathname + window.location.search;
		if (data.path !== currentPath) {
			return null;
		}

		if (Date.now() - data.timestamp >= 300000) {
			return null;
		}

		var targetY = data.scrollY;

		window.scrollTo({ top: targetY, behavior: 'instant' });

		if (window.requestAnimationFrame) {
			window.requestAnimationFrame(function() {
				window.scrollTo({ top: targetY, behavior: 'instant' });
			});
		}

		setTimeout(function() {
			window.scrollTo({ top: targetY, behavior: 'instant' });

			if (data.inComments === true) {
				var $textarea = $('#dpc-comment-textarea');
				var userLoggedIn = $('.dpc-user-logged').length > 0 || $('.dpc-author-input').length === 0;

				if ($textarea.length && userLoggedIn && $textarea[0]) {
					try {
						$textarea[0].focus({ preventScroll: true });
					} catch (e) {
						$textarea.trigger('focus');
					}
				}
			}
		}, 50);

		return data;
	}

	var restoredScrollState = restoreScrollPosition();

	// Intercept click on any social auth, fallback login, or logout button to save scroll and refresh redirect_to
	$(document).on('click', '.dpc-social-btn, .dpc-logout-btn, .dpc-gatekeeper-login-fallback', function() {
		var $el = $(this);
		var inComments = $el.closest('#dpc-comments, #dpc-comment-form, .dpc-comments-area').length > 0;
		saveScrollRestoreState(inComments);

		var cleanUrl = getCleanCurrentUrl();

		if ($el.hasClass('dpc-btn-telegram')) {
			$el.attr('data-redirect', cleanUrl);
		} else {
			var href = $el.attr('href');
			if (href) {
				$el.attr('href', updateRedirectToParam(href, cleanUrl));
			}
		}
	});

	// =========================================================================
	// 1. Telegram Direct JS Auth (On-Demand Dynamic Script Loading)
	// =========================================================================
	var telegramScriptPromise = null;

	function ensureTelegramWidgetLoaded() {
		if (window.Telegram && window.Telegram.Login && typeof window.Telegram.Login.auth === 'function') {
			return $.Deferred().resolve().promise();
		}
		if (telegramScriptPromise) {
			return telegramScriptPromise;
		}

		var deferred = $.Deferred();
		telegramScriptPromise = deferred.promise();

		var script = document.createElement('script');
		script.src = 'https://telegram.org/js/telegram-widget.js?22';
		script.async = true;
		script.onload = function() {
			if (window.Telegram && window.Telegram.Login && typeof window.Telegram.Login.auth === 'function') {
				deferred.resolve();
			} else {
				telegramScriptPromise = null;
				deferred.reject();
			}
		};
		script.onerror = function() {
			telegramScriptPromise = null;
			deferred.reject();
		};
		document.head.appendChild(script);

		return telegramScriptPromise;
	}

	// Preload on hover/touchstart of Telegram button so click opens popup immediately without blocking initial page load
	$(document).on('mouseenter touchstart', '.dpc-btn-telegram', function() {
		ensureTelegramWidgetLoaded();
	});

	$(document).on('click', '.dpc-btn-telegram', function(e) {
		e.preventDefault();

		var $btn = $(this);
		var botId = $btn.attr('data-bot-id');
		var redirectTo = getCleanCurrentUrl();
		var baseCallback = (window.dpcFrontend && window.dpcFrontend.telegramCallbackUrl)
			? window.dpcFrontend.telegramCallbackUrl
			: '/wp-json/dpc/v1/auth/telegram';

		if (!botId) {
			return;
		}

		ensureTelegramWidgetLoaded().done(function() {
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

					params.set('redirect_to', redirectTo);

					var separator = baseCallback.indexOf('?') !== -1 ? '&' : '?';
					window.location.href = baseCallback + separator + params.toString();
				}
			);
		});
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

		ajaxWithNonceRetry(function(currentNonce) {
			return {
				action: 'dpc_rate_post',
				nonce: currentNonce,
				post_id: postId,
				value: value
			};
		}).done(function(res) {
			if (!res || !res.success || !res.data) {
				if (res && res.data && res.data.code === 'auth_required') {
					triggerLockedShake($widget);
				}
				return;
			}

			var data = res.data;
			var $allWidgets = $('.dpc-post-rating[data-post-id="' + postId + '"]');

			setStoredVote('dpc_rated_' + postId, data.user_vote);
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
	function resetCommentForm() {
		var $respond = $('#dpc-respond');
		var $defaultSlot = $('.dpc-comments-form-slot');
		if ($respond.length && $defaultSlot.length) {
			$('#dpc-comment-parent').val('0');
			$respond.find('.dpc-reply-indicator').hide();
			$respond.find('.dpc-reply-indicator__name').text('');
			$respond.find('.dpc-cancel-reply-btn').hide();
			$defaultSlot.append($respond);
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
		$respond.find('.dpc-cancel-reply-btn').css('display', 'inline-flex');
		$respond.find('.dpc-form-notice').hide();

		$replySlot.append($respond);
		$('#dpc-comment-textarea').trigger('focus');
	});

	$(document).on('click', '.dpc-cancel-reply-btn', function(e) {
		e.preventDefault();
		resetCommentForm();
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

		$form.addClass('is-submitting');
		$submitBtn.prop('disabled', true);

		ajaxWithNonceRetry(function(currentNonce) {
			var payload = $form.serializeArray();
			payload.push({ name: 'action', value: 'dpc_submit_comment' });
			payload.push({ name: 'nonce', value: currentNonce });
			payload.push({ name: 'lang', value: getPageLang() });
			return $.param(payload);
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
							$childrenUl = $('<ul class="children dpc-comment-children"></ul>');
							$parentLi.append($childrenUl);
						}
						$childrenUl.append($newComment);
					} else {
						$('.dpc-comment-list').prepend($newComment);
					}
					resetCommentForm();
				} else {
					var isBottomForm = $('#dpc-comments').hasClass('dpc-form-pos-bottom');
					if (isBottomForm && $('.dpc-sort-btn.is-active').attr('data-sort') === 'oldest') {
						$('.dpc-comment-list').append($newComment);
					} else {
						$('.dpc-comment-list').prepend($newComment);
					}
				}

				if (typeof data.total_count !== 'undefined') {
					$('.dpc-comments-total').text(data.total_count);
				}

				syncCommentsLanguage();
				syncClientVotes();
			} else if (data.status === 'moderation') {
				var lang = getPageLang();
				var modMsg = (i18nDict[lang] && i18nDict[lang].moderation) ? i18nDict[lang].moderation : data.message;
				$notice.addClass('is-success').text(modMsg).fadeIn(180);
				setTimeout(function() {
					resetCommentForm();
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

		resetCommentForm();

		$section.find('.dpc-sort-btn').removeClass('is-active');
		$btn.addClass('is-active');
		$section.addClass('is-loading');

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'dpc_load_comments',
				post_id: postId,
				sort: sort,
				lang: getPageLang()
			}
		}).done(function(res) {
			if (res && res.success && res.data) {
				$section.find('.dpc-comment-list').html(res.data.html);
				if (typeof res.data.total_count !== 'undefined') {
					$section.find('.dpc-comments-total').text(res.data.total_count);
				}
				syncCommentsLanguage();
				syncClientVotes();
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

		ajaxWithNonceRetry(function(currentNonce) {
			return {
				action: 'dpc_vote_comment',
				nonce: currentNonce,
				comment_id: commentId,
				vote: vote
			};
		}).done(function(res) {
			if (!res || !res.success || !res.data) {
				if (res && res.data && res.data.code === 'auth_required') {
					triggerLockedShake($wrap);
				}
				return;
			}

			var data = res.data;
			setStoredVote('dpc_c_voted_' + commentId, data.user_vote);
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
	// 5. Floating Comment Nudge Widget (IntersectionObserver on #dpc-comment-form)
	// =========================================================================
	var $nudge = $('.dpc-comment-nudge');
	var commentFormEl = document.getElementById('dpc-comment-form');

	if ($nudge.length && commentFormEl) {
		var nudgeDismissed = false;
		var delayReady = false;
		var isFormInViewport = false;
		var delaySec = parseInt($nudge.attr('data-delay'), 10) || 15;

		function updateNudgeVisibility() {
			if (nudgeDismissed || !delayReady) {
				$nudge.removeClass('is-visible').attr('aria-hidden', 'true');
				return;
			}
			if (isFormInViewport) {
				$nudge.removeClass('is-visible').attr('aria-hidden', 'true');
			} else {
				$nudge.addClass('is-visible').attr('aria-hidden', 'false');
			}
		}

		if ('IntersectionObserver' in window) {
			var observer = new IntersectionObserver(function(entries) {
				entries.forEach(function(entry) {
					isFormInViewport = entry.isIntersecting;
					updateNudgeVisibility();
				});
			}, {
				root: null,
				threshold: 0.05
			});
			observer.observe(commentFormEl);
		} else {
			$(window).on('scroll.dpcNudge resize.dpcNudge', function() {
				var rect = commentFormEl.getBoundingClientRect();
				isFormInViewport = (rect.top < window.innerHeight && rect.bottom > 0);
				updateNudgeVisibility();
			});
		}

		setTimeout(function() {
			delayReady = true;
			if (!('IntersectionObserver' in window)) {
				var rect = commentFormEl.getBoundingClientRect();
				isFormInViewport = (rect.top < window.innerHeight && rect.bottom > 0);
			}
			updateNudgeVisibility();
		}, delaySec * 1000);

		$(document).on('click', '.dpc-comment-nudge__action', function(e) {
			e.preventDefault();
			$nudge.removeClass('is-visible').attr('aria-hidden', 'true');

			if (typeof commentFormEl.scrollIntoView === 'function') {
				commentFormEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
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

	// =========================================================================
	// 6. Smooth Scroll for #comments and #respond Anchor Links
	// =========================================================================
	$(document).on('click', 'a[href$="#comments"], a[href$="#respond"]', function(e) {
		var href = $(this).attr('href') || '';
		var isRespond = href.slice(-8) === '#respond';
		var targetEl = isRespond
			? (document.getElementById('dpc-respond') || document.getElementById('dpc-comments') || document.getElementById('comments'))
			: (document.querySelector('.dpc-comment-list > li.dpc-comment-item') || document.getElementById('dpc-comments') || document.getElementById('comments'));

		if (targetEl && typeof targetEl.scrollIntoView === 'function') {
			e.preventDefault();
			targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
			if (isRespond) {
				setTimeout(function() {
					$('#dpc-comment-textarea').trigger('focus');
				}, 450);
			}
		}
	});

	// =========================================================================
	// 7. Download Gatekeeper (Auth-Gated Downloads & Auto-Resume After Login)
	// =========================================================================
	var defaultGkI18n = {
		uk: {
			title: '🔒 Доступ лише для авторизованих користувачів',
			description: 'Завантаження технічної документації, каталогів PDF та креслень AutoCAD доступне після швидкої авторизації. Увійдіть за кілька секунд:',
			close: 'Закрити',
			login_btn: 'Увійти на сайт',
			toast_title: '✓ Дякуємо за авторизацію! Завантаження розпочато.'
		},
		ru: {
			title: '🔒 Доступ только для авторизованных пользователей',
			description: 'Скачивание технической документации, каталогов PDF и чертежей AutoCAD доступно после быстрой авторизации:',
			close: 'Закрыть',
			login_btn: 'Войти на сайт',
			toast_title: '✓ Спасибо за авторизацию! Загрузка началась.'
		},
		en: {
			title: '🔒 Access for Authorized Users Only',
			description: 'Downloading technical documentation, PDF catalogs, and AutoCAD drawings is available after quick sign-in:',
			close: 'Close',
			login_btn: 'Sign In',
			toast_title: '✓ Thank you for signing in! Your download is starting.'
		}
	};

	var defaultMiniAuthI18n = {
		uk: {
			btn_label: 'Увійти',
			modal_title: 'Швидка авторизація',
			modal_desc: 'Оберіть зручний спосіб входу на сайт:',
			close: 'Закрити',
			login_btn: 'Увійти на сайт',
			logout: 'Вийти'
		},
		ru: {
			btn_label: 'Войти',
			modal_title: 'Быстрая авторизация',
			modal_desc: 'Выберите удобный способ входа на сайт:',
			close: 'Закрыть',
			login_btn: 'Войти на сайт',
			logout: 'Выйти'
		},
		en: {
			btn_label: 'Sign in',
			modal_title: 'Quick Sign In',
			modal_desc: 'Choose your preferred sign-in method:',
			close: 'Close',
			login_btn: 'Sign In',
			logout: 'Log out'
		}
	};

	function getGatekeeperConfig() {
		if (window.dpcConfig && window.dpcConfig.gatekeeper) {
			return window.dpcConfig.gatekeeper;
		}
		if (window.dpcFrontend && window.dpcFrontend.gatekeeper) {
			return window.dpcFrontend.gatekeeper;
		}
		return null;
	}

	function isUserLoggedIn() {
		var gk = getGatekeeperConfig();
		return document.body.classList.contains('logged-in')
			|| document.cookie.indexOf('wordpress_logged_in_') !== -1
			|| document.querySelector('.dpc-user-logged') !== null
			|| document.querySelector('.dpc-mini-user') !== null
			|| !!(gk && gk.isLoggedIn);
	}

	function getGatekeeperStrings() {
		var gk = getGatekeeperConfig();
		var dict = (gk && gk.i18n) ? gk.i18n : defaultGkI18n;
		var lang = getPageLang();
		return dict[lang] || dict.en || defaultGkI18n.en;
	}

	function getMiniAuthStrings() {
		var dict = (window.dpcFrontend && window.dpcFrontend.miniAuthI18n) ? window.dpcFrontend.miniAuthI18n : defaultMiniAuthI18n;
		var lang = getPageLang();
		return dict[lang] || dict.en || defaultMiniAuthI18n.en;
	}

	function syncMiniAuthLanguage() {
		var miniStrings = getMiniAuthStrings();
		$('[data-dpc-mini-login-label]').text(miniStrings.btn_label);
		$('[data-dpc-mini-logout-label]').text(miniStrings.logout);
		$('.dpc-mini-logout-link, .dpc-mini-user__logout').attr('title', miniStrings.logout).attr('aria-label', miniStrings.logout);
	}

	function syncGatekeeperLanguage(mode) {
		syncMiniAuthLanguage();

		var $modal = $('#dpc-gatekeeper-modal');
		if (!$modal.length) {
			return;
		}

		var strings;
		if (mode === 'auth') {
			var mini = getMiniAuthStrings();
			strings = {
				title: mini.modal_title,
				description: mini.modal_desc,
				close: mini.close,
				login_btn: mini.login_btn
			};
		} else {
			strings = getGatekeeperStrings();
		}

		$modal.find('[data-dpc-gk-i18n]').each(function() {
			var key = $(this).attr('data-dpc-gk-i18n');
			if (key && strings[key]) {
				$(this).text(strings[key]);
			}
		});

		$modal.find('[data-dpc-gk-i18n-aria]').each(function() {
			var key = $(this).attr('data-dpc-gk-i18n-aria');
			if (key && strings[key]) {
				$(this).attr('aria-label', strings[key]);
			}
		});
	}

	function openGatekeeperModal(mode) {
		var $modal = $('#dpc-gatekeeper-modal');
		if (!$modal.length) {
			return;
		}
		var modalMode = mode === 'auth' ? 'auth' : 'gatekeeper';
		syncGatekeeperLanguage(modalMode);
		$modal.attr('data-dpc-modal-mode', modalMode).addClass('is-open').attr('aria-hidden', 'false');
	}

	function closeGatekeeperModal(preservePending) {
		var $modal = $('#dpc-gatekeeper-modal');
		if ($modal.length) {
			$modal.removeClass('is-open').attr('aria-hidden', 'true');
		}
		if (!preservePending) {
			setPendingDownloadUrl('');
		}
	}

	function getUploadsBasePath(gk) {
		if (gk && gk.uploadsUrl) {
			try {
				var parsed = new URL(gk.uploadsUrl, window.location.origin);
				return parsed.pathname.replace(/\/+$/, '').toLowerCase();
			} catch (e) {}
		}
		return '/wp-content/uploads';
	}

	function isProtectedDownloadLink(anchorEl) {
		var gk = getGatekeeperConfig();
		if (!gk || !gk.enabled || !anchorEl) {
			return false;
		}

		if (anchorEl.hasAttribute('data-dpc-gk-skip') || $(anchorEl).closest('#dpc-gatekeeper-modal, .dpc-gatekeeper-toast, .dpc-social-login, .dpc-user-logged, .dpc-mini-user, .dpc-mini-login-btn').length > 0) {
			return false;
		}

		var rawHref = (anchorEl.getAttribute('href') || '').trim();
		if (!rawHref || rawHref.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(rawHref)) {
			return false;
		}

		var parsedUrl;
		try {
			parsedUrl = new URL(anchorEl.href, window.location.origin);
		} catch (e) {
			return false;
		}

		if (parsedUrl.origin !== window.location.origin) {
			return false;
		}

		var pathname = parsedUrl.pathname || '';
		try {
			pathname = decodeURIComponent(pathname);
		} catch (e) {}
		var pathLower = pathname.toLowerCase();

		var protectedPath = (gk.protectedPath || '').replace(/^\/+|\/+$/g, '').toLowerCase();
		if (protectedPath && pathLower.indexOf('/' + protectedPath + '/') !== -1 && pathLower.slice(-1) !== '/') {
			return true;
		}

		if (gk.interceptAllUploads) {
			var uploadsPath = getUploadsBasePath(gk);
			if (pathLower.indexOf(uploadsPath + '/') !== 0 || pathLower.slice(-1) === '/') {
				return false;
			}

			var lastSegment = pathLower.split('/').pop() || '';
			var dotIndex = lastSegment.lastIndexOf('.');
			if (dotIndex === -1 || dotIndex === lastSegment.length - 1) {
				return false;
			}

			var ext = lastSegment.slice(dotIndex + 1);
			var imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'ico', 'bmp'];
			if (imageExts.indexOf(ext) !== -1 && pathLower.indexOf('/dmdocuments/') === -1) {
				return false;
			}

			var allowedExts = Array.isArray(gk.extensions) ? gk.extensions : [];
			if (allowedExts.indexOf(ext) !== -1) {
				return true;
			}
		}

		return false;
	}

	function showGatekeeperToast() {
		var strings = getGatekeeperStrings();
		$('.dpc-gatekeeper-toast').remove();

		var toastText = (strings && strings.toast_title) ? strings.toast_title : '✓';
		var $toast = $('<div class="dpc-gatekeeper-toast" role="status" aria-live="polite"></div>');
		var $content = $('<div class="dpc-gatekeeper-toast__content"></div>');
		var $msg = $('<span class="dpc-gatekeeper-toast__text"></span>');

		if (toastText.indexOf('✓') === 0) {
			var $icon = $('<span class="dpc-gatekeeper-toast__icon" aria-hidden="true">✓</span>');
			$msg.append($icon).append(document.createTextNode(' ' + toastText.replace(/^✓\s*/, '')));
		} else {
			$msg.text(toastText);
		}

		var closeLabel = (strings && strings.close) ? strings.close : 'Close';
		var $close = $('<button type="button" class="dpc-gatekeeper-toast__close">&times;</button>')
			.attr('aria-label', closeLabel);

		$content.append($msg);
		$toast.append($content).append($close);
		$('body').append($toast);

		setTimeout(function() {
			$toast.addClass('is-visible');
		}, 20);

		var autoHideTimer = setTimeout(function() {
			$toast.removeClass('is-visible');
			setTimeout(function() {
				$toast.remove();
			}, 300);
		}, 4500);

		$close.on('click', function() {
			clearTimeout(autoHideTimer);
			$toast.removeClass('is-visible');
			setTimeout(function() {
				$toast.remove();
			}, 300);
		});
	}

	function processPendingDownload(restoredPendingUrl) {
		var pendingUrl = getPendingDownloadUrl() || restoredPendingUrl || '';
		if (!pendingUrl || !isUserLoggedIn()) {
			return;
		}

		setPendingDownloadUrl('');

		var parsedPending;
		try {
			parsedPending = new URL(pendingUrl, window.location.origin);
		} catch (e) {
			return;
		}

		if (parsedPending.origin !== window.location.origin) {
			return;
		}

		var targetHref = parsedPending.href;
		var targetPathSearch = parsedPending.pathname + parsedPending.search;
		var targetLink = null;

		$('a[href]').each(function() {
			if ($(this).closest('#dpc-gatekeeper-modal, .dpc-gatekeeper-toast').length) {
				return;
			}
			try {
				var linkParsed = new URL(this.href, window.location.origin);
				if (linkParsed.href === targetHref || (linkParsed.origin === window.location.origin && (linkParsed.pathname + linkParsed.search) === targetPathSearch)) {
					$(this).addClass('dpc-download-ready');
					if (!targetLink) {
						targetLink = this;
					}
				}
			} catch (e) {}
		});

		var inlineExts = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
		var pathname = parsedPending.pathname || '';
		try {
			pathname = decodeURIComponent(pathname);
		} catch (e) {}
		var lastSegment = pathname.split('/').pop() || '';
		var dotIdx = lastSegment.lastIndexOf('.');
		var fileExt = dotIdx !== -1 ? lastSegment.slice(dotIdx + 1).toLowerCase() : '';
		var isInlineViewable = inlineExts.indexOf(fileExt) !== -1;

		if (isInlineViewable) {
			if (targetLink) {
				var linkTarget = (targetLink.getAttribute('target') || '').trim().toLowerCase();
				if (linkTarget === '_blank') {
					var opened = null;
					try {
						opened = window.open(targetHref, '_blank');
					} catch (e) {}
					if (!opened) {
						try {
							targetLink.click();
						} catch (e) {}
					}
				} else {
					try {
						targetLink.click();
					} catch (e) {
						window.location.assign(targetHref);
					}
				}
			} else {
				window.location.assign(targetHref);
			}
		} else {
			window.location.assign(targetHref);
		}

		showGatekeeperToast();
	}

	function handleGatewayRedirectParams() {
		var gk = getGatekeeperConfig();
		if (!gk || !gk.enabled || typeof window.URLSearchParams !== 'function') {
			return false;
		}

		var params = new URLSearchParams(window.location.search);
		if (params.get('dpc_auth_required') !== '1') {
			return false;
		}

		var rawTarget = params.get('dpc_dl_target') || '';
		var isValidTarget = rawTarget.length > 1
			&& rawTarget.charAt(0) === '/'
			&& rawTarget.charAt(1) !== '/'
			&& rawTarget.indexOf('\\') === -1
			&& !/[\r\n]/.test(rawTarget);

		params.delete('dpc_auth_required');
		params.delete('dpc_dl_target');

		if (window.history && typeof window.history.replaceState === 'function') {
			var remainingQuery = params.toString();
			var cleanedUrl = window.location.pathname + (remainingQuery ? '?' + remainingQuery : '') + window.location.hash;
			try {
				window.history.replaceState(null, document.title, cleanedUrl);
			} catch (e) {}
		}

		var fullTargetUrl = isValidTarget ? (window.location.origin + rawTarget) : '';

		if (!isUserLoggedIn()) {
			if (fullTargetUrl) {
				setPendingDownloadUrl(fullTargetUrl);
				saveScrollRestoreState(false, fullTargetUrl);
			}
			openGatekeeperModal('gatekeeper');
			return true;
		}

		if (fullTargetUrl) {
			processPendingDownload(fullTargetUrl);
			return true;
		}

		return false;
	}

	$(document).on('click', '.dpc-mini-login-btn, [data-dpc-auth-trigger]', function(e) {
		e.preventDefault();
		openGatekeeperModal('auth');
	});

	$(document).on('click', 'a[href]', function(e) {
		if (!isProtectedDownloadLink(this)) {
			return;
		}

		if (isUserLoggedIn()) {
			return;
		}

		e.preventDefault();
		setPendingDownloadUrl(this.href);
		saveScrollRestoreState(false, this.href);
		openGatekeeperModal('gatekeeper');
	});

	$(document).on('click', '[data-dpc-gk-close]', function(e) {
		e.preventDefault();
		closeGatekeeperModal(false);
	});

	$(document).on('keydown', function(e) {
		if (e.key === 'Escape' && $('#dpc-gatekeeper-modal').hasClass('is-open')) {
			closeGatekeeperModal(false);
		}
	});

	syncGatekeeperLanguage();
	if (!handleGatewayRedirectParams()) {
		var gkRuntime = getGatekeeperConfig();
		if (gkRuntime && gkRuntime.enabled) {
			processPendingDownload(restoredScrollState && restoredScrollState.pendingDownloadUrl ? restoredScrollState.pendingDownloadUrl : '');
		}
	}
});
