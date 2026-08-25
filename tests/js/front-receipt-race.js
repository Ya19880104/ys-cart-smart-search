'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

class NodeStub {
	constructor(tag) {
		this.tagName = tag;
		this.children = [];
		this.listeners = {};
		this.hidden = false;
		this.childElementCount = 0;
		this._textContent = '';
		Object.defineProperty(this, 'textContent', {
			get: () => this._textContent,
			set: (value) => {
				this._textContent = String(value);
				if ('' === value) {
					this.children = [];
					this.childElementCount = 0;
				}
			},
		});
		this.classList = { add() {}, remove() {} };
	}
	appendChild(child) {
		this.children.push(child);
		this.childElementCount = this.children.length;
		return child;
	}
	addEventListener(type, callback) {
		this.listeners[type] = callback;
	}
	setAttribute() {}
	removeAttribute() {}
	querySelectorAll() { return []; }
	closest() { return null; }
}

const input = new NodeStub('input');
input.value = 'nova';
input.focus = function () {};
const panel = new NodeStub('div');
const form = new NodeStub('form');
form.querySelector = function (selector) {
	if ('.ys-ss-input' === selector) { return input; }
	if ('.ys-ss-panel' === selector) { return panel; }
	return null;
};
form.getAttribute = function () { return 'bar'; };
form.contains = function () { return true; };

let nextTimerId = 1;
const timers = new Map();
function setTimeoutFake(callback, delay) {
	const id = nextTimerId++;
	timers.set(id, { callback, delay, cleared: false });
	return id;
}
function clearTimeoutFake(id) {
	if (timers.has(id)) { timers.get(id).cleared = true; }
}
function runTimer(delay) {
	for (const [id, timer] of timers.entries()) {
		if (!timer.cleared && timer.delay === delay) {
			timers.delete(id);
			timer.callback();
			return true;
		}
	}
	return false;
}
function hasActiveTimer(delay) {
	return Array.from(timers.values()).some((timer) => !timer.cleared && timer.delay === delay);
}

const storage = () => {
	const values = new Map();
	return {
		getItem(key) { return values.has(key) ? values.get(key) : null; },
		setItem(key, value) { values.set(key, String(value)); },
	};
};

const beacons = [];
const pendingFetches = [];
const documentStub = {
	readyState: 'complete',
	documentElement: { classList: { add() {}, remove() {} } },
	querySelectorAll() { return [form]; },
	getElementById() { return null; },
	addEventListener() {},
	createElement(tag) { return new NodeStub(tag); },
	createTextNode(text) { const node = new NodeStub('#text'); node.textContent = text; return node; },
};

const sandbox = {
	window: {
		ysSsFront: {
			restUrl: 'https://example.test/wp-json/ys/smart-search',
			shopUrl: 'https://example.test/shop/',
			recentEnabled: true,
			resultsMode: 'list',
			i18n: { popular: '', recent: '', viewAll: '', noResults: 'none', searching: 'searching' },
		},
	},
	document: documentStub,
	navigator: { sendBeacon(url, blob) { beacons.push({ url, blob }); return true; } },
	localStorage: storage(),
	sessionStorage: storage(),
	fetch(url) {
		return new Promise((resolve) => {
			pendingFetches.push({ url, resolve });
		});
	},
	Blob: class Blob { constructor(parts, options) { this.parts = parts; this.options = options; } },
	setTimeout: setTimeoutFake,
	clearTimeout: clearTimeoutFake,
	console,
	Date,
	JSON,
	Promise,
	Array,
	encodeURIComponent,
};

const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/ys-ss-front.js'), 'utf8');
vm.runInNewContext(source, sandbox, { filename: 'ys-ss-front.js' });

async function flushPromises() {
	for (let i = 0; i < 6; i++) { await Promise.resolve(); }
}

async function resolveSearch(index, data) {
	const request = pendingFetches[index];
	if (!request) { throw new Error('missing deferred search request ' + index); }
	request.resolve({ ok: true, json: () => Promise.resolve(data) });
	await flushPromises();
}

function beaconPayload(index) {
	const beacon = beacons[index];
	if (!beacon) { return null; }
	return JSON.parse(beacon.blob.parts.join(''));
}

function renderedGroupLabel() {
	return (((panel.children[0] || {}).children || [])[0] || {}).textContent || '';
}

(async () => {
	input.value = 'Alpha Pro';
	input.listeners.input();
	if (!runTimer(250)) { throw new Error('debounced search timer was not scheduled'); }

	input.value = 'beta';
	input.listeners.input();
	if (!runTimer(250)) { throw new Error('second debounced search timer was not scheduled'); }

	input.value = 'Alpha Pro';
	input.listeners.input();
	if (!runTimer(250)) { throw new Error('third debounced search timer was not scheduled'); }

	await resolveSearch(2, {
		q: 'alpha pro', total: 2,
		groups: [{ type: 'products', label: 'newest-results', items: [], total: 2 }],
		view_all: '', log_receipt: 'receipt-alpha-new',
	});
	if (!hasActiveTimer(1200)) { throw new Error('settle timer was not scheduled after the newest signed response'); }

	await resolveSearch(0, {
		q: 'alpha pro', total: 1,
		groups: [{ type: 'products', label: 'stale-results', items: [], total: 1 }],
		view_all: '', log_receipt: 'receipt-alpha-old',
	});
	if ('newest-results' !== renderedGroupLabel()) {
		throw new Error('older same-query response replaced the newest rendered results');
	}

	form.listeners.submit();
	let recent = JSON.parse(sandbox.localStorage.getItem('ysss_recent') || '[]');
	if (recent.length !== 1 || 'Alpha Pro' !== recent[0]) {
		throw new Error('positive result did not preserve the exact displayed recent search');
	}
	if (beacons.length !== 1 || 'receipt-alpha-new' !== (beaconPayload(0) || {}).receipt) {
		throw new Error('older same-query response replaced the newest analytics proof');
	}

	input.value = 'human zero';
	input.listeners.input();
	if (!runTimer(250)) { throw new Error('zero-result debounced search timer was not scheduled'); }
	await resolveSearch(3, {
		q: 'human zero', total: 0, groups: [], view_all: '', log_receipt: 'receipt-zero',
	});
	form.listeners.submit();
	recent = JSON.parse(sandbox.localStorage.getItem('ysss_recent') || '[]');
	if (recent.length !== 1 || 'Alpha Pro' !== recent[0]) {
		throw new Error('zero-result search polluted browser recent history');
	}
	if (beacons.length !== 2 || 'receipt-zero' !== (beaconPayload(1) || {}).receipt) {
		throw new Error('human zero-result analytics was not preserved');
	}

	input.value = 'quick request';
	input.listeners.input();
	form.listeners.submit();
	recent = JSON.parse(sandbox.localStorage.getItem('ysss_recent') || '[]');
	if (recent.length !== 1 || 'Alpha Pro' !== recent[0]) {
		throw new Error('receipt-less quick submit polluted browser recent history');
	}
	if (beacons.length !== 2) { throw new Error('receipt-less quick submit sent analytics'); }
})().catch((error) => {
	console.error(error.message);
	process.exitCode = 1;
});
