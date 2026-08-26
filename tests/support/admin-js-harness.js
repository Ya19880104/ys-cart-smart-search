'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

class NodeStub {
	constructor(tagName = 'div') {
		this.tagName = String(tagName).toUpperCase();
		this.children = [];
		this.parentNode = null;
		this.listeners = {};
		this.attributes = new Map();
		this.selectorResults = new Map();
		this._textContent = '';
		this.value = '';
		this.disabled = false;
		this.checked = false;
		this.type = '';
		this.className = '';
		this.style = {};
		this.classNames = new Set();
		this.classList = {
			add: (...names) => names.forEach((name) => this.classNames.add(name)),
			remove: (...names) => names.forEach((name) => this.classNames.delete(name)),
			contains: (name) => this.classNames.has(name),
		};
	}
	get textContent() { return this._textContent; }
	set textContent(value) {
		this._textContent = String(value);
		this.children = [];
	}
	appendChild(child) {
		child.parentNode = this;
		this.children.push(child);
		return child;
	}
	addEventListener(type, callback) {
		this.listeners[type] = callback;
	}
	dispatch(type) {
		if (this.listeners[type]) {
			return this.listeners[type]({ target: this, preventDefault() {} });
		}
	}
	setAttribute(name, value) {
		this.attributes.set(name, String(value));
		this[name] = String(value);
	}
	getAttribute(name) {
		return this.attributes.has(name) ? this.attributes.get(name) : null;
	}
	querySelectorAll(selector) {
		return this.selectorResults.get(selector) || [];
	}
}

function response(ok, payload) {
	return Promise.resolve({ ok, json: () => Promise.resolve(payload) });
}

function deferred() {
	let resolve;
	let reject;
	const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
	return { promise, resolve, reject };
}

async function flush(rounds = 16) {
	for (let i = 0; i < rounds; i++) { await Promise.resolve(); }
}

function walk(root) {
	const nodes = [];
	(function visit(node) {
		if (!node) { return; }
		nodes.push(node);
		node.children.forEach(visit);
	})(root);
	return nodes;
}

function findNode(root, predicate) {
	return walk(root).find(predicate) || null;
}

function makeSettingsFixture(ids, initialKeywords) {
	const app = new NodeStub('div');
	app.setAttribute('data-keywords', JSON.stringify(initialKeywords || []));
	ids.set('ys-ss-settings-app', app);
	[
		['ys-ss-save', 'button'],
		['ys-ss-save-msg', 'div'],
		['ys-ss-kw-add', 'button'],
		['ys-ss-kw-input', 'input'],
		['ys-ss-counts', 'div'],
		['ys-ss-purge-expired', 'button'],
		['ys-ss-purge-all', 'button'],
	].forEach(([id, tag]) => ids.set(id, new NodeStub(tag)));
	return new NodeStub('tbody');
}

function makeAnalyticsFixture(ids) {
	[
		['ys-ss-analytics-app', 'div'],
		['ys-ss-from', 'input'],
		['ys-ss-to', 'input'],
		['ys-ss-export', 'a'],
		['ys-ss-kpi-total', 'div'],
		['ys-ss-kpi-unique', 'div'],
		['ys-ss-kpi-zero', 'div'],
		['ys-ss-kpi-zerorate', 'div'],
		['ys-ss-trend', 'div'],
		['ys-ss-top-body', 'tbody'],
		['ys-ss-zero-body', 'tbody'],
		['ys-ss-apply', 'button'],
		['ys-ss-action-msg', 'div'],
		['ys-ss-delete-selected', 'button'],
		['ys-ss-delete-all-analytics', 'button'],
	].forEach(([id, tag]) => ids.set(id, new NodeStub(tag)));
}

function createAdminHarness(options = {}) {
	const ids = new Map();
	const buttons = [];
	const fetchCalls = [];
	const timers = new Map();
	let nextTimerId = 1;
	let keywordBody = null;

	if (options.settings) {
		keywordBody = makeSettingsFixture(ids, options.initialKeywords || []);
	}
	if (options.analytics) {
		makeAnalyticsFixture(ids);
	}

	const documentStub = {
		readyState: 'complete',
		getElementById(id) { return ids.get(id) || null; },
		querySelector(selector) {
			return '#ys-ss-kw-table tbody' === selector ? keywordBody : null;
		},
		querySelectorAll() { return []; },
		addEventListener() {},
		createElement(tag) {
			const node = new NodeStub(tag);
			if ('button' === String(tag).toLowerCase()) { buttons.push(node); }
			return node;
		},
	};

	const fetchImpl = options.fetch || ((url) => { throw new Error('unexpected fetch: ' + url); });
	function fetchStub(url, requestOptions) {
		fetchCalls.push({ url, options: requestOptions || {} });
		return fetchImpl(url, requestOptions || {}, fetchCalls.length);
	}

	const sandbox = {
		window: {
			ysSsAdmin: { restUrl: 'https://example.test/wp-json/ys', nonce: 'nonce' },
			prompt: options.prompt || (() => 'DELETE'),
			confirm: options.confirm || (() => true),
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
		Map,
		Set,
		WeakSet,
		encodeURIComponent,
	};

	const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/ys-ss-admin.js'), 'utf8');
	vm.runInNewContext(source, sandbox, { filename: 'ys-ss-admin.js' });

	return {
		ids,
		buttons,
		fetchCalls,
		timers,
		keywordBody,
		document: documentStub,
		sandbox,
	};
}

module.exports = {
	NodeStub,
	createAdminHarness,
	deferred,
	findNode,
	flush,
	response,
	walk,
};
