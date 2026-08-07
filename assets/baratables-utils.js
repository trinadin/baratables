(function(window) {
	function extractText(data) {
		if (data === null || data === undefined) { return ''; }
		if (typeof data === 'number') { return data.toString(); }
		if (typeof data !== 'string') { return ''; }
		return data.replace(/<[^>]*?>/g, ' ').trim();
	}

	function escapeHtml(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function parseDate(value) {
		var text = extractText(value);
		if (!text) { return null; }
		var parsed = Date.parse(text);
		return isNaN(parsed) ? null : parsed;
	}

	function normalizeSearchText(value) {
		if (value === null || value === undefined) { return ''; }
		return String(value).replace(/<[^>]*?>/g, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
	}

	function parseOptionalNumber(value) {
		var text = extractText(value);
		if (text === '') { return null; }
		var number = parseFloat(text.replace(/[^0-9.+\-eE]/g, ''));
		return isNaN(number) ? null : number;
	}

	function parseNumber(value) {
		var number = parseOptionalNumber(value);
		return number === null ? 0 : number;
	}

	function slugIndex(map, slug) {
		return Object.prototype.hasOwnProperty.call(map || {}, slug) ? map[slug] : null;
	}

	function compactValues(values) {
		return (values || []).filter(function(value) {
			return value !== null && value !== undefined && value !== '';
		});
	}

	function resolveLabelHtml(value, fallback) {
		var label = value === null || value === undefined ? '' : String(value);
		if (label.trim) { label = label.trim(); }
		return label === '' && typeof fallback !== 'undefined' ? String(fallback) : label;
	}

	function labelToPlainText(value, fallback) {
		var html = resolveLabelHtml(value, typeof fallback !== 'undefined' ? fallback : '');
		return html === '' ? '' : html.replace(/<[^>]*?>/g, ' ').replace(/\s+/g, ' ').trim();
	}

	window.BaraTablesUtils = {
		extractText: extractText,
		escapeHtml: escapeHtml,
		parseDate: parseDate,
		normalizeSearchText: normalizeSearchText,
		parseNumber: parseNumber,
		parseOptionalNumber: parseOptionalNumber,
		slugIndex: slugIndex,
		compactValues: compactValues,
		resolveLabelHtml: resolveLabelHtml,
		labelToPlainText: labelToPlainText
	};
})(window);
