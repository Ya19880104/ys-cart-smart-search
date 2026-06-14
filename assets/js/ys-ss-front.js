/**
 * YS CART 智慧搜尋 — 前台（零依賴 vanilla）。
 *
 * - focus（空字）→ 熱門搜尋 chips（混合式，伺服器快取）＋最近搜尋（localStorage）
 * - 輸入（debounce 250ms、IME composition 守門）→ 分組結果面板
 * - 行為紀錄：送出 / 點擊結果 / 停頓 1.2s 且 ≥2 字（sessionStorage 去重，絕不逐鍵）
 */
(function () {
	'use strict';

	if (typeof window.ysSsFront === 'undefined') {
		return;
	}

	var CFG = window.ysSsFront;
	var RECENT_KEY = 'ysss_recent';
	var LOGGED_KEY = 'ysss_logged';
	var suggestCache = null;

	/* ───────── utils ───────── */

	function debounce(fn, wait) {
		var t = null;
		return function () {
			var args = arguments, ctx = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(ctx, args); }, wait);
		};
	}

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text) { n.textContent = text; }
		return n;
	}

	/** 安全高亮：純 DOM 組裝（先文字、後 <mark>），無 innerHTML 注入面。 */
	function highlightInto(parent, text, q) {
		if (!q) { parent.textContent = text; return; }
		var lower = text.toLowerCase();
		var ql = q.toLowerCase();
		var i = lower.indexOf(ql);
		if (i < 0) { parent.textContent = text; return; }
		parent.appendChild(document.createTextNode(text.slice(0, i)));
		parent.appendChild(el('mark', 'ys-ss-mark', text.slice(i, i + q.length)));
		parent.appendChild(document.createTextNode(text.slice(i + q.length)));
	}

	function recentList() {
		try {
			var raw = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
			return Array.isArray(raw) ? raw.slice(0, 5) : [];
		} catch (e) { return []; }
	}

	function recentPush(term) {
		if (!CFG.recentEnabled || !term) { return; }
		try {
			var list = recentList().filter(function (t) { return t !== term; });
			list.unshift(term);
			localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 5)));
		} catch (e) { /* noop */ }
	}

	/** 行為紀錄（fire-and-forget；同詞 10 分鐘去重）。 */
	function logQuery(term, total, source) {
		var norm = (term || '').trim().toLowerCase();
		if (norm.length < 1) { return; }
		try {
			var map = JSON.parse(sessionStorage.getItem(LOGGED_KEY) || '{}');
			var now = Date.now();
			if (map[norm] && now - map[norm] < 600000) { return; }
			map[norm] = now;
			sessionStorage.setItem(LOGGED_KEY, JSON.stringify(map));
		} catch (e) { /* 仍嘗試送出 */ }

		var payload = JSON.stringify({ q: term, total: total, source: source });
		var url = CFG.restUrl + '/log';
		if (navigator.sendBeacon) {
			navigator.sendBeacon(url, new Blob([payload], { type: 'application/json' }));
		} else {
			fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload, keepalive: true }).catch(function () {});
		}
	}

	/* ───────── render ───────── */

	function renderSuggest(panel, form) {
		panel.textContent = '';
		var any = false;

		var chips = function (title, terms, cls) {
			if (!terms.length) { return; }
			any = true;
			var sec = el('div', 'ys-ss-suggest');
			sec.appendChild(el('div', 'ys-ss-suggest__title', title));
			var wrap = el('div', 'ys-ss-suggest__chips');
			terms.forEach(function (t) {
				var b = el('button', 'ys-ss-chip' + (cls ? ' ' + cls : ''), t.term || t);
				b.type = 'button';
				b.addEventListener('click', function () {
					var input = form.querySelector('.ys-ss-input');
					input.value = (t.term || t);
					input.focus();
					doSearch(form, input.value);
				});
				wrap.appendChild(b);
			});
			sec.appendChild(wrap);
			panel.appendChild(sec);
		};

		var done = function (data) {
			var items = (data && data.items) || [];
			chips(CFG.i18n.popular, items);
			if (CFG.recentEnabled) {
				chips(CFG.i18n.recent, recentList(), 'ys-ss-chip--recent');
			}
			panel.hidden = !any && panel.childElementCount === 0;
			if (!panel.hidden) { panel.removeAttribute('hidden'); }
		};

		if (suggestCache) { done(suggestCache); return; }
		fetch(CFG.restUrl + '/suggest')
			.then(function (r) { return r.ok ? r.json() : { items: [] }; })
			.then(function (data) { suggestCache = data; done(data); })
			.catch(function () { done({ items: [] }); });
	}

	function renderResults(panel, form, q, data) {
		panel.textContent = '';
		var groups = (data && data.groups) || [];

		if (!groups.length) {
			var empty = el('div', 'ys-ss-empty');
			empty.appendChild(el('div', 'ys-ss-empty__msg', CFG.i18n.noResults));
			panel.appendChild(empty);
			// 零結果也給熱門詞當出路
			if (suggestCache && suggestCache.items && suggestCache.items.length) {
				var wrap = el('div', 'ys-ss-suggest__chips');
				suggestCache.items.forEach(function (t) {
					var b = el('button', 'ys-ss-chip', t.term);
					b.type = 'button';
					b.addEventListener('click', function () {
						var input = form.querySelector('.ys-ss-input');
						input.value = t.term;
						doSearch(form, t.term);
					});
					wrap.appendChild(b);
				});
				empty.appendChild(wrap);
			}
			panel.hidden = false;
			return;
		}

		groups.forEach(function (g) {
			var sec = el('div', 'ys-ss-group ys-ss-group--' + g.type);
			sec.appendChild(el('div', 'ys-ss-group__title', g.label));
			var list = el('div', 'ys-ss-group__items');
			list.setAttribute('role', 'listbox');

			(g.items || []).forEach(function (item) {
				var a = el('a', 'ys-ss-item');
				a.href = item.url;
				a.setAttribute('role', 'option');
				a.addEventListener('click', function () { logQuery(q, data.total, sourceOf(form)); });

				if (item.image) {
					var img = el('img', 'ys-ss-item__img');
					img.src = item.image;
					img.alt = '';
					img.loading = 'lazy';
					a.appendChild(img);
				}
				var body = el('div', 'ys-ss-item__body');
				var title = el('div', 'ys-ss-item__title');
				highlightInto(title, item.title || '', q);
				body.appendChild(title);

				var meta = el('div', 'ys-ss-item__meta');
				if (item.price) {
					meta.appendChild(el('span', 'ys-ss-item__price', item.price));
					if (item.price_original) {
						meta.appendChild(el('del', 'ys-ss-item__price-org', item.price_original));
					}
				}
				if (item.sku) { meta.appendChild(el('span', 'ys-ss-item__sku', item.sku)); }
				if (typeof item.count === 'number' && item.count > 0) {
					meta.appendChild(el('span', 'ys-ss-item__sku', '(' + item.count + ')'));
				}
				if (item.excerpt) { meta.appendChild(el('span', 'ys-ss-item__excerpt', item.excerpt)); }
				if (meta.childElementCount) { body.appendChild(meta); }

				a.appendChild(body);
				list.appendChild(a);
			});

			sec.appendChild(list);
			panel.appendChild(sec);
		});

		var all = el('a', 'ys-ss-viewall', CFG.i18n.viewAll);
		all.href = data.view_all || CFG.shopUrl;
		all.addEventListener('click', function () { logQuery(q, data.total, sourceOf(form)); });
		panel.appendChild(all);

		panel.hidden = false;
	}

	/* ───────── behavior ───────── */

	function sourceOf(form) {
		return form.getAttribute('data-ys-ss-source') === 'popup' ? 'popup' : 'bar';
	}

	var settleTimer = null;

	function doSearch(form, q) {
		var panel = form.querySelector('.ys-ss-panel');
		q = (q || '').trim();

		if (!q) {
			renderSuggest(panel, form);
			return;
		}

		panel.hidden = false;
		if (!panel.childElementCount) {
			panel.appendChild(el('div', 'ys-ss-loading', CFG.i18n.searching));
		}

		fetch(CFG.restUrl + '/query?q=' + encodeURIComponent(q))
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) {
				if (!data) { return; }
				// 過期回應防護：只渲染目前輸入值的結果
				var current = (form.querySelector('.ys-ss-input').value || '').trim();
				if (current !== q) { return; }
				renderResults(panel, form, q, data);

				// 停頓紀錄：1.2 秒沒有新輸入且 ≥2 字
				clearTimeout(settleTimer);
				if (q.length >= 2) {
					settleTimer = setTimeout(function () {
						logQuery(q, data.total, sourceOf(form));
					}, 1200);
				}
			})
			.catch(function () { /* 靜默 */ });
	}

	function bindForm(form) {
		var input = form.querySelector('.ys-ss-input');
		var panel = form.querySelector('.ys-ss-panel');
		if (!input || !panel) { return; }

		var composing = false;
		var run = debounce(function () {
			if (!composing) { doSearch(form, input.value); }
		}, 250);

		input.addEventListener('compositionstart', function () { composing = true; });
		input.addEventListener('compositionend', function () { composing = false; run(); });
		input.addEventListener('input', run);
		input.addEventListener('focus', function () {
			if (!input.value.trim()) { renderSuggest(panel, form); } else { doSearch(form, input.value); }
		});

		form.addEventListener('submit', function () {
			var q = input.value.trim();
			if (!q) { return; }
			recentPush(q);
			// page 模式（獨立結果頁）：交由結果頁 server 端記錄，避免與此處雙記。
			if (CFG.resultsMode !== 'page') {
				logQuery(q, panel.querySelectorAll('.ys-ss-item').length, sourceOf(form));
			}
			// 交給原生 GET 導向結果頁／商店頁
		});

		// 鍵盤導航
		input.addEventListener('keydown', function (e) {
			if ('Escape' === e.key) { panel.hidden = true; return; }
			if ('ArrowDown' !== e.key && 'ArrowUp' !== e.key) { return; }
			var items = panel.querySelectorAll('.ys-ss-item, .ys-ss-chip, .ys-ss-viewall');
			if (!items.length) { return; }
			e.preventDefault();
			var idx = Array.prototype.indexOf.call(items, document.activeElement);
			idx = 'ArrowDown' === e.key ? Math.min(idx + 1, items.length - 1) : Math.max(idx - 1, 0);
			items[idx].focus();
		});

		// 點外面收合
		document.addEventListener('click', function (e) {
			if (!form.contains(e.target)) { panel.hidden = true; }
		});
	}

	/* ───────── popup ───────── */

	function bindPopup() {
		var popup = document.getElementById('ys-ss-popup');
		if (!popup) { return; }

		document.addEventListener('click', function (e) {
			var trigger = e.target.closest('[data-ys-ss-open]');
			if (trigger) {
				e.preventDefault();
				popup.hidden = false;
				document.documentElement.classList.add('ys-ss-noscroll');
				var input = popup.querySelector('.ys-ss-input');
				if (input) { setTimeout(function () { input.focus(); }, 30); }
				return;
			}
			if (e.target.closest('[data-ys-ss-close]')) {
				closePopup();
			}
		});

		document.addEventListener('keydown', function (e) {
			if ('Escape' === e.key && !popup.hidden) { closePopup(); }
		});

		function closePopup() {
			popup.hidden = true;
			document.documentElement.classList.remove('ys-ss-noscroll');
		}
	}

	function init() {
		document.querySelectorAll('form[data-ys-ss]').forEach(bindForm);
		bindPopup();
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
