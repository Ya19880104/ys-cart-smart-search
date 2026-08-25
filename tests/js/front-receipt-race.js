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
		this.textContent = '';
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
	fetch() {
		return Promise.resolve({
			ok: true,
			json: () => Promise.resolve({
				q: 'nova', total: 0, groups: [], view_all: '', log_receipt: 'signed-receipt',
			}),
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

(async () => {
	input.listeners.input();
	if (!runTimer(250)) { throw new Error('debounced search timer was not scheduled'); }
	for (let i = 0; i < 5; i++) { await Promise.resolve(); }
	if (!hasActiveTimer(1200)) { throw new Error('settle timer was not scheduled after a signed response'); }

	input.value = 'nova x';
	input.listeners.input();
	if (hasActiveTimer(1200)) {
		throw new Error('new input did not cancel the previous signed settle timer immediately');
	}
	if (beacons.length !== 0) { throw new Error('stale analytics beacon was sent'); }

	form.listeners.submit();
	const recent = JSON.parse(sandbox.localStorage.getItem('ysss_recent') || '[]');
	if (recent.length !== 1 || 'nova x' !== recent[0]) {
		throw new Error('native submit without a receipt did not preserve the recent search');
	}
	if (beacons.length !== 0) { throw new Error('submit without a receipt sent an analytics beacon'); }
})().catch((error) => {
	console.error(error.message);
	process.exitCode = 1;
});
