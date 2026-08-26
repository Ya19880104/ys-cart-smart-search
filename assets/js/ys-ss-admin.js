/**
 * YS CART 智慧搜尋 — 後台（設定頁 + 分析頁，全 REST，零 admin-ajax）。
 */
(function () {
	'use strict';

	if (typeof window.ysSsAdmin === 'undefined') {
		return;
	}

	var CFG = window.ysSsAdmin;

	function api(path, opts) {
		opts = opts || {};
		opts.headers = Object.assign({ 'X-WP-Nonce': CFG.nonce, 'Content-Type': 'application/json' }, opts.headers || {});
		return fetch(CFG.restUrl + '/admin/smart-search' + path, opts).then(function (r) {
			if (!r.ok) { return r.json().then(function (e) { throw e; }); }
			return r.json();
		});
	}

	var CACHE_WARNING_MESSAGE = '資料已更新，但熱門建議快取可能延遲更新。';
	var KEYWORD_FAILURE_MESSAGE = '關鍵字更新失敗，請稍後再試。';
	var keywordQueue = Promise.resolve();
	var keywordOperationGeneration = 0;
	var keywordItems = [];
	var keywordRenderer = null;
	var pendingKeywordControls = new WeakSet();

	function messageWithCacheStatus(message, payload) {
		return payload && 'failed' === payload.cache_status
			? message + '\n' + CACHE_WARNING_MESSAGE
			: message;
	}

	function normalizeKeywordItems(items) {
		if (!Array.isArray(items)) { return null; }
		var clean = [];
		for (var i = 0; i < items.length; i++) {
			var item = items[i];
			if (!item || 'object' !== typeof item || Array.isArray(item)
				|| 'number' !== typeof item.id || !Number.isInteger(item.id) || item.id <= 0
				|| 'string' !== typeof item.keyword
				|| 'number' !== typeof item.sort_order || !Number.isInteger(item.sort_order)
				|| 'boolean' !== typeof item.is_active) {
				return null;
			}
			clean.push({
				id: item.id,
				keyword: item.keyword,
				sort_order: item.sort_order,
				is_active: item.is_active,
			});
		}
		return clean;
	}

	function redrawKeywordItems() {
		if (keywordRenderer) { keywordRenderer(keywordItems); }
	}

	function runKeywordMutation(path, options, control, callbacks) {
		callbacks = callbacks || {};
		if (control && pendingKeywordControls.has(control)) {
			return keywordQueue;
		}

		var operation = ++keywordOperationGeneration;
		var committed = false;
		if (control) {
			pendingKeywordControls.add(control);
			control.disabled = true;
		}
		if (callbacks.onPending) { callbacks.onPending(); }

		var request = keywordQueue.then(function () {
			return api(path, options);
		});
		// A rejected request must never poison the authority for the next queued write.
		keywordQueue = request.then(function () {}, function () {});

		return request.then(function (payload) {
			var confirmed = normalizeKeywordItems(payload && payload.items);
			if (null === confirmed) { throw new Error('invalid keyword mutation payload'); }
			keywordItems = confirmed;
			committed = true;
			// Per-control settlement belongs to this request. A later operation may suppress the
			// shared table redraw/message, but cannot make an already committed control look undone.
			if (callbacks.onSuccess) { callbacks.onSuccess(payload); }
			if (operation === keywordOperationGeneration) {
				redrawKeywordItems();
				if (callbacks.showMessage) {
					callbacks.showMessage(messageWithCacheStatus(callbacks.successMessage || '✓ 關鍵字已更新', payload));
				}
			}
			return payload;
		}).catch(function () {
			if (callbacks.onFailure) { callbacks.onFailure(); }
			if (operation === keywordOperationGeneration) {
				redrawKeywordItems();
				if (callbacks.showMessage) {
					callbacks.showMessage(callbacks.failureMessage || KEYWORD_FAILURE_MESSAGE);
				}
			}
			return null;
		}).finally(function () {
			if (!control) { return; }
			pendingKeywordControls.delete(control);
			if (!(committed && callbacks.keepDisabledOnSuccess)) {
				control.disabled = false;
			}
		});
	}

	/* ═════════ 設定頁 ═════════ */

	function initSettings() {
		var app = document.getElementById('ys-ss-settings-app');
		if (!app) { return; }

		var saveBtn = document.getElementById('ys-ss-save');
		var saveMsg = document.getElementById('ys-ss-save-msg');
		var saveMsgTimer = 0;

		function showSettingsMessage(message, clearAfter) {
			if (saveMsgTimer) {
				clearTimeout(saveMsgTimer);
				saveMsgTimer = 0;
			}
			saveMsg.textContent = message;
			if (clearAfter) {
				saveMsgTimer = setTimeout(function () {
					if (message === saveMsg.textContent) { saveMsg.textContent = ''; }
					saveMsgTimer = 0;
				}, clearAfter);
			}
		}

		function collect() {
			var out = {};
			app.querySelectorAll('[data-ss-key]').forEach(function (input) {
				var path = input.getAttribute('data-ss-key').split('.');
				var val;
				if ('checkbox' === input.type) { val = input.checked; }
				else if ('number' === input.type) { val = parseInt(input.value || '0', 10); }
				else { val = input.value; }
				var ref = out;
				for (var i = 0; i < path.length - 1; i++) {
					ref[path[i]] = ref[path[i]] || {};
					ref = ref[path[i]];
				}
				ref[path[path.length - 1]] = val;
			});
			// post types 多選
			var pts = [];
			app.querySelectorAll('[data-ss-posttype]').forEach(function (cb) {
				if (cb.checked) { pts.push(cb.getAttribute('data-ss-posttype')); }
			});
			out.posts = out.posts || {};
			out.posts.post_types = pts;
			// 商品搜尋欄位多選
			var pf = [];
			app.querySelectorAll('[data-ss-product-field]').forEach(function (cb) {
				if (cb.checked) { pf.push(cb.getAttribute('data-ss-product-field')); }
			});
			out.products = out.products || {};
			out.products.fields = pf;
			return out;
		}

		saveBtn.addEventListener('click', function () {
			saveBtn.disabled = true;
			showSettingsMessage('儲存中…');
			api('/settings', { method: 'POST', body: JSON.stringify(collect()) })
				.then(function (d) { showSettingsMessage(messageWithCacheStatus('✓ 已儲存', d), 2500); })
				.catch(function () { showSettingsMessage('儲存失敗，請稍後再試。'); })
				.finally(function () {
					saveBtn.disabled = false;
				});
		});

		/* 手動關鍵字 */
		var tbody = document.querySelector('#ys-ss-kw-table tbody');

		function renderKeywords(items) {
			if (!tbody) { return; }
			tbody.textContent = '';
			if (!items.length) {
				var tr0 = document.createElement('tr');
				var td0 = document.createElement('td');
				td0.colSpan = 4;
				td0.textContent = '尚無手動關鍵字（建議區將全部由自動統計補滿）。';
				tr0.appendChild(td0);
				tbody.appendChild(tr0);
				return;
			}
			items.forEach(function (item) {
				var tr = document.createElement('tr');

				var tdK = document.createElement('td');
				var inK = document.createElement('input');
				inK.type = 'text'; inK.value = item.keyword; inK.maxLength = 100; inK.className = 'ys-ss-kw-edit';
				inK.addEventListener('change', function () {
					runKeywordMutation(
						'/keywords/' + item.id,
						{ method: 'POST', body: JSON.stringify({ keyword: inK.value }) },
						inK,
						{ showMessage: showSettingsMessage }
					);
				});
				tdK.appendChild(inK);

				var tdS = document.createElement('td');
				var inS = document.createElement('input');
				inS.type = 'number'; inS.value = item.sort_order; inS.className = 'ys-ss-kw-sort';
				inS.addEventListener('change', function () {
					runKeywordMutation(
						'/keywords/' + item.id,
						{ method: 'POST', body: JSON.stringify({ sort_order: parseInt(inS.value || '0', 10) }) },
						inS,
						{ showMessage: showSettingsMessage }
					);
				});
				tdS.appendChild(inS);

				var tdA = document.createElement('td');
				var inA = document.createElement('input');
				inA.type = 'checkbox'; inA.checked = !!item.is_active;
				inA.addEventListener('change', function () {
					runKeywordMutation(
						'/keywords/' + item.id,
						{ method: 'POST', body: JSON.stringify({ is_active: inA.checked }) },
						inA,
						{ showMessage: showSettingsMessage }
					);
				});
				tdA.appendChild(inA);

				var tdD = document.createElement('td');
				var del = document.createElement('button');
				del.type = 'button'; del.className = 'ysca-btn ysca-btn--sm ysca-btn--ghost'; del.textContent = '刪除';
				del.addEventListener('click', function () {
					runKeywordMutation(
						'/keywords/' + item.id,
						{ method: 'DELETE' },
						del,
						{ showMessage: showSettingsMessage }
					);
				});
				tdD.appendChild(del);

				tr.appendChild(tdK); tr.appendChild(tdS); tr.appendChild(tdA); tr.appendChild(tdD);
				tbody.appendChild(tr);
			});
		}

		keywordRenderer = renderKeywords;
		try {
			keywordItems = normalizeKeywordItems(JSON.parse(app.getAttribute('data-keywords') || '[]')) || [];
		} catch (e) {
			keywordItems = [];
		}
		redrawKeywordItems();

		document.getElementById('ys-ss-kw-add').addEventListener('click', function () {
			var input = document.getElementById('ys-ss-kw-input');
			var kw = (input.value || '').trim();
			if (!kw) { return; }
			var addButton = document.getElementById('ys-ss-kw-add');
			runKeywordMutation(
				'/keywords',
				{ method: 'POST', body: JSON.stringify({ keyword: kw }) },
				addButton,
				{
					showMessage: showSettingsMessage,
					onSuccess: function () { input.value = ''; },
				}
			);
		});

		/* 清理 */
		var counts = document.getElementById('ys-ss-counts');

		function fmtCounts(c) {
			return Number(c.queries).toLocaleString() + ' 筆原始 / ' + Number(c.daily).toLocaleString() + ' 筆彙總';
		}

		var purgeExpiredBtn = document.getElementById('ys-ss-purge-expired');
		purgeExpiredBtn.addEventListener('click', function () {
			purgeExpiredBtn.disabled = true;
			showSettingsMessage('清理中…');
			api('/purge', { method: 'POST', body: JSON.stringify({ mode: 'expired' }) }).then(function (d) {
				counts.textContent = fmtCounts(d.counts);
				showSettingsMessage(messageWithCacheStatus('已清理 ' + d.deleted + ' 筆逾期資料', d), 3000);
			}).catch(function () {
				showSettingsMessage('清理失敗，請稍後再試。');
			}).finally(function () {
				purgeExpiredBtn.disabled = false;
			});
		});

		var purgeAllBtn = document.getElementById('ys-ss-purge-all');
		purgeAllBtn.addEventListener('click', function () {
			var code = window.prompt('此操作將刪除全部搜尋分析資料且無法復原。\n請輸入 DELETE 確認：');
			if ('DELETE' !== (code || '').trim()) { return; }
			purgeAllBtn.disabled = true;
			showSettingsMessage('清理中…');
			api('/purge', { method: 'POST', body: JSON.stringify({ mode: 'all', confirm: 'DELETE' }) }).then(function (d) {
				counts.textContent = fmtCounts(d.counts);
				showSettingsMessage(messageWithCacheStatus('已清除全部搜尋分析資料。', d), 3000);
			}).catch(function () {
				showSettingsMessage('清理失敗，請稍後再試。');
			}).finally(function () {
				purgeAllBtn.disabled = false;
			});
		});
	}

	/* ═════════ 分析頁 ═════════ */

	function initAnalytics() {
		var app = document.getElementById('ys-ss-analytics-app');
		if (!app) { return; }

		var fromInput = document.getElementById('ys-ss-from');
		var toInput = document.getElementById('ys-ss-to');
		var actionMsg = document.getElementById('ys-ss-action-msg');
		var actionMsgTimer = 0;

		function showActionMessage(message, clearAfter) {
			if (actionMsgTimer) {
				clearTimeout(actionMsgTimer);
				actionMsgTimer = 0;
			}
			if (!actionMsg) { return; }
			actionMsg.textContent = message;
			if (clearAfter) {
				actionMsgTimer = setTimeout(function () {
					if (message === actionMsg.textContent) { actionMsg.textContent = ''; }
					actionMsgTimer = 0;
				}, clearAfter);
			}
		}

		function fmtDate(d) {
			return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
		}

		var curFrom = '';
		var curTo = '';

		function load(from, to) {
			curFrom = from;
			curTo = to;
			fromInput.value = from;
			toInput.value = to;

			document.getElementById('ys-ss-export').href =
				CFG.restUrl + '/admin/smart-search/export?from=' + from + '&to=' + to + '&_wpnonce=' + CFG.nonce;

			api('/overview?from=' + from + '&to=' + to).then(function (d) {
				document.getElementById('ys-ss-kpi-total').textContent = Number(d.kpi.total).toLocaleString();
				document.getElementById('ys-ss-kpi-unique').textContent = Number(d.kpi.unique).toLocaleString();
				document.getElementById('ys-ss-kpi-zero').textContent = Number(d.kpi.zero).toLocaleString();
				document.getElementById('ys-ss-kpi-zerorate').textContent = d.kpi.zero_rate + '%';

				renderTrend(d.trend);
				renderTop(d.top);
				renderZero(d.zero);
			}).catch(function () { /* 顯示維持載入前狀態 */ });
		}

		function renderTrend(trend) {
			var box = document.getElementById('ys-ss-trend');
			box.textContent = '';
			if (!trend.length) {
				box.textContent = '期間內無資料。';
				return;
			}
			var max = Math.max.apply(null, trend.map(function (t) { return t.hits; })) || 1;
			trend.forEach(function (t) {
				var col = document.createElement('div');
				col.className = 'ys-ss-trend__col';
				col.title = t.date + '：' + t.hits;
				var bar = document.createElement('div');
				bar.className = 'ys-ss-trend__bar';
				bar.style.height = Math.max(2, Math.round(t.hits / max * 100)) + '%';
				var label = document.createElement('span');
				label.className = 'ys-ss-trend__label';
				label.textContent = t.date.slice(5);
				col.appendChild(bar);
				col.appendChild(label);
				box.appendChild(col);
			});
		}

		/* 單筆刪除某關鍵字（原始+彙總全部紀錄），完成後重載目前區間。 */
		function deleteTerm(term, btn) {
			if (!window.confirm('刪除關鍵字「' + term + '」的全部搜尋紀錄？此操作無法復原。')) { return; }
			if (btn) { btn.disabled = true; }
			showActionMessage('');
			api('/term?term=' + encodeURIComponent(term), { method: 'DELETE' })
				.then(function (d) {
					var total = parseInt(d && d.deleted && d.deleted.total, 10);
					if (!Number.isFinite(total) || total < 0) { total = 0; }
					showActionMessage(messageWithCacheStatus('已刪除 ' + total + ' 筆搜尋紀錄。', d), 4000);
					load(curFrom, curTo);
				})
				.catch(function (e) {
					if (btn) { btn.disabled = false; }
					showActionMessage(e && 'ys_ss_analytics_busy' === e.code
							? '搜尋分析正在更新，請稍後再試。'
							: '刪除失敗，請稍後再試。');
				});
		}

		/* 刪除鈕（垃圾桶）：排行每列共用。 */
		function delButton(term) {
			var del = document.createElement('button');
			del.type = 'button';
			del.className = 'ysca-btn ysca-btn--sm ysca-btn--ghost';
			del.textContent = '🗑';
			del.title = '刪除此關鍵字的全部紀錄';
			del.setAttribute('aria-label', '刪除 ' + term);
			del.addEventListener('click', function () { deleteTerm(term, del); });
			return del;
		}

		function renderTop(rows) {
			var body = document.getElementById('ys-ss-top-body');
			body.textContent = '';
			if (!rows.length) {
				var tr0 = document.createElement('tr');
				var td0 = document.createElement('td');
				td0.colSpan = 5;
				td0.textContent = '期間內無資料。';
				tr0.appendChild(td0);
				body.appendChild(tr0);
				return;
			}
			rows.forEach(function (r, i) {
				var tr = document.createElement('tr');
				[i + 1, r.term, r.hits, r.zero_hits].forEach(function (v) {
					var td = document.createElement('td');
					td.textContent = String(v);
					tr.appendChild(td);
				});
				var tdA = document.createElement('td');
				tdA.className = 'ys-ss-rowactions';
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'ysca-btn ysca-btn--sm ysca-btn--ghost';
				btn.textContent = '＋設為關鍵字';
				btn.addEventListener('click', function () {
					runKeywordMutation(
						'/keywords',
						{ method: 'POST', body: JSON.stringify({ keyword: r.term }) },
						btn,
						{
							showMessage: showActionMessage,
							successMessage: '✓ 已加入關鍵字',
							onSuccess: function () { btn.textContent = '✓ 已加入'; },
							keepDisabledOnSuccess: true,
						}
					);
				});
				tdA.appendChild(btn);
				tdA.appendChild(delButton(r.term));
				tr.appendChild(tdA);
				body.appendChild(tr);
			});
		}

		function renderZero(rows) {
			var body = document.getElementById('ys-ss-zero-body');
			body.textContent = '';
			if (!rows.length) {
				var tr0 = document.createElement('tr');
				var td0 = document.createElement('td');
				td0.colSpan = 5;
				td0.textContent = '期間內沒有零結果搜尋 🎉';
				tr0.appendChild(td0);
				body.appendChild(tr0);
				return;
			}
			rows.forEach(function (r, i) {
				var tr = document.createElement('tr');
				[i + 1, r.term, r.zero_hits, r.hits].forEach(function (v) {
					var td = document.createElement('td');
					td.textContent = String(v);
					tr.appendChild(td);
				});
				var tdA = document.createElement('td');
				tdA.className = 'ys-ss-rowactions';
				tdA.appendChild(delButton(r.term));
				tr.appendChild(tdA);
				body.appendChild(tr);
			});
		}

		/* 快速區間 */
		document.querySelectorAll('[data-ss-range]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				document.querySelectorAll('[data-ss-range]').forEach(function (b) { b.classList.remove('is-active'); });
				btn.classList.add('is-active');
				var days = parseInt(btn.getAttribute('data-ss-range'), 10);
				var to = new Date();
				var from = new Date();
				from.setDate(to.getDate() - days);
				load(fmtDate(from), fmtDate(to));
			});
		});

		document.getElementById('ys-ss-apply').addEventListener('click', function () {
			if (fromInput.value && toInput.value) {
				document.querySelectorAll('[data-ss-range]').forEach(function (b) { b.classList.remove('is-active'); });
				load(fromInput.value, toInput.value);
			}
		});

		/* 預設 30 天 */
		var to = new Date();
		var from = new Date();
		from.setDate(to.getDate() - 29);
		load(fmtDate(from), fmtDate(to));
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', function () { initSettings(); initAnalytics(); });
	} else {
		initSettings();
		initAnalytics();
	}
})();
