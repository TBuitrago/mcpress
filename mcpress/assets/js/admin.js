/* MCPress admin: copy buttons, client tabs, confirmations, auto-submit selects. No dependencies. */
(function () {
	'use strict';
	var i18n = window.mcpressAdmin || {};

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.setAttribute('readonly', '');
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.select();
			try {
				document.execCommand('copy');
				resolve();
			} catch (e) {
				reject(e);
			}
			document.body.removeChild(ta);
		});
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-mcp-copy]');
		if (btn) {
			var scope = btn.parentElement;
			var src = scope.querySelector('[data-mcp-copy-source]');
			if (!src) {
				return;
			}
			copyText(src.textContent.trim()).then(function () {
				var old = btn.textContent;
				btn.textContent = i18n.copied || 'Copied';
				btn.classList.add('is-done');
				setTimeout(function () {
					btn.textContent = old;
					btn.classList.remove('is-done');
				}, 1600);
			});
			return;
		}

		var tab = e.target.closest('[data-mcp-tab]');
		if (tab) {
			var root = tab.closest('[data-mcp-tabs]');
			root.querySelectorAll('[data-mcp-tab]').forEach(function (t) {
				var on = t === tab;
				t.classList.toggle('is-active', on);
				t.setAttribute('aria-selected', on ? 'true' : 'false');
			});
			root.querySelectorAll('[data-mcp-panel]').forEach(function (p) {
				p.hidden = p.getAttribute('data-mcp-panel') !== tab.getAttribute('data-mcp-tab');
			});
			try {
				localStorage.setItem('mcpressClientTab', tab.getAttribute('data-mcp-tab'));
			} catch (err) {}
			return;
		}

		var confirmBtn = e.target.closest('[data-mcp-confirm]');
		if (confirmBtn) {
			var kind = confirmBtn.getAttribute('data-mcp-confirm');
			var msg = { revoke: i18n.confirmRevoke, all: i18n.confirmAll, clear: i18n.confirmClear }[kind];
			if (msg && !window.confirm(msg)) {
				e.preventDefault();
			}
		}
	});

	document.addEventListener('change', function (e) {
		var el = e.target;
		if (el.matches('[data-mcp-confirm-check]') && el.checked && !window.confirm(i18n.confirmFull)) {
			var fallback = el.form.querySelector('input[name="' + el.name + '"][value="approval"]');
			if (fallback) {
				fallback.checked = true;
			}
			return;
		}
		if (el.matches('select[data-mcp-mode-select]') && el.value === 'full' && !window.confirm(i18n.confirmFull)) {
			el.value = el.getAttribute('data-mcp-previous') || 'approval';
			return;
		}
		if (el.matches('select[data-mcp-autosubmit]')) {
			el.form.submit();
		}
	});

	document.querySelectorAll('select[data-mcp-mode-select]').forEach(function (s) {
		s.setAttribute('data-mcp-previous', s.value);
	});

	// Remember the last client tab.
	try {
		var saved = localStorage.getItem('mcpressClientTab');
		if (saved) {
			var t = document.querySelector('[data-mcp-tab="' + saved + '"]');
			if (t) {
				t.click();
			}
		}
	} catch (err) {}

	// New key: scroll into view.
	var fresh = document.getElementById('mcp-new-key');
	if (fresh) {
		fresh.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}
})();
