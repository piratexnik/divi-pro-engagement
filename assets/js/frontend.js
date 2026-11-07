/**
 * Divi Pro Engagement - Frontend Scripts
 */
jQuery(document).ready(function($) {
	'use strict';

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
});
