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
			q: 'alpha pro', total: 2, products_total: 2,
			groups: [{ type: 'products', label: 'newest-results', items: [{ title: 'Newest alpha', url: '/alpha-new' }], total: 2 }],
			view_all: '', log_receipt: 'receipt-alpha-new', recent_term: 'Alpha Pro',
		});
		assert(h.hasTimer(1200), 'settle timer was not scheduled after the newest signed response');
		await h.resolveJson(0, {
			q: 'alpha pro', total: 1, products_total: 1,
			groups: [{ type: 'products', label: 'stale-results', items: [{ title: 'Stale alpha', url: '/alpha-old' }], total: 1 }],
			view_all: '', log_receipt: 'receipt-alpha-old',
		});
		assert(panel.textContent.includes('newest-results'), 'older same-query response replaced the newest rendered results');
		assert(!panel.textContent.includes('stale-results'), 'stale same-query response entered rendered DOM');
		h.dispatch(form, 'submit');
		const recent = JSON.parse(h.sandbox.localStorage.getItem('ysss_recent_v2') || '[]');
		assert(1 === recent.length && 'Alpha Pro' === recent[0], 'positive result did not preserve exact displayed recent search');
		assert(1 === h.beacons.length && 'receipt-alpha-new' === (h.beaconPayload(0) || {}).receipt, 'older response replaced newest analytics proof');
		assert('receipt-alpha-new' === (form.querySelector('input[name="ys_ss_log_receipt"]') || {}).value, 'proved list submit did not carry its signed receipt to the destination');
	}],
	['zero result and receipt-less submit preserve v1.5.2 admission rules', async () => {
		const h = createHarness();
		const { form, input } = h.forms[0];
		h.sandbox.localStorage.setItem('ysss_recent_v2', JSON.stringify(['Alpha Pro']));
		input.value = 'human zero'; h.dispatch(input, 'input'); h.advanceTimers(250);
		await h.resolveJson(0, { q: 'human zero', total: 0, products_total: 0, groups: [], view_all: '', log_receipt: 'receipt-zero' });
		h.dispatch(form, 'submit');
		let recent = JSON.parse(h.sandbox.localStorage.getItem('ysss_recent_v2') || '[]');
		assert(1 === recent.length && 'Alpha Pro' === recent[0], 'zero-result search polluted browser recent history');
		assert(1 === h.beacons.length && 'receipt-zero' === (h.beaconPayload(0) || {}).receipt, 'human zero-result analytics was not preserved');
		input.value = 'quick request'; h.dispatch(input, 'input'); h.dispatch(form, 'submit');
		recent = JSON.parse(h.sandbox.localStorage.getItem('ysss_recent_v2') || '[]');
		assert(1 === recent.length && 'Alpha Pro' === recent[0], 'receipt-less quick submit polluted browser recent history');
		assert(1 === h.beacons.length, 'receipt-less quick submit sent analytics');
		assert(!form.querySelector('input[name="ys_ss_log_receipt"]'), 'receipt-less quick submit carried stale receipt authority');
	}],
	['beacon rejection still carries the signed receipt to server fallback', async () => {
		const h = createHarness({ sendBeaconResult: false });
		const { form, input } = h.forms[0];
		input.value = 'server fallback'; h.dispatch(input, 'input'); h.advanceTimers(250);
		await h.resolveJson(0, {
			q: 'server fallback', total: 1, products_total: 1,
			groups: [{ type: 'products', label: 'results', items: [{ title: 'Fallback', url: '/fallback' }], total: 1 }],
			view_all: '', log_receipt: 'receipt-server-fallback', recent_term: 'server fallback',
		});
		h.dispatch(form, 'submit');
		assert(0 === h.beacons.length, 'rejected beacon was treated as queued');
		assert('receipt-server-fallback' === (form.querySelector('input[name="ys_ss_log_receipt"]') || {}).value, 'beacon rejection suppressed the signed server fallback');
	}],
	['category-only proof logs analytics but never enters recent history', async () => {
		const h = createHarness();
		const { form, input } = h.forms[0];
		h.sandbox.localStorage.setItem('ysss_recent_v2', JSON.stringify(['previous product']));
		input.value = 'category only'; h.dispatch(input, 'input'); h.advanceTimers(250);
		await h.resolveJson(0, {
			q: 'category only', total: 4, products_total: 0,
			groups: [{ type: 'categories', label: 'category-results', items: [{ title: 'Category', url: '/category' }], total: 4 }],
			view_all: '', log_receipt: 'receipt-category-zero-product',
		});
		h.dispatch(form, 'submit');
		const recent = JSON.parse(h.sandbox.localStorage.getItem('ysss_recent_v2') || '[]');
		assert(1 === recent.length && 'previous product' === recent[0], 'aggregate category total incorrectly authorized recent history');
		assert(1 === h.beacons.length, 'zero-product recognizable query lost its analytics path');
		assert('receipt-category-zero-product' === (h.beaconPayload(0) || {}).receipt, 'zero-product analytics did not use its matching receipt');
	}],
	['page-mode view-all leaves analytics ownership to the destination page', async () => {
		const h = createHarness({ cfg: { resultsMode: 'page' } });
		const { input, panel } = h.forms[0];
		input.value = 'page owned'; h.dispatch(input, 'input'); h.advanceTimers(250);
		await h.resolveJson(0, {
			q: 'page owned', total: 1, products_total: 1,
			groups: [{ type: 'products', label: 'results', items: [{ title: 'Visible', url: '/product/visible' }], total: 1 }],
			view_all: '/ys-search/?ys_ec_search=page%20owned',
			log_receipt: 'receipt-page-owned',
		});
		const viewAll = panel.querySelector('.ys-ss-viewall');
		assert(!!viewAll, 'Page-mode response did not render view-all navigation');
		h.advanceTimers(1200);
		assert(0 === h.beacons.length, 'Page-mode settle timer consumed analytics before destination ownership');
		viewAll.click();
		assert(0 === h.beacons.length, 'Page-mode view-all sent client analytics before destination page logging');
	}],
	['malformed product totals fail closed without aggregate fallback', async () => {
		const cases = [
			['missing', undefined, true],
			['negative', -3, false],
			['nonnumeric', 'many', false],
			['infinite', Infinity, false],
			['not-a-number', NaN, false],
			['boolean-true', true, false],
			['array-one', [1], false],
			['numeric-string', '1', false],
			['object', {}, false],
			['fractional', 1.5, false],
		];
		for (const [label, productsTotal, omit] of cases) {
			const h = createHarness();
			const { form, input } = h.forms[0];
			input.value = 'aggregate ' + label; h.dispatch(input, 'input'); h.advanceTimers(250);
			const response = {
				q: 'aggregate ' + label,
				total: 9,
				groups: [{ type: 'products', label: 'results', items: [{ title: 'Visible', url: '/visible' }], total: 9 }],
				view_all: '',
				log_receipt: 'receipt-' + label,
			};
			if (!omit) { response.products_total = productsTotal; }
			await h.resolveJson(0, response);
			h.dispatch(form, 'submit');
			assert(null === h.sandbox.localStorage.getItem('ysss_recent_v2'), label + ' products_total authorized recent history');
			assert(1 === h.beacons.length && 'receipt-' + label === (h.beaconPayload(0) || {}).receipt, label + ' products_total blocked valid analytics');
		}
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
			q: '諾瓦', total: 1, products_total: 1,
			groups: [{ type: 'products', label: 'ime-results', items: [{ title: '諾瓦', url: '/nova-ime' }], total: 1 }],
			view_all: '', log_receipt: 'receipt-ime',
		});
		assert(panel.textContent.includes('ime-results'), 'IME-completed query did not render its result');
	}],
	['analytics sends exact ingress while recent memory uses only the server-approved term', async () => {
		const h = createHarness();
		const { form, input } = h.forms[0];
		const ingress = 'Nova Mixed Case ' + 'x'.repeat(110);
		input.value = ingress; h.dispatch(input, 'input'); h.advanceTimers(250);
		await h.resolveJson(0, {
			q: 'nova mixed case ' + 'x'.repeat(84), total: 1, products_total: 1,
			groups: [{ type: 'products', label: 'results', items: [{ title: 'Visible', url: '/visible' }], total: 1 }],
			view_all: '', log_receipt: 'receipt-ingress-v2', recent_term: 'Nova Mixed Case Product',
		});
		h.dispatch(form, 'submit');
		const payload = h.beaconPayload(0) || {};
		assert(ingress === payload.ingress, 'Analytics did not carry the exact accepted ingress');
		assert('nova mixed case ' + 'x'.repeat(84) === payload.q, 'Analytics did not carry the server canonical query');
		const recent = JSON.parse(h.sandbox.localStorage.getItem('ysss_recent_v2') || '[]');
		assert(1 === recent.length && 'Nova Mixed Case Product' === recent[0], 'Recent memory did not use the server-approved term');
		assert(!JSON.stringify(recent).includes(ingress), 'Exact ingress leaked into persistent recent storage');
	}],
	['result item and list-mode view-all analytics both carry exact ingress', async () => {
		for (const target of ['.ys-ss-item', '.ys-ss-viewall']) {
			const h = createHarness();
			const { input, panel } = h.forms[0];
			const ingress = 'Exact Mixed Input ' + target;
			input.value = ingress; h.dispatch(input, 'input'); h.advanceTimers(250);
			await h.resolveJson(0, {
				q: 'exact mixed canonical', total: 1, products_total: 1,
				groups: [{ type: 'products', label: 'results', items: [{ title: 'Visible', url: '/visible' }], total: 1 }],
				view_all: '/shop/?q=exact', log_receipt: 'receipt-click', recent_term: 'Exact Mixed Input',
			});
			panel.querySelector(target).click();
			const payload = h.beaconPayload(0) || {};
			assert(ingress === payload.ingress && 'exact mixed canonical' === payload.q, target + ' lost exact ingress or canonical q');
		}
	}],
	['page-mode direct result click carries ingress while view-all remains page-owned', async () => {
		const h = createHarness({ cfg: { resultsMode: 'page' } });
		const { input, panel } = h.forms[0];
		const ingress = 'Page Exact Input';
		input.value = ingress; h.dispatch(input, 'input'); h.advanceTimers(250);
		await h.resolveJson(0, {
			q: 'page exact input', total: 1, products_total: 1,
			groups: [{ type: 'products', label: 'results', items: [{ title: 'Visible', url: '/visible' }], total: 1 }],
			view_all: '/ys-search/?q=page', log_receipt: 'receipt-page-item', recent_term: 'Page Exact Input',
		});
		panel.querySelector('.ys-ss-item').click();
		assert(ingress === (h.beaconPayload(0) || {}).ingress, 'Page-mode result item lost exact ingress');
		panel.querySelector('.ys-ss-viewall').click();
		assert(1 === h.beacons.length, 'Page-mode view-all duplicated client analytics');
	}],
	['missing or malformed receipt preserves navigation but grants no analytics or recent memory', async () => {
		for (const receipt of ['', { token: 'not-a-string' }]) {
			const h = createHarness();
			const { form, input, panel } = h.forms[0];
			input.value = 'Receiptless Product'; h.dispatch(input, 'input'); h.advanceTimers(250);
			await h.resolveJson(0, {
				q: 'receiptless product', total: 1, products_total: 1,
				groups: [{ type: 'products', label: 'results', items: [{ title: 'Visible receiptless product', url: '/visible' }], total: 1 }],
				view_all: '/shop/?q=receiptless', log_receipt: receipt, recent_term: 'Receiptless Product',
			});
			assert(panel.textContent.includes('Visible receiptless product'), 'Receipt shape suppressed a safe search result');
			assert(!!panel.querySelector('.ys-ss-item') && !!panel.querySelector('.ys-ss-viewall'), 'Receipt shape removed safe navigation');
			h.dispatch(form, 'submit');
			panel.querySelector('.ys-ss-item').click();
			panel.querySelector('.ys-ss-viewall').click();
			h.advanceTimers(1200);
			assert(0 === h.beacons.length, 'Missing or malformed receipt authorized analytics');
			assert(null === h.sandbox.localStorage.getItem('ysss_recent_v2'), 'Missing or malformed receipt authorized recent memory');
		}
	}],
]);
