'use strict';

const { createHarness, assert, runTests } = require('../support/front-js-harness');

runTests([
	['newest same-query receipt remains authoritative', async () => {
		const h = createHarness();
		const { form, input, panel } = h.forms[0];
		input.value = 'Alpha Pro'; h.dispatch(input, 'input'); h.advanceTimers(250);
		input.value = 'beta'; h.dispatch(input, 'input'); h.advanceTimers(250);
		input.value = 'Alpha Pro'; h.dispatch(input, 'input'); h.advanceTimers(250);
		await h.resolveJson(2, {
			q: 'alpha pro', total: 2,
			groups: [{ type: 'products', label: 'newest-results', items: [], total: 2 }],
			view_all: '', log_receipt: 'receipt-alpha-new',
		});
		assert(h.hasTimer(1200), 'settle timer was not scheduled after the newest signed response');
		await h.resolveJson(0, {
			q: 'alpha pro', total: 1,
			groups: [{ type: 'products', label: 'stale-results', items: [], total: 1 }],
			view_all: '', log_receipt: 'receipt-alpha-old',
		});
		assert(panel.textContent.includes('newest-results'), 'older same-query response replaced the newest rendered results');
		assert(!panel.textContent.includes('stale-results'), 'stale same-query response entered rendered DOM');
		h.dispatch(form, 'submit');
		const recent = JSON.parse(h.sandbox.localStorage.getItem('ysss_recent') || '[]');
		assert(1 === recent.length && 'Alpha Pro' === recent[0], 'positive result did not preserve exact displayed recent search');
		assert(1 === h.beacons.length && 'receipt-alpha-new' === (h.beaconPayload(0) || {}).receipt, 'older response replaced newest analytics proof');
	}],
	['zero result and receipt-less submit preserve v1.5.2 admission rules', async () => {
		const h = createHarness();
		const { form, input } = h.forms[0];
		h.sandbox.localStorage.setItem('ysss_recent', JSON.stringify(['Alpha Pro']));
		input.value = 'human zero'; h.dispatch(input, 'input'); h.advanceTimers(250);
		await h.resolveJson(0, { q: 'human zero', total: 0, groups: [], view_all: '', log_receipt: 'receipt-zero' });
		h.dispatch(form, 'submit');
		let recent = JSON.parse(h.sandbox.localStorage.getItem('ysss_recent') || '[]');
		assert(1 === recent.length && 'Alpha Pro' === recent[0], 'zero-result search polluted browser recent history');
		assert(1 === h.beacons.length && 'receipt-zero' === (h.beaconPayload(0) || {}).receipt, 'human zero-result analytics was not preserved');
		input.value = 'quick request'; h.dispatch(input, 'input'); h.dispatch(form, 'submit');
		recent = JSON.parse(h.sandbox.localStorage.getItem('ysss_recent') || '[]');
		assert(1 === recent.length && 'Alpha Pro' === recent[0], 'receipt-less quick submit polluted browser recent history');
		assert(1 === h.beacons.length, 'receipt-less quick submit sent analytics');
	}],
	['IME composition preserves the v1.5.2 debounce guard', async () => {
		const h = createHarness();
		const { input, panel } = h.forms[0];
		input.value = '諾';
		h.dispatch(input, 'compositionstart');
		h.dispatch(input, 'input');
		h.advanceTimers(250);
		assert(0 === h.pendingFetches.length, 'IME composition issued an intermediate query');
		input.value = '諾瓦';
		h.dispatch(input, 'input');
		h.dispatch(input, 'compositionend');
		h.advanceTimers(249);
		assert(0 === h.pendingFetches.length, 'IME completion bypassed the 250ms debounce');
		h.advanceTimers(1);
		assert(1 === h.pendingFetches.length, 'IME completion did not issue exactly one query');
		await h.resolveJson(0, {
			q: '諾瓦', total: 1,
			groups: [{ type: 'products', label: 'ime-results', items: [{ title: '諾瓦', url: '/nova-ime' }], total: 1 }],
			view_all: '', log_receipt: 'receipt-ime',
		});
		assert(panel.textContent.includes('ime-results'), 'IME-completed query did not render its result');
	}],
]);
