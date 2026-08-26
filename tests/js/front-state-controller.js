'use strict';

const { createHarness, assert, runTests } = require('../support/front-js-harness');

const ERROR = '搜尋暫時無法使用，請稍後再試。';

function result(query, label, receipt, items = [{ title: label, url: '/product/' + query }]) {
	return {
		q: query,
		total: items.length,
		groups: [{ type: 'products', label, items, total: items.length }],
		view_all: '/shop/?q=' + query,
		log_receipt: receipt,
	};
}

async function search(h, formParts, query, response) {
	formParts.input.value = query;
	h.dispatch(formParts.input, 'input');
	h.advanceTimers(250);
	const index = h.pendingFetches.length - 1;
	await h.resolveJson(index, response);
	return index;
}

runTests([
	['late empty-input suggest cannot replace a later successful nova query', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = '';
		h.dispatch(parts.input, 'focus');
		assert(h.pendingFetches[0] && h.pendingFetches[0].url.endsWith('/suggest'), 'empty focus did not request suggestions');
		parts.input.value = 'nova';
		h.dispatch(parts.input, 'input');
		h.advanceTimers(250);
		await h.resolveJson(1, result('nova', 'nova-results', 'receipt-nova'));
		await h.resolveJson(0, { items: [{ term: 'stale-popular' }] });
		assert(parts.panel.textContent.includes('nova-results'), 'late suggest replaced the current nova result');
		assert(!parts.panel.textContent.includes('stale-popular'), 'late suggest entered the current panel');
	}],
	['changing A to B immediately removes A links and A proof before debounce', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		await search(h, parts, 'alpha', result('alpha', 'alpha-results', 'receipt-alpha'));
		assert(parts.panel.querySelectorAll('.ys-ss-item').length === 1, 'A fixture did not render a result link');
		parts.input.value = 'beta';
		h.dispatch(parts.input, 'input');
		assert(0 === parts.panel.querySelectorAll('.ys-ss-item').length, 'A links remained during B debounce');
		assert(!parts.panel.textContent.includes('alpha-results'), 'A text remained during B debounce');
		h.dispatch(parts.form, 'submit');
		assert(0 === h.beacons.length, 'A proof remained usable after input changed to B');
		assert('[]' === (h.sandbox.localStorage.getItem('ysss_recent') || '[]'), 'A proof wrote recent history for B');
	}],
	['HTTP 500 renders only the localized current error and clears busy', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		await search(h, parts, 'alpha', result('alpha', 'alpha-secret', 'receipt-alpha'));
		parts.input.value = 'beta';
		h.dispatch(parts.input, 'input');
		h.advanceTimers(250);
		await h.resolveJson(1, { message: 'server stack detail' }, 500);
		assert(ERROR === parts.panel.textContent, 'HTTP failure did not render the fixed localized error');
		assert(!parts.panel.textContent.includes('server stack detail'), 'server error detail entered panel text');
		assert('false' === parts.input.getAttribute('aria-busy'), 'HTTP failure left input busy');
		h.dispatch(parts.form, 'submit');
		assert(0 === h.beacons.length && null === h.sandbox.localStorage.getItem('ysss_recent'), 'HTTP failure retained analytics/recent proof');
		assert(!parts.panel.textContent.includes('alpha-secret'), 'HTTP failure retained A');
	}],
	['invalid JSON renders only the localized current error and clears busy', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		await search(h, parts, 'alpha', result('alpha', 'alpha-secret', 'receipt-alpha'));
		parts.input.value = 'beta';
		h.dispatch(parts.input, 'input');
		h.advanceTimers(250);
		await h.resolveInvalidJson(1, 'Unexpected token < server trace');
		assert(ERROR === parts.panel.textContent, 'invalid JSON did not render the fixed localized error');
		assert(!parts.panel.textContent.includes('Unexpected token'), 'JSON parser detail entered panel text');
		assert('false' === parts.input.getAttribute('aria-busy'), 'invalid JSON left input busy');
		h.dispatch(parts.form, 'submit');
		assert(0 === h.beacons.length && null === h.sandbox.localStorage.getItem('ysss_recent'), 'invalid JSON retained analytics/recent proof');
		assert(!parts.panel.textContent.includes('alpha-secret'), 'invalid JSON retained A');
	}],
	['network rejection renders only the localized current error and clears busy', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		await search(h, parts, 'alpha', result('alpha', 'alpha-secret', 'receipt-alpha'));
		parts.input.value = 'beta';
		h.dispatch(parts.input, 'input');
		h.advanceTimers(250);
		await h.rejectFetch(1, new Error('socket detail 10.0.0.8'));
		assert(ERROR === parts.panel.textContent, 'network rejection did not render the fixed localized error');
		assert(!parts.panel.textContent.includes('socket detail'), 'network error detail entered panel text');
		assert('false' === parts.input.getAttribute('aria-busy'), 'network rejection left input busy');
		h.dispatch(parts.form, 'submit');
		assert(0 === h.beacons.length && null === h.sandbox.localStorage.getItem('ysss_recent'), 'network failure retained analytics/recent proof');
		assert(!parts.panel.textContent.includes('alpha-secret'), 'network failure retained A');
	}],
	['first zero result loads popular terms from a cold suggest cache', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = 'nothing';
		h.dispatch(parts.input, 'input');
		h.advanceTimers(250);
		await h.resolveJson(0, { q: 'nothing', total: 0, groups: [], view_all: '', log_receipt: 'receipt-zero' });
		assert(h.pendingFetches[1] && h.pendingFetches[1].url.endsWith('/suggest'), 'cold zero result did not load suggestions');
		await h.resolveJson(1, { items: [{ term: 'popular-nova' }] });
		assert(parts.panel.textContent.includes('popular-nova'), 'cold zero result did not append fallback chips');
	}],
	['late zero-result fallback cannot render after input changes again', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = 'nothing';
		h.dispatch(parts.input, 'input');
		h.advanceTimers(250);
		await h.resolveJson(0, { q: 'nothing', total: 0, groups: [], view_all: '', log_receipt: 'receipt-zero' });
		assert(h.pendingFetches[1] && h.pendingFetches[1].url.endsWith('/suggest'), 'cold zero result did not start fallback request');
		parts.input.value = 'next';
		h.dispatch(parts.input, 'input');
		await h.resolveJson(1, { items: [{ term: 'late-popular' }] });
		assert(!parts.panel.textContent.includes('late-popular'), 'late fallback entered a newer interaction');
	}],
	['aborted A is silent while current B renders successfully', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = 'alpha';
		h.dispatch(parts.input, 'input');
		h.advanceTimers(250);
		parts.input.value = 'beta';
		h.dispatch(parts.input, 'input');
		h.advanceTimers(250);
		assert(h.pendingFetches[0].options.signal && h.pendingFetches[0].options.signal.aborted, 'superseded A request was not aborted');
		const abortError = new Error('superseded');
		abortError.name = 'AbortError';
		await h.rejectFetch(0, abortError);
		await h.resolveJson(1, result('beta', 'beta-results', 'receipt-beta'));
		assert(parts.panel.textContent.includes('beta-results'), 'current B did not render after A abort');
		assert(!parts.panel.textContent.includes(ERROR), 'superseded AbortError rendered as a current error');
	}],
]);
