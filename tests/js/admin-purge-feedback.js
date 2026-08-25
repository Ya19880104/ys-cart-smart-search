'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

class NodeStub {
	constructor() {
		this.listeners = {};
		this.textContent = '';
		this.value = '';
		this.disabled = false;
		this.checked = false;
	}
	addEventListener(type, callback) { this.listeners[type] = callback; }
	querySelectorAll() { return []; }
	getAttribute(name) { return 'data-keywords' === name ? '[]' : null; }
	appendChild() {}
}

const ids = new Map();
[
	'ys-ss-settings-app', 'ys-ss-save', 'ys-ss-save-msg', 'ys-ss-kw-add',
	'ys-ss-kw-input', 'ys-ss-counts', 'ys-ss-purge-expired', 'ys-ss-purge-all',
].forEach((id) => ids.set(id, new NodeStub()));
const keywordBody = new NodeStub();

const documentStub = {
	readyState: 'complete',
	getElementById(id) { return ids.get(id) || null; },
	querySelector(selector) { return '#ys-ss-kw-table tbody' === selector ? keywordBody : null; },
	querySelectorAll() { return []; },
	addEventListener() {},
	createElement() { return new NodeStub(); },
};

const purgeRequests = [];
let responseIndex = 0;
function fetchStub(url, options) {
	if (!url.includes('/purge')) { throw new Error('unexpected fetch: ' + url); }
	purgeRequests.push({ url, options });
	responseIndex++;
	if (1 === responseIndex) {
		return Promise.resolve({
			ok: false,
			json: () => Promise.resolve({ code: 'ys_ss_analytics_mutation_failed', message: 'SECRET SQL' }),
		});
	}
	return Promise.reject(new Error('SECRET NETWORK'));
}

const sandbox = {
	window: {
		ysSsAdmin: { restUrl: 'https://example.test/wp-json/ys', nonce: 'nonce' },
		prompt() { return 'DELETE'; },
	},
	document: documentStub,
	fetch: fetchStub,
	setTimeout() { return 1; },
	clearTimeout() {},
	console,
	Date,
	JSON,
	Promise,
	Array,
	Object,
	Number,
	String,
};

async function flush() {
	for (let i = 0; i < 10; i++) { await Promise.resolve(); }
}

const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/ys-ss-admin.js'), 'utf8');
vm.runInNewContext(source, sandbox, { filename: 'ys-ss-admin.js' });

(async () => {
	const message = ids.get('ys-ss-save-msg');
	const expiredButton = ids.get('ys-ss-purge-expired');
	const allButton = ids.get('ys-ss-purge-all');

	expiredButton.listeners.click();
	await flush();
	if (expiredButton.disabled) { throw new Error('expired purge failure did not re-enable its button'); }
	if ('清理失敗，請稍後再試。' !== message.textContent) {
		throw new Error('expired purge did not show a fixed safe failure message');
	}
	if (message.textContent.includes('SECRET')) { throw new Error('expired purge leaked server detail'); }

	allButton.listeners.click();
	await flush();
	if (allButton.disabled) { throw new Error('full purge failure did not re-enable its button'); }
	if ('清理失敗，請稍後再試。' !== message.textContent) {
		throw new Error('full purge did not show a fixed safe network failure message');
	}
	if (message.textContent.includes('SECRET')) { throw new Error('full purge leaked network detail'); }

	if (2 !== purgeRequests.length) { throw new Error('expected two purge requests'); }
	const expiredPayload = JSON.parse(purgeRequests[0].options.body);
	const allPayload = JSON.parse(purgeRequests[1].options.body);
	if ('POST' !== purgeRequests[0].options.method || 'expired' !== expiredPayload.mode) {
		throw new Error('expired purge request contract changed');
	}
	if ('POST' !== purgeRequests[1].options.method || 'all' !== allPayload.mode || 'DELETE' !== allPayload.confirm) {
		throw new Error('full purge request contract changed');
	}
})().catch((error) => {
	console.error(error.message);
	process.exitCode = 1;
});
