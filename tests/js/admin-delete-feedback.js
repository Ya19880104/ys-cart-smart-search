'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

class NodeStub {
	constructor(tag) {
		this.tagName = tag;
		this.children = [];
		this.listeners = {};
		this.textContent = '';
		this.value = '';
		this.disabled = false;
		this.style = {};
		this.classList = { add() {}, remove() {} };
	}
	appendChild(child) {
		this.children.push(child);
		return child;
	}
	addEventListener(type, callback) {
		this.listeners[type] = callback;
	}
	setAttribute(name, value) {
		this[name] = String(value);
	}
	getAttribute(name) {
		return this[name] || null;
	}
	querySelectorAll() { return []; }
}

const ids = new Map();
[
	'ys-ss-analytics-app', 'ys-ss-from', 'ys-ss-to', 'ys-ss-export',
	'ys-ss-kpi-total', 'ys-ss-kpi-unique', 'ys-ss-kpi-zero', 'ys-ss-kpi-zerorate',
	'ys-ss-trend', 'ys-ss-top-body', 'ys-ss-zero-body', 'ys-ss-apply',
	'ys-ss-action-msg',
].forEach((id) => ids.set(id, new NodeStub('div')));

const buttons = [];
const documentStub = {
	readyState: 'complete',
	getElementById(id) { return ids.get(id) || null; },
	querySelectorAll() { return []; },
	addEventListener() {},
	createElement(tag) {
		const node = new NodeStub(tag);
		if ('button' === tag) { buttons.push(node); }
		return node;
	},
};

let overviewCalls = 0;
const termRequests = [];
const termResponses = [
	{ ok: false, payload: { code: 'ys_ss_analytics_busy', message: 'SECRET SQL ONE' } },
	{ ok: false, payload: { code: 'ys_ss_analytics_mutation_failed', message: 'SECRET SQL TWO' } },
	{ ok: true, payload: { ok: true, deleted: { queries: 2, daily: 1, total: 3 } } },
	{ ok: false, payload: { code: 'ys_ss_analytics_busy', message: 'SECRET SQL THREE' } },
];

function response(ok, payload) {
	return Promise.resolve({ ok, json: () => Promise.resolve(payload) });
}

function fetchStub(url, options) {
	if (url.includes('/overview?')) {
		overviewCalls++;
		return response(true, {
			kpi: { total: 1, unique: 1, zero: 0, zero_rate: 0 },
			trend: [],
			top: [{ term: 'C:\\Docs\\<vector>', hits: 1, zero_hits: 0 }],
			zero: [],
		});
	}
	if (url.includes('/term?')) {
		if (!options || 'DELETE' !== options.method) { throw new Error('exact term request did not use DELETE'); }
		termRequests.push(url);
		const next = termResponses.shift();
		return response(next.ok, next.payload);
	}
	throw new Error('unexpected fetch: ' + url);
}

let nextTimerId = 1;
const timers = new Map();

const sandbox = {
	window: {
		ysSsAdmin: { restUrl: 'https://example.test/wp-json/ys', nonce: 'nonce' },
		confirm() { return true; },
	},
	document: documentStub,
	fetch: fetchStub,
	setTimeout(callback, delay) {
		const id = nextTimerId++;
		timers.set(id, { callback, delay, cancelled: false });
		return id;
	},
	clearTimeout(id) {
		if (timers.has(id)) { timers.get(id).cancelled = true; }
	},
	console,
	Date,
	JSON,
	Promise,
	Array,
	Object,
	Math,
	Number,
	String,
	encodeURIComponent,
};

async function flush() {
	for (let i = 0; i < 8; i++) { await Promise.resolve(); }
}

const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/ys-ss-admin.js'), 'utf8');
vm.runInNewContext(source, sandbox, { filename: 'ys-ss-admin.js' });

(async () => {
	await flush();
	const deleteButton = buttons.find((button) => '刪除此關鍵字的全部紀錄' === button.title);
	if (!deleteButton) { throw new Error('analytics exact-delete button was not rendered'); }
	const actionMessage = ids.get('ys-ss-action-msg');

	deleteButton.listeners.click();
	await flush();
	if (deleteButton.disabled) { throw new Error('busy failure did not re-enable the delete button'); }
	if ('搜尋分析正在更新，請稍後再試。' !== actionMessage.textContent) {
		throw new Error('busy failure did not show the fixed safe message');
	}
	if (1 !== overviewCalls) { throw new Error('busy failure unexpectedly reloaded analytics'); }

	deleteButton.listeners.click();
	await flush();
	if (deleteButton.disabled) { throw new Error('database failure did not re-enable the delete button'); }
	if ('刪除失敗，請稍後再試。' !== actionMessage.textContent) {
		throw new Error('database failure did not show the fixed safe message');
	}
	if (actionMessage.textContent.includes('SECRET SQL')) {
		throw new Error('server error detail leaked into the admin UI');
	}
	if (1 !== overviewCalls) { throw new Error('database failure unexpectedly reloaded analytics'); }

	deleteButton.listeners.click();
	await flush();
	if ('已刪除 3 筆搜尋紀錄。' !== actionMessage.textContent) {
		throw new Error('success did not report the trusted server deletion total');
	}
	if (2 !== overviewCalls) { throw new Error('successful delete did not reload analytics'); }

	const refreshedDeleteButton = buttons.filter((button) => '刪除此關鍵字的全部紀錄' === button.title).at(-1);
	refreshedDeleteButton.listeners.click();
	await flush();
	if ('搜尋分析正在更新，請稍後再試。' !== actionMessage.textContent) {
		throw new Error('new failure did not replace the previous success message');
	}
	for (const timer of timers.values()) {
		if (!timer.cancelled && 4000 === timer.delay) { timer.callback(); }
	}
	if ('搜尋分析正在更新，請稍後再試。' !== actionMessage.textContent) {
		throw new Error('stale success timer erased a newer failure message');
	}

	if (4 !== termRequests.length || !termRequests.every((url) => url.includes('term=C%3A%5CDocs%5C%3Cvector%3E'))) {
		throw new Error('exact term bytes were not encoded consistently in delete requests');
	}
})().catch((error) => {
	console.error(error.message);
	process.exitCode = 1;
});
