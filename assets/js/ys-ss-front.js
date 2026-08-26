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
	var RECENT_KEY = 'ysss_recent_v2';
	var LOGGED_KEY = 'ysss_logged';
	var suggestCache = null;
	var suggestPromise = null;

	/* ───────── utils ───────── */

	function debounce(fn, wait) {
		var timer = null;
		return function () {
			var args = arguments;
			var ctx = this;
			clearTimeout(timer);
			timer = setTimeout(function () { fn.apply(ctx, args); }, wait);
		};
	}

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) { node.className = cls; }
		if (text) { node.textContent = text; }
		return node;
	}

	/** 安全高亮：純 DOM 組裝（先文字、後 <mark>），無 innerHTML 注入面。 */
	function highlightInto(parent, text, query) {
		if (!query) { parent.textContent = text; return; }
		var lower = text.toLowerCase();
		var queryLower = query.toLowerCase();
		var index = lower.indexOf(queryLower);
		if (index < 0) { parent.textContent = text; return; }
		parent.appendChild(document.createTextNode(text.slice(0, index)));
		parent.appendChild(el('mark', 'ys-ss-mark', text.slice(index, index + query.length)));
		parent.appendChild(document.createTextNode(text.slice(index + query.length)));
	}

	function boundedTerm(value) {
		if (typeof value !== 'string') { return ''; }
		var term = value.trim();
		if (!term || Array.from(term).length > 100) { return ''; }
		try { encodeURIComponent(term); } catch (error) { return ''; }
		return term;
	}

	function recentList() {
		try {
			var raw = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
			if (!Array.isArray(raw)) { return []; }
			var result = [];
			raw.slice(0, 20).forEach(function (value) {
				var term = boundedTerm(value);
				if (term && result.indexOf(term) < 0 && result.length < 5) { result.push(term); }
			});
			return result;
		} catch (error) { return []; }
	}

	function recentPush(term) {
		term = boundedTerm(term);
		if (!CFG.recentEnabled || !term) { return; }
		try {
			var list = recentList().filter(function (item) { return item !== term; });
			list.unshift(term);
			localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 5)));
		} catch (error) { /* noop */ }
	}

	/** 行為紀錄（fire-and-forget；只對同一 signed receipt 去重）。 */
	function logQuery(term, ingress, receipt, source) {
		var normalized = (term || '').trim().toLowerCase();
		if (normalized.length < 1 || typeof ingress !== 'string' || !ingress || typeof receipt !== 'string' || !receipt) { return false; }
		var eventKey = receipt.slice(-64);
		try {
			var map = JSON.parse(sessionStorage.getItem(LOGGED_KEY) || '{}');
			var now = Date.now();
			if (!map || 'object' !== typeof map || Array.isArray(map)) { map = {}; }
			Object.keys(map).forEach(function (key) {
				if (!Number.isFinite(map[key]) || now - map[key] >= 600000) { delete map[key]; }
			});
			if (map[eventKey]) { return true; }
		} catch (error) { /* 仍嘗試送出 */ }
		function commitLogged() {
			try {
				var committed = JSON.parse(sessionStorage.getItem(LOGGED_KEY) || '{}');
				var committedAt = Date.now();
				if (!committed || 'object' !== typeof committed || Array.isArray(committed)) { committed = {}; }
				Object.keys(committed).forEach(function (key) {
					if (!Number.isFinite(committed[key]) || committedAt - committed[key] >= 600000) { delete committed[key]; }
				});
				committed[eventKey] = committedAt;
				sessionStorage.setItem(LOGGED_KEY, JSON.stringify(committed));
			} catch (error) { /* transport 已接受，storage 失敗不影響搜尋 */ }
		}

		var payload = JSON.stringify({ q: term, ingress: ingress, receipt: receipt, source: source });
		var url = CFG.restUrl + '/log';
		if (navigator.sendBeacon) {
			try {
				if (navigator.sendBeacon(url, new Blob([payload], { type: 'application/json' }))) {
					commitLogged();
					return true;
				}
			} catch (error) { /* 改走 keepalive fetch */ }
		}
		try {
			var request = fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: payload, keepalive: true });
			request.then(function (response) {
				if (response && response.ok) { commitLogged(); }
			}).catch(function () {});
			return true;
		} catch (error) {
			return false;
		}
	}

	function setServerLogReceipt(form, receipt) {
		var field = form.querySelector('input[name="ys_ss_log_receipt"]');
		if (!field) {
			field = document.createElement('input');
			field.type = 'hidden';
			field.setAttribute('name', 'ys_ss_log_receipt');
			form.appendChild(field);
		}
		field.value = receipt;
	}

	function clearServerLogReceipt(form) {
		var field = form.querySelector('input[name="ys_ss_log_receipt"]');
		if (field && field.parentNode) { field.parentNode.removeChild(field); }
	}

	/* ───────── controller and render ───────── */

	function controller(form) {
		if (!form._ysSsController) {
			form._ysSsController = {
				generation: 0,
				mode: 'closed',
				proof: null,
				activeRequest: null,
				activeIndex: -1,
				settleTimer: null,
				suppressFocus: false
			};
		}
		return form._ysSsController;
	}

	function setBusy(form, busy) {
		var input = form.querySelector('.ys-ss-input');
		var panel = form.querySelector('.ys-ss-panel');
		var value = busy ? 'true' : 'false';
		if (input) { input.setAttribute('aria-busy', value); }
		if (panel) { panel.setAttribute('aria-busy', value); }
	}

	function beginInteraction(form, mode) {
		var state = controller(form);
		clearServerLogReceipt(form);
		state.generation += 1;
		state.mode = mode;
		clearTimeout(state.settleTimer);
		state.settleTimer = null;
		state.proof = null;
		form._ysSsLogProof = null;
		if (state.activeRequest) { state.activeRequest.abort(); }
		state.activeRequest = null;
		state.activeIndex = -1;
		renderPanelState(form, mode, null);
		return state.generation;
	}

	function isCurrent(form, token, expectedValue, expectedMode) {
		var input = form.querySelector('.ys-ss-input');
		var state = controller(form);
		return state.generation === token
			&& !!input
			&& (input.value || '').trim() === expectedValue
			&& (!expectedMode || state.mode === expectedMode);
	}

	function closeInteraction(form) {
		beginInteraction(form, 'closed');
	}

	function renderFailure(form) {
		var state = controller(form);
		clearTimeout(state.settleTimer);
		state.settleTimer = null;
		state.proof = null;
		form._ysSsLogProof = null;
		state.activeRequest = null;
		state.activeIndex = -1;
		state.mode = 'error';
		renderPanelState(form, 'error', null);
	}

	function selectableItems(panel) {
		return panel.querySelectorAll('.ys-ss-item, .ys-ss-chip, .ys-ss-viewall');
	}

	function renderPanelState(form, mode, payload) {
		var input = form.querySelector('.ys-ss-input');
		var panel = form.querySelector('.ys-ss-panel');
		var state = controller(form);
		if (!input || !panel) { return; }

		if (payload && payload.busyOnly) {
			setBusy(form, !!payload.busy);
			return;
		}

		if (payload && payload.selectionOnly) {
			var currentItems = selectableItems(panel);
			Array.prototype.forEach.call(currentItems, function (item, index) {
				var active = index === state.activeIndex;
				item.classList[active ? 'add' : 'remove']('is-active');
				item.setAttribute('aria-selected', active ? 'true' : 'false');
			});
			if (state.activeIndex >= 0 && currentItems[state.activeIndex]) {
				input.setAttribute('aria-activedescendant', currentItems[state.activeIndex].getAttribute('id'));
			} else {
				input.removeAttribute('aria-activedescendant');
			}
			return;
		}

		panel.textContent = '';
		input.removeAttribute('aria-activedescendant');
		setBusy(form, 'loading' === mode);
		var visible = false;

		function startChipSearch(term, event) {
			if (event) { event._ysSsActivationOwner = form; }
			input.value = term;
			state.suppressFocus = true;
			try { input.focus(); } finally { state.suppressFocus = false; }
			var token = beginInteraction(form, 'query');
			requestQuery(form, term, token);
		}

		function appendChips(title, terms, extraClass, parent) {
			if (!terms || !terms.length) { return false; }
			var section = parent || el('div', 'ys-ss-suggest');
			if (!parent) { section.appendChild(el('div', 'ys-ss-suggest__title', title)); }
			var wrap = el('div', 'ys-ss-suggest__chips');
			terms.forEach(function (termData) {
				var term = boundedTerm(typeof termData === 'string' ? termData : (termData && termData.term));
				if (!term) { return; }
				var chip = el('button', 'ys-ss-chip' + (extraClass ? ' ' + extraClass : ''), term);
				chip.type = 'button';
				chip.addEventListener('click', function (event) { startChipSearch(term, event); });
				wrap.appendChild(chip);
			});
			section.appendChild(wrap);
			if (!parent) { panel.appendChild(section); }
			return true;
		}

		if ('loading' === mode) {
			panel.appendChild(el('div', 'ys-ss-loading', CFG.i18n.searching));
			visible = true;
		} else if ('error' === mode) {
			panel.appendChild(el('div', 'ys-ss-error', CFG.i18n.error));
			visible = true;
		} else if ('suggest' === mode && payload) {
			var suggestionItems = (payload.data && payload.data.items) || [];
			visible = appendChips(CFG.i18n.popular, suggestionItems, '', null) || visible;
			if (CFG.recentEnabled) {
				visible = appendChips(CFG.i18n.recent, recentList(), 'ys-ss-chip--recent', null) || visible;
			}
		} else if ('empty' === mode && payload) {
			var empty = el('div', 'ys-ss-empty');
			empty.appendChild(el('div', 'ys-ss-empty__msg', CFG.i18n.noResults));
			var fallback = (payload.suggestions && payload.suggestions.items) || [];
			appendChips('', fallback, '', empty);
			panel.appendChild(empty);
			visible = true;
		} else if ('results' === mode && payload) {
			var data = payload.data || {};
			var groups = data.groups || [];
			var logTerm = data.q || '';
			var logIngress = payload.query || '';
			var receipt = data.log_receipt || '';
			groups.forEach(function (group) {
				var section = el('div', 'ys-ss-group ys-ss-group--' + group.type);
				section.appendChild(el('div', 'ys-ss-group__title', group.label));
				var list = el('div', 'ys-ss-group__items');
				(group.items || []).forEach(function (item) {
					var link = el('a', 'ys-ss-item');
					link.href = item.url;
					link.addEventListener('click', function () { logQuery(logTerm, logIngress, receipt, sourceOf(form)); });
					if (item.image) {
						var image = el('img', 'ys-ss-item__img');
						image.src = item.image;
						image.alt = '';
						image.loading = 'lazy';
						link.appendChild(image);
					}
					var body = el('div', 'ys-ss-item__body');
					var title = el('div', 'ys-ss-item__title');
					highlightInto(title, item.title || '', payload.query);
					body.appendChild(title);
					var meta = el('div', 'ys-ss-item__meta');
					if (item.price) {
						meta.appendChild(el('span', 'ys-ss-item__price', item.price));
						if (item.price_original) { meta.appendChild(el('del', 'ys-ss-item__price-org', item.price_original)); }
					}
					if (item.sku) { meta.appendChild(el('span', 'ys-ss-item__sku', item.sku)); }
					if (typeof item.count === 'number' && item.count > 0) { meta.appendChild(el('span', 'ys-ss-item__sku', '(' + item.count + ')')); }
					if (item.excerpt) { meta.appendChild(el('span', 'ys-ss-item__excerpt', item.excerpt)); }
					if (meta.childElementCount) { body.appendChild(meta); }
					link.appendChild(body);
					list.appendChild(link);
				});
				section.appendChild(list);
				panel.appendChild(section);
			});
			var viewAll = el('a', 'ys-ss-viewall', CFG.i18n.viewAll);
			viewAll.href = data.view_all || CFG.shopUrl;
			if (CFG.resultsMode !== 'page') {
				viewAll.addEventListener('click', function () { logQuery(logTerm, logIngress, receipt, sourceOf(form)); });
			}
			panel.appendChild(viewAll);
			visible = true;
		}

		var items = selectableItems(panel);
		var panelId = panel.getAttribute('id') || 'ys-ss-panel';
		Array.prototype.forEach.call(items, function (item, index) {
			item.setAttribute('id', panelId + '-option-' + index);
			item.setAttribute('role', 'option');
			item.setAttribute('aria-selected', 'false');
		});
		panel.hidden = !visible;
		if (visible) { panel.removeAttribute('hidden'); } else { panel.setAttribute('hidden', 'hidden'); }
		input.setAttribute('aria-expanded', visible ? 'true' : 'false');
	}

	/* ───────── requests ───────── */

	function loadSuggestions() {
		if (!suggestPromise) {
			var pending = fetch(CFG.restUrl + '/suggest')
				.then(function (response) {
					if (!response.ok) { throw new Error('suggest request failed'); }
					return response.json();
				})
				.then(function (data) {
					suggestCache = data && Array.isArray(data.items) ? data : { items: [] };
					return suggestCache;
				});
			suggestPromise = pending.catch(function (error) {
				suggestCache = null;
				suggestPromise = null;
				throw error;
			});
		}
		return suggestPromise;
	}

	function requestSuggestions(form, token) {
		if (suggestCache) {
			if (isCurrent(form, token, '', 'suggest')) {
				renderPanelState(form, 'suggest', { data: suggestCache });
			}
			return;
		}
		if (isCurrent(form, token, '', 'suggest')) {
			renderPanelState(form, 'suggest', { busyOnly: true, busy: true });
		}
		loadSuggestions().then(function (data) {
			if (!isCurrent(form, token, '', 'suggest')) { return; }
			renderPanelState(form, 'suggest', { data: data });
		}).catch(function () {
			if (!isCurrent(form, token, '', 'suggest')) { return; }
			renderFailure(form);
		});
	}

	function requestQuery(form, query, token) {
		var state = controller(form);
		if (!isCurrent(form, token, query, 'query')) { return; }
		var requestController = typeof AbortController !== 'undefined' ? new AbortController() : null;
		state.activeRequest = requestController;
		state.mode = 'loading';
		renderPanelState(form, 'loading', null);
		var options = requestController ? { signal: requestController.signal } : {};

		fetch(CFG.restUrl + '/query?q=' + encodeURIComponent(query), options)
			.then(function (response) {
				if (!response.ok) { throw new Error('query request failed'); }
				return response.json();
			})
			.then(function (data) {
				if (!isCurrent(form, token, query, 'loading')) { return; }
				state = controller(form);
				if (state.activeRequest === requestController) { state.activeRequest = null; }
				if (data
					&& typeof data.q === 'string'
					&& data.q
				) {
					var productsTotal = data.products_total;
					if (typeof productsTotal !== 'number'
						|| !Number.isFinite(productsTotal)
						|| !Number.isInteger(productsTotal)
						|| productsTotal < 0) {
						productsTotal = 0;
					}
					state.proof = {
						input: query,
						query: data.q,
						receipt: typeof data.log_receipt === 'string' ? data.log_receipt : '',
						total: Math.max(0, Number(data.total) || 0),
						productsTotal: productsTotal,
						recentTerm: boundedTerm(data.recent_term)
					};
					form._ysSsLogProof = state.proof;
				}

				if (!state.proof) {
					state.mode = 'empty';
					renderPanelState(form, 'empty', { data: null, suggestions: null });
					return;
				}

				var groups = (data && data.groups) || [];
				if (!groups.length) {
					state.mode = 'empty';
					renderPanelState(form, 'empty', { data: data, suggestions: null });
					if (!suggestCache && isCurrent(form, token, query, 'empty')) {
						renderPanelState(form, 'empty', { busyOnly: true, busy: true });
					}
					loadSuggestions().then(function (suggestions) {
						if (!isCurrent(form, token, query, 'empty')) { return; }
						renderPanelState(form, 'empty', { data: data, suggestions: suggestions });
					}).catch(function () {
						if (!isCurrent(form, token, query, 'empty')) { return; }
						renderPanelState(form, 'empty', { busyOnly: true, busy: false });
					});
				} else {
					state.mode = 'results';
					renderPanelState(form, 'results', { query: query, data: data });
				}

				clearTimeout(state.settleTimer);
				if (CFG.resultsMode !== 'page' && state.proof) {
					var proof = state.proof;
					var settleMode = state.mode;
					state.settleTimer = setTimeout(function () {
						state.settleTimer = null;
						if (isCurrent(form, token, query, settleMode) && state.proof === proof) {
							logQuery(proof.query, proof.input, proof.receipt, sourceOf(form));
						}
					}, 1200);
				}
			})
			.catch(function (error) {
				if (error && error.name === 'AbortError' && !isCurrent(form, token, query, 'loading')) { return; }
				if (!isCurrent(form, token, query, 'loading')) { return; }
				renderFailure(form);
			});
	}

	/* ───────── form behavior ───────── */

	function sourceOf(form) {
		return form.getAttribute('data-ys-ss-source') === 'popup' ? 'popup' : 'bar';
	}

	function bindForm(form) {
		var input = form.querySelector('.ys-ss-input');
		var panel = form.querySelector('.ys-ss-panel');
		if (!input || !panel) { return; }
		controller(form);

		var composing = false;
		var run = debounce(function (query, token) {
			if (composing || !isCurrent(form, token, query, query ? 'query' : 'suggest')) { return; }
			if (query) { requestQuery(form, query, token); } else { requestSuggestions(form, token); }
		}, 250);

		function startFromInput() {
			var query = (input.value || '').trim();
			var token = beginInteraction(form, query ? 'query' : 'suggest');
			if (!composing) { run(query, token); }
		}

		input.addEventListener('compositionstart', function () {
			composing = true;
			beginInteraction(form, (input.value || '').trim() ? 'query' : 'suggest');
		});
		input.addEventListener('compositionend', function () {
			composing = false;
			startFromInput();
		});
		input.addEventListener('input', startFromInput);
		input.addEventListener('focus', function () {
			if (controller(form).suppressFocus) { return; }
			var query = (input.value || '').trim();
			var token = beginInteraction(form, query ? 'query' : 'suggest');
			if (query) { requestQuery(form, query, token); } else { requestSuggestions(form, token); }
		});

		form.addEventListener('submit', function () {
			var query = input.value.trim();
			if (!query) { return; }
			var proof = controller(form).proof;
			if (!proof || proof.input !== query || !proof.receipt) { return; }
			if (proof.productsTotal > 0) { recentPush(proof.recentTerm); }
			if (CFG.resultsMode !== 'page') {
				input.value = proof.input;
				logQuery(proof.query, proof.input, proof.receipt, sourceOf(form));
				setServerLogReceipt(form, proof.receipt);
			}
		});

		input.addEventListener('keydown', function (event) {
			var state = controller(form);
			if ('Escape' === event.key) {
				closeInteraction(form);
				return;
			}
			var items = selectableItems(panel);
			if ('Enter' === event.key && state.activeIndex >= 0 && items[state.activeIndex]) {
				event.preventDefault();
				items[state.activeIndex].click();
				return;
			}
			if ('ArrowDown' !== event.key && 'ArrowUp' !== event.key) { return; }
			if (!items.length) { return; }
			event.preventDefault();
			state.activeIndex = 'ArrowDown' === event.key
				? Math.min(state.activeIndex + 1, items.length - 1)
				: Math.max(state.activeIndex - 1, 0);
			renderPanelState(form, state.mode, { selectionOnly: true });
		});

		document.addEventListener('click', function (event) {
			if (event._ysSsActivationOwner === form) { return; }
			if (!form.contains(event.target)) {
				closeInteraction(form);
			}
		});
	}

	/* ───────── popup ───────── */

	function bindPopup() {
		var popup = document.getElementById('ys-ss-popup');
		if (!popup) { return; }
		var popupOpener = null;

		function isVisible(node) {
			var current = node;
			while (current && current !== document) {
				if (current.hidden) { return false; }
				current = current.parentNode;
			}
			return true;
		}

		function focusables() {
			var nodes = popup.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])');
			return Array.prototype.filter.call(nodes, isVisible);
		}

		function closePopup() {
			Array.prototype.forEach.call(popup.querySelectorAll('form[data-ys-ss]'), closeInteraction);
			popup.hidden = true;
			document.documentElement.classList.remove('ys-ss-noscroll');
			var restore = popupOpener;
			popupOpener = null;
			if (restore && typeof restore.focus === 'function') { restore.focus(); }
		}

		document.addEventListener('click', function (event) {
			var trigger = event.target.closest('[data-ys-ss-open]');
			if (trigger) {
				event.preventDefault();
				popupOpener = trigger;
				popup.hidden = false;
				document.documentElement.classList.add('ys-ss-noscroll');
				var input = popup.querySelector('.ys-ss-input');
				if (input) { setTimeout(function () { input.focus(); }, 30); }
				return;
			}
			if (event.target.closest('[data-ys-ss-close]')) { closePopup(); }
		});

		document.addEventListener('keydown', function (event) {
			if (popup.hidden) { return; }
			if ('Escape' === event.key) { closePopup(); return; }
			if ('Tab' !== event.key) { return; }
			var items = focusables();
			if (!items.length) { return; }
			var first = items[0];
			var last = items[items.length - 1];
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		});
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
