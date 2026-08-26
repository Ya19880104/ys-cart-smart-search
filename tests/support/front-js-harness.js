'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

function classTokens(value) {
	return String(value || '').split(/\s+/).filter(Boolean);
}

function selectorMatches(node, selector) {
	selector = selector.trim();
	if (!selector) { return false; }
	const notMatch = selector.match(/:not\(([^)]+)\)$/);
	if (notMatch) {
		selector = selector.slice(0, notMatch.index);
		if (selectorMatches(node, notMatch[1])) { return false; }
	}
	const attributes = Array.from(selector.matchAll(/\[([^\]=]+)(?:="([^"]*)")?\]/g));
	selector = selector.replace(/\[[^\]]+\]/g, '');
	for (const match of attributes) {
		if (!node.hasAttribute(match[1])) { return false; }
		if (typeof match[2] !== 'undefined' && node.getAttribute(match[1]) !== match[2]) { return false; }
	}
	const idMatch = selector.match(/#([A-Za-z0-9_-]+)/);
	if (idMatch && node.getAttribute('id') !== idMatch[1]) { return false; }
	selector = selector.replace(/#[A-Za-z0-9_-]+/, '');
	const classes = Array.from(selector.matchAll(/\.([A-Za-z0-9_-]+)/g), (match) => match[1]);
	if (classes.some((name) => !node.classList.contains(name))) { return false; }
	selector = selector.replace(/\.[A-Za-z0-9_-]+/g, '').trim();
	return !selector || '*' === selector || node.tagName.toLowerCase() === selector.toLowerCase();
}

class NodeStub {
	constructor(tag, ownerDocument) {
		this.tagName = String(tag).toUpperCase();
		this.ownerDocument = ownerDocument;
		this.parentNode = null;
		this.children = [];
		this.listeners = {};
		this.attributes = {};
		this.hidden = false;
		this.value = '';
		this.clickCount = 0;
		this._textContent = '';
		this._classes = new Set();
		this.classList = {
			add: (...names) => names.forEach((name) => this._classes.add(name)),
			remove: (...names) => names.forEach((name) => this._classes.delete(name)),
			contains: (name) => this._classes.has(name),
		};
		Object.defineProperty(this, 'className', {
			get: () => Array.from(this._classes).join(' '),
			set: (value) => { this._classes = new Set(classTokens(value)); },
		});
		Object.defineProperty(this, 'href', {
			get: () => this.getAttribute('href') || '',
			set: (value) => { this.setAttribute('href', value); },
		});
		Object.defineProperty(this, 'childElementCount', {
			get: () => this.children.filter((child) => '#TEXT' !== child.tagName).length,
		});
		Object.defineProperty(this, 'textContent', {
			get: () => this._textContent + this.children.map((child) => child.textContent).join(''),
			set: (value) => {
				this._textContent = String(value);
				this.children.forEach((child) => { child.parentNode = null; });
				this.children = [];
			},
		});
	}
	appendChild(child) {
		child.parentNode = this;
		this.children.push(child);
		return child;
	}
	removeChild(child) {
		const index = this.children.indexOf(child);
		if (index < 0) { throw new Error('Node is not a child'); }
		this.children.splice(index, 1);
		child.parentNode = null;
		return child;
	}
	addEventListener(type, callback) {
		if (!this.listeners[type]) { this.listeners[type] = []; }
		this.listeners[type].push(callback);
	}
	dispatchEvent(event) {
		event = event || {};
		event.type = event.type || '';
		event.target = event.target || this;
		event.currentTarget = this;
		event.defaultPrevented = false;
		event.preventDefault = event.preventDefault || function () { this.defaultPrevented = true; };
		event.stopPropagation = event.stopPropagation || function () { this._stopped = true; };
		(this.listeners[event.type] || []).slice().forEach((callback) => callback(event));
		if (!event._stopped && this.ownerDocument && this !== this.ownerDocument) {
			this.ownerDocument._dispatchBubbled(event);
		}
		return !event.defaultPrevented;
	}
	setAttribute(name, value) {
		this.attributes[name] = String(value);
		if ('hidden' === name) { this.hidden = true; }
		if ('class' === name) { this.className = value; }
	}
	getAttribute(name) {
		if ('class' === name) { return this.className || null; }
		return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
	}
	hasAttribute(name) {
		if ('class' === name) { return this._classes.size > 0; }
		return Object.prototype.hasOwnProperty.call(this.attributes, name);
	}
	removeAttribute(name) {
		delete this.attributes[name];
		if ('hidden' === name) { this.hidden = false; }
		if ('class' === name) { this.className = ''; }
	}
	querySelectorAll(selector) {
		const selectors = selector.split(',').map((part) => part.trim());
		const found = [];
		const visit = (node) => {
			node.children.forEach((child) => {
				if (selectors.some((part) => selectorMatches(child, part))) { found.push(child); }
				visit(child);
			});
		};
		visit(this);
		return found;
	}
	querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
	closest(selector) {
		let node = this;
		while (node) {
			if (selectorMatches(node, selector)) { return node; }
			node = node.parentNode;
		}
		return null;
	}
	contains(node) {
		let current = node;
		while (current) {
			if (current === this) { return true; }
			current = current.parentNode;
		}
		return false;
	}
	focus() {
		if (!this.ownerDocument || this.ownerDocument.activeElement === this) { return; }
		this.ownerDocument.activeElement = this;
		this.dispatchEvent({ type: 'focus', target: this });
	}
	click() {
		this.clickCount += 1;
		this.dispatchEvent({ type: 'click', target: this });
	}
}

class DocumentStub extends NodeStub {
	constructor() {
		super('#document', null);
		this.ownerDocument = this;
		this.readyState = 'complete';
		this.activeElement = null;
		this.documentElement = new NodeStub('html', this);
		this.appendChild(this.documentElement);
		this.body = new NodeStub('body', this);
		this.documentElement.appendChild(this.body);
	}
	createElement(tag) { return new NodeStub(tag, this); }
	createTextNode(text) {
		const node = new NodeStub('#text', this);
		node.textContent = text;
		return node;
	}
	getElementById(id) { return this.querySelector('#' + id); }
	_dispatchBubbled(event) {
		event.currentTarget = this;
		(this.listeners[event.type] || []).slice().forEach((callback) => callback(event));
	}
}

function createStorage() {
	const values = new Map();
	return {
		getItem(key) { return values.has(key) ? values.get(key) : null; },
		setItem(key, value) { values.set(key, String(value)); },
		removeItem(key) { values.delete(key); },
		clear() { values.clear(); },
	};
}

function createForm(document, source, id) {
	const form = document.createElement('form');
	form.className = 'ys-ss-form';
	form.setAttribute('data-ys-ss', '');
	form.setAttribute('data-ys-ss-source', source);
	const wrap = document.createElement('div');
	wrap.className = 'ys-ss-inputwrap';
	const input = document.createElement('input');
	input.className = 'ys-ss-input';
	input.setAttribute('role', 'combobox');
	input.setAttribute('aria-autocomplete', 'list');
	input.setAttribute('aria-expanded', 'false');
	input.setAttribute('aria-controls', id);
	const submit = document.createElement('button');
	submit.className = 'ys-ss-submit';
	submit.type = 'submit';
	const panel = document.createElement('div');
	panel.className = 'ys-ss-panel';
	panel.setAttribute('id', id);
	panel.setAttribute('role', 'listbox');
	panel.setAttribute('aria-live', 'polite');
	panel.setAttribute('hidden', 'hidden');
	wrap.appendChild(input);
	wrap.appendChild(submit);
	wrap.appendChild(panel);
	form.appendChild(wrap);
	return { form, input, submit, panel };
}

function createHarness(options = {}) {
	const document = new DocumentStub();
	const forms = [];
	const triggers = [];
	const formCount = typeof options.formCount === 'number' ? options.formCount : 1;
	for (let index = 0; index < formCount; index += 1) {
		const parts = createForm(document, 'bar', 'ys-ss-panel-fixture-' + index);
		forms.push(parts);
		document.body.appendChild(parts.form);
	}

	let popup = null;
	if (options.popup) {
		const triggerCount = options.triggerCount || 2;
		for (let index = 0; index < triggerCount; index += 1) {
			const trigger = document.createElement('button');
			trigger.setAttribute('data-ys-ss-open', '');
			trigger.setAttribute('aria-haspopup', 'dialog');
			trigger.textContent = 'trigger-' + index;
			triggers.push(trigger);
			document.body.appendChild(trigger);
		}
		const root = document.createElement('div');
		root.className = 'ys-ss-popup';
		root.setAttribute('id', 'ys-ss-popup');
		root.setAttribute('hidden', 'hidden');
		root.setAttribute('role', 'dialog');
		const backdrop = document.createElement('div');
		backdrop.setAttribute('data-ys-ss-close', '');
		const content = document.createElement('div');
		content.className = 'ys-ss-popup__content';
		const close = document.createElement('button');
		close.className = 'ys-ss-popup__close';
		close.setAttribute('data-ys-ss-close', '');
		close.type = 'button';
		const parts = createForm(document, 'popup', 'ys-ss-panel-popup');
		content.appendChild(close);
		content.appendChild(parts.form);
		root.appendChild(backdrop);
		root.appendChild(content);
		document.body.appendChild(root);
		forms.push(parts);
		popup = { root, backdrop, content, close, ...parts };
	}

	let now = 0;
	let nextTimerId = 1;
	const timers = new Map();
	function setTimeoutFake(callback, delay) {
		const id = nextTimerId++;
		timers.set(id, { callback, due: now + Number(delay || 0) });
		return id;
	}
	function clearTimeoutFake(id) { timers.delete(id); }
	function advanceTimers(milliseconds) {
		const target = now + milliseconds;
		while (true) {
			let selectedId = null;
			let selected = null;
			for (const [id, timer] of timers.entries()) {
				if (timer.due <= target && (!selected || timer.due < selected.due || (timer.due === selected.due && id < selectedId))) {
					selectedId = id;
					selected = timer;
				}
			}
			if (!selected) { break; }
			now = selected.due;
			timers.delete(selectedId);
			selected.callback();
		}
		now = target;
	}
	function hasTimer(delayFromNow) {
		return Array.from(timers.values()).some((timer) => timer.due - now === delayFromNow);
	}

	const pendingFetches = [];
	function fetchStub(url, fetchOptions = {}) {
		let resolvePromise;
		let rejectPromise;
		const promise = new Promise((resolve, reject) => {
			resolvePromise = resolve;
			rejectPromise = reject;
		});
		pendingFetches.push({ url, options: fetchOptions, resolve: resolvePromise, reject: rejectPromise, promise });
		return promise;
	}

	class AbortControllerStub {
		constructor() {
			const listeners = [];
			this.signal = {
				aborted: false,
				addEventListener(type, callback) { if ('abort' === type) { listeners.push(callback); } },
				removeEventListener(type, callback) {
					if ('abort' !== type) { return; }
					const index = listeners.indexOf(callback);
					if (index >= 0) { listeners.splice(index, 1); }
				},
			};
			this._listeners = listeners;
		}
		abort() {
			if (this.signal.aborted) { return; }
			this.signal.aborted = true;
			this._listeners.slice().forEach((listener) => listener({ type: 'abort', target: this.signal }));
		}
	}

	const beacons = [];
	const cfg = {
		restUrl: 'https://example.test/wp-json/ys/smart-search',
		shopUrl: 'https://example.test/shop/',
		recentEnabled: true,
		resultsMode: 'list',
		i18n: {
			popular: '熱門搜尋', recent: '最近搜尋', viewAll: '查看全部商品結果 →',
			noResults: '找不到符合的結果，試試其他關鍵字：', searching: '搜尋中…',
			error: '搜尋暫時無法使用，請稍後再試。',
		},
		...(options.cfg || {}),
	};
	if (options.cfg && options.cfg.i18n) { cfg.i18n = { ...cfg.i18n, ...options.cfg.i18n }; }

	const sandbox = {
		window: { ysSsFront: cfg }, document,
		navigator: { sendBeacon(url, blob) {
			if (false === options.sendBeaconResult) { return false; }
			beacons.push({ url, blob });
			return true;
		} },
		localStorage: createStorage(), sessionStorage: createStorage(), fetch: fetchStub,
		AbortController: AbortControllerStub,
		Blob: class Blob { constructor(parts, blobOptions) { this.parts = parts; this.options = blobOptions; } },
		setTimeout: setTimeoutFake, clearTimeout: clearTimeoutFake,
		console, Date, JSON, Promise, Array, Math, Number, String, encodeURIComponent,
	};
	const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/ys-ss-front.js'), 'utf8');
	vm.runInNewContext(source, sandbox, { filename: 'ys-ss-front.js' });

	async function flushPromises() {
		for (let index = 0; index < 10; index += 1) { await Promise.resolve(); }
	}
	async function resolveJson(index, data, status = 200) {
		const request = pendingFetches[index];
		if (!request) { throw new Error('missing deferred fetch request ' + index); }
		request.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(data) });
		await flushPromises();
	}
	async function resolveInvalidJson(index, detail = 'invalid json detail') {
		const request = pendingFetches[index];
		if (!request) { throw new Error('missing deferred fetch request ' + index); }
		request.resolve({ ok: true, status: 200, json: () => Promise.reject(new SyntaxError(detail)) });
		await flushPromises();
	}
	async function rejectFetch(index, error) {
		const request = pendingFetches[index];
		if (!request) { throw new Error('missing deferred fetch request ' + index); }
		request.reject(error);
		await flushPromises();
	}
	function dispatch(node, type, props = {}) {
		const event = { type, target: node, key: '', shiftKey: false, ...props };
		node.dispatchEvent(event);
		return event;
	}
	function beaconPayload(index) {
		const beacon = beacons[index];
		return beacon ? JSON.parse(beacon.blob.parts.join('')) : null;
	}

	return { sandbox, document, forms, popup, triggers, pendingFetches, beacons, advanceTimers, hasTimer, flushPromises, resolveJson, resolveInvalidJson, rejectFetch, dispatch, beaconPayload };
}

function assert(condition, message) {
	if (!condition) { throw new Error(message); }
}

async function runTests(tests) {
	let failures = 0;
	for (const [label, test] of tests) {
		try {
			await test();
			console.log('[PASS] ' + label);
		} catch (error) {
			failures += 1;
			console.error('[FAIL] ' + label + ': ' + error.message);
		}
	}
	if (failures) { process.exitCode = 1; }
}

module.exports = { NodeStub, createHarness, assert, runTests };
