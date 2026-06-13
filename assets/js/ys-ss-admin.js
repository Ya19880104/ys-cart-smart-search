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

	function esc(s) { return String(s == null ? '' : s); }

	/* ═════════ 設定頁 ═════════ */

	function initSettings() {
		var app = document.getElementById('ys-ss-settings-app');
		if (!app) { return; }

		var saveBtn = document.getElementById('ys-ss-save');
		var saveMsg = document.getElementById('ys-ss-save-msg');

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
			saveMsg.textContent = '儲存中…';
			api('/settings', { method: 'POST', body: JSON.stringify(collect()) })
				.then(function () { saveMsg.textContent = '✓ 已儲存'; })
				.catch(function (e) { saveMsg.textContent = '儲存失敗：' + esc(e && e.message); })
				.finally(function () {
					saveBtn.disabled = false;
					setTimeout(function () { saveMsg.textContent = ''; }, 2500);
				});
		});

		/* 手動關鍵字 */
		var tbody = document.querySelector('#ys-ss-kw-table tbody');

		function renderKeywords(items) {
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
					api('/keywords/' + item.id, { method: 'POST', body: JSON.stringify({ keyword: inK.value }) }).then(function (d) { renderKeywords(d.items); });
				});
				tdK.appendChild(inK);

				var tdS = document.createElement('td');
				var inS = document.createElement('input');
				inS.type = 'number'; inS.value = item.sort_order; inS.className = 'ys-ss-kw-sort';
				inS.addEventListener('change', function () {
					api('/keywords/' + item.id, { method: 'POST', body: JSON.stringify({ sort_order: parseInt(inS.value || '0', 10) }) }).then(function (d) { renderKeywords(d.items); });
				});
				tdS.appendChild(inS);

				var tdA = document.createElement('td');
				var inA = document.createElement('input');
				inA.type = 'checkbox'; inA.checked = !!item.is_active;
				inA.addEventListener('change', function () {
					api('/keywords/' + item.id, { method: 'POST', body: JSON.stringify({ is_active: inA.checked }) }).then(function (d) { renderKeywords(d.items); });
				});
				tdA.appendChild(inA);

				var tdD = document.createElement('td');
				var del = document.createElement('button');
				del.type = 'button'; del.className = 'ysca-btn ysca-btn--sm ysca-btn--ghost'; del.textContent = '刪除';
				del.addEventListener('click', function () {
					api('/keywords/' + item.id, { method: 'DELETE' }).then(function (d) { renderKeywords(d.items); });
				});
				tdD.appendChild(del);

				tr.appendChild(tdK); tr.appendChild(tdS); tr.appendChild(tdA); tr.appendChild(tdD);
				tbody.appendChild(tr);
			});
		}

		try { renderKeywords(JSON.parse(app.getAttribute('data-keywords') || '[]')); } catch (e) { renderKeywords([]); }

		document.getElementById('ys-ss-kw-add').addEventListener('click', function () {
			var input = document.getElementById('ys-ss-kw-input');
			var kw = (input.value || '').trim();
			if (!kw) { return; }
			api('/keywords', { method: 'POST', body: JSON.stringify({ keyword: kw }) }).then(function (d) {
				input.value = '';
				renderKeywords(d.items);
			});
		});

		/* 清理 */
		var counts = document.getElementById('ys-ss-counts');

		function fmtCounts(c) {
			return Number(c.queries).toLocaleString() + ' 筆原始 / ' + Number(c.daily).toLocaleString() + ' 筆彙總';
		}

		document.getElementById('ys-ss-purge-expired').addEventListener('click', function () {
			api('/purge', { method: 'POST', body: JSON.stringify({ mode: 'expired' }) }).then(function (d) {
				counts.textContent = fmtCounts(d.counts);
				saveMsg.textContent = '已清理 ' + d.deleted + ' 筆逾期資料';
				setTimeout(function () { saveMsg.textContent = ''; }, 3000);
			});
		});

		document.getElementById('ys-ss-purge-all').addEventListener('click', function () {
			var code = window.prompt('此操作將刪除全部搜尋分析資料且無法復原。\n請輸入 DELETE 確認：');
			if ('DELETE' !== (code || '').trim()) { return; }
			api('/purge', { method: 'POST', body: JSON.stringify({ mode: 'all', confirm: 'DELETE' }) }).then(function (d) {
				counts.textContent = fmtCounts(d.counts);
			});
		});
	}

	/* ═════════ 分析頁 ═════════ */

	function initAnalytics() {
		var app = document.getElementById('ys-ss-analytics-app');
		if (!app) { return; }

		var fromInput = document.getElementById('ys-ss-from');
		var toInput = document.getElementById('ys-ss-to');

		function fmtDate(d) {
			return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
		}

		function load(from, to) {
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

		function renderTop(rows) {
			var body = document.getElementById('ys-ss-top-body');
			body.textContent = '';
			if (!rows.length) {
				body.innerHTML = '<tr><td colspan="5">期間內無資料。</td></tr>';
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
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'ysca-btn ysca-btn--sm ysca-btn--ghost';
				btn.textContent = '＋設為關鍵字';
				btn.addEventListener('click', function () {
					api('/keywords', { method: 'POST', body: JSON.stringify({ keyword: r.term }) }).then(function () {
						btn.textContent = '✓ 已加入';
						btn.disabled = true;
					});
				});
				tdA.appendChild(btn);
				tr.appendChild(tdA);
				body.appendChild(tr);
			});
		}

		function renderZero(rows) {
			var body = document.getElementById('ys-ss-zero-body');
			body.textContent = '';
			if (!rows.length) {
				body.innerHTML = '<tr><td colspan="4">期間內沒有零結果搜尋 🎉</td></tr>';
				return;
			}
			rows.forEach(function (r, i) {
				var tr = document.createElement('tr');
				[i + 1, r.term, r.zero_hits, r.hits].forEach(function (v) {
					var td = document.createElement('td');
					td.textContent = String(v);
					tr.appendChild(td);
				});
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
