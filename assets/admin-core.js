(function(window, $) {
	function formAuth($control) {
		var $form = $control && $control.length ? $control.closest('form') : $();
		if (!$form.length) { $form = $('#post'); }
		return {
			nonce: $form.find('input[name="_baratables_nonce"]').val() || '',
			postId: $('#post_ID').val() || $form.find('input[name="post_ID"]').val() || 0
		};
	}

	var activeRequests = {};
	function startRequest(key, data) {
		var previous = activeRequests[key];
		var state = {xhr: null};
		activeRequests[key] = state;
		if (previous && previous.xhr && previous.xhr.readyState !== 4) {
			previous.xhr.abort();
		}
		state.xhr = $.post(window.ajaxurl, data);
		return {
			xhr: state.xhr,
			isCurrent: function() { return activeRequests[key] === state; }
		};
	}

	function requestFragment(options) {
		var data = options.data;
		function unavailable() {
			if (options.unavailable) { options.unavailable(); }
			else if (options.fallback) { options.fallback(); }
			if (options.always) { options.always(true); }
		}
		if (options.auth !== false) {
			var auth = formAuth(options.control);
			if (!auth.nonce || !window.ajaxurl) {
				unavailable();
				return null;
			}
			data = $.extend({}, data, {
				post_id: auth.postId,
				_baratables_nonce: auth.nonce
			});
		} else if (!window.ajaxurl) {
			unavailable();
			return null;
		}

		var request = startRequest(options.key, data);
		request.xhr.done(function(response) {
			if (!request.isCurrent()) { return; }
			var valid = options.validate ? options.validate(response) : !!(response && response.success);
			if (valid) {
				if (options.success) { options.success(response.data, response); }
			} else if (options.fallback) {
				options.fallback();
			}
		}).fail(function() {
			if (request.isCurrent() && options.fallback) { options.fallback(); }
		}).always(function() {
			if (options.always) { options.always(request.isCurrent()); }
		});
		return request;
	}

	function isActivationEvent(event) {
		return event.type === 'click' || (event.type === 'keydown' && (event.key === 'Enter' || event.key === ' '));
	}

	function isDefaultChecked($checkbox) {
		if (!$checkbox || !$checkbox.length) { return false; }
		var raw = $checkbox.data('default');
		if (raw === undefined) { raw = $checkbox.attr('data-default'); }
		if (typeof raw === 'string') {
			raw = raw.toLowerCase();
			return raw === '1' || raw === 'true';
		}
		return !!raw;
	}

	function applyDefaultIfUntouched($checkbox) {
		if (!$checkbox || !$checkbox.length || $checkbox.data('touched')) { return; }
		if (isDefaultChecked($checkbox)) { $checkbox.prop('checked', true); }
	}

	function redirectWithQuery(values, removedKeys) {
		var url = new URL(window.location.href);
		(removedKeys || []).forEach(function(key) { url.searchParams.delete(key); });
		Object.keys(values || {}).forEach(function(key) { url.searchParams.set(key, values[key]); });
		window.location.href = url.toString();
	}

	window.BaraTablesAdminCore = {
		formAuth: formAuth,
		requestFragment: requestFragment,
		isActivationEvent: isActivationEvent,
		applyDefaultIfUntouched: applyDefaultIfUntouched,
		redirectWithQuery: redirectWithQuery
	};
})(window, jQuery);
