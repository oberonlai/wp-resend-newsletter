/**
 * Live audience estimate on classic campaign edit (filter tag checkboxes).
 */
(function () {
	'use strict';

	var cfg = window.wprnAudienceEstimate;
	if (!cfg || !cfg.restUrl) {
		return;
	}

	var estimateEl = document.getElementById('wprn-audience-estimate');
	if (!estimateEl) {
		return;
	}

	var matchingEl = estimateEl.querySelector('.wprn-audience-matching');
	var confirmedEl = estimateEl.querySelector('.wprn-audience-confirmed');
	var abortController = null;
	var debounceTimer = null;

	function selectedTagIds() {
		var nodes = document.querySelectorAll('input[name="filter_tag_ids[]"]:checked');
		var ids = [];
		for (var i = 0; i < nodes.length; i++) {
			ids.push(nodes[i].value);
		}
		return ids;
	}

	function buildUrl(tagIds) {
		var url = cfg.restUrl;
		var sep = url.indexOf('?') === -1 ? '?' : '&';
		var parts = [];
		for (var i = 0; i < tagIds.length; i++) {
			parts.push('tag_ids[]=' + encodeURIComponent(tagIds[i]));
		}
		return parts.length ? url + sep + parts.join('&') : url;
	}

	function setLoading(isLoading) {
		estimateEl.style.opacity = isLoading ? '0.55' : '';
		estimateEl.setAttribute('aria-busy', isLoading ? 'true' : 'false');
	}

	function refresh() {
		if (abortController) {
			abortController.abort();
		}
		abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;

		setLoading(true);

		var opts = {
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'X-WP-Nonce': cfg.nonce || '',
			},
		};
		if (abortController) {
			opts.signal = abortController.signal;
		}

		fetch(buildUrl(selectedTagIds()), opts)
			.then(function (res) {
				if (!res.ok) {
					throw new Error('audience_fetch_failed');
				}
				return res.json();
			})
			.then(function (data) {
				if (matchingEl && typeof data.matching === 'number') {
					matchingEl.textContent = String(data.matching);
				}
				if (typeof data.confirmed === 'number') {
					if (confirmedEl) {
						confirmedEl.textContent = String(data.confirmed);
					}
					estimateEl.setAttribute('data-confirmed', String(data.confirmed));
				}
			})
			.catch(function (err) {
				if (err && err.name === 'AbortError') {
					return;
				}
				// Keep last known numbers on failure.
			})
			.finally(function () {
				setLoading(false);
			});
	}

	function onChange(event) {
		var target = event.target;
		if (!target || !target.matches || !target.matches('input[name="filter_tag_ids[]"]')) {
			return;
		}
		if (debounceTimer) {
			clearTimeout(debounceTimer);
		}
		debounceTimer = setTimeout(refresh, 150);
	}

	document.addEventListener('change', onChange);
})();
