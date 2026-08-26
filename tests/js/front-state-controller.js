'use strict';

const { createHarness, assert, runTests } = require('../support/front-js-harness');

const ERROR = '搜尋暫時無法使用，請稍後再試。';

function result(query, label, receipt, items = [{ title: label, url: '/product/' + query }]) {
	return {
		q: query,
		total: items.length,
		products_total: items.length,
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

function assertBusy(parts, expected, context) {
	assert(expected === parts.input.getAttribute('aria-busy'), context + ' input busy state mismatch');
	assert(expected === parts.panel.getAttribute('aria-busy'), context + ' panel busy state mismatch');
}

async function chipFixture(kind) {
	const h = createHarness();
	const parts = h.forms[0];
	let term;

	if ('popular' === kind) {
		term = 'popular-chip';
		parts.input.focus();
		await h.resolveJson(0, { items: [{ term }] });
	} else if ('recent' === kind) {
		term = 'recent-chip';
		h.sandbox.localStorage.setItem('ysss_recent_v2', JSON.stringify([term]));
		parts.input.focus();
		await h.resolveJson(0, { items: [] });
	} else {
		term = 'fallback-chip';
		parts.input.value = 'zero-origin';
		parts.input.focus();
		await h.resolveJson(0, {
			q: 'zero-origin', total: 0, products_total: 0, groups: [], view_all: '', log_receipt: 'receipt-zero-origin',
		});
		await h.resolveJson(1, { items: [{ term }] });
	}

	const chip = Array.from(parts.panel.querySelectorAll('.ys-ss-chip')).find((item) => item.textContent === term);
	assert(!!chip, kind + ' fixture did not render its chip');
	return { h, parts, chip, term };
}

async function assertChipActivation(kind, activation) {
	const { h, parts, chip, term } = await chipFixture(kind);
	if ('keyboard' === activation) {
		h.dispatch(parts.input, 'keydown', { key: 'ArrowDown' });
		h.dispatch(parts.input, 'keydown', { key: 'Enter' });
	} else {
		chip.focus();
		chip.click();
	}

	const expectedUrl = '/query?q=' + encodeURIComponent(term);
	const matching = h.pendingFetches.filter((request) => request.url.endsWith(expectedUrl));
	assert(1 === matching.length, kind + ' ' + activation + ' activation issued more than one query');
	assert(!matching[0].options.signal || !matching[0].options.signal.aborted, kind + ' ' + activation + ' activation was reclassified as outside click');
	assertBusy(parts, 'true', kind + ' ' + activation + ' active query');

	h.document.body.click();
	assert(matching[0].options.signal && matching[0].options.signal.aborted, kind + ' ' + activation + ' true outside click did not close the active query');
	assertBusy(parts, 'false', kind + ' ' + activation + ' closed query');
}

runTests([
	['popular chip mouse activation issues one live query and true outside click still closes', async () => {
		await assertChipActivation('popular', 'mouse');
	}],
	['popular chip keyboard activation issues one live query and true outside click still closes', async () => {
		await assertChipActivation('popular', 'keyboard');
	}],
	['recent chip mouse activation issues one live query and true outside click still closes', async () => {
		await assertChipActivation('recent', 'mouse');
	}],
	['recent chip keyboard activation issues one live query and true outside click still closes', async () => {
		await assertChipActivation('recent', 'keyboard');
	}],
	['zero fallback chip mouse activation issues one live query and true outside click still closes', async () => {
		await assertChipActivation('fallback', 'mouse');
	}],
	['zero fallback chip keyboard activation issues one live query and true outside click still closes', async () => {
		await assertChipActivation('fallback', 'keyboard');
	}],
	['chip activation protects only its owner while closing another open form', async () => {
		const h = createHarness({ formCount: 2 });
		const first = h.forms[0];
		const second = h.forms[1];
		first.input.focus();
		await h.resolveJson(0, { items: [{ term: 'owner-chip' }] });
		second.input.focus();
		assert(!first.panel.hidden && !second.panel.hidden, 'two-form fixture did not open both panels');
		const chip = first.panel.querySelector('.ys-ss-chip');
		chip.focus();
		chip.click();
		const ownerQueries = h.pendingFetches.filter((request) => request.url.endsWith('/query?q=owner-chip'));
		assert(1 === ownerQueries.length && !ownerQueries[0].options.signal.aborted, 'owner activation lost its live query');
		assert(!first.panel.hidden, 'owner activation closed its own panel');
		assert(second.panel.hidden, 'owner activation did not close the other form as a true outside click');
	}],
	['blocked-neutral query stays fixed empty without fallback or side effects', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = 'blocked-payload';
		parts.input.focus();
		await h.resolveJson(0, { q: '', total: 0, products_total: 0, groups: [], view_all: '', log_receipt: '' });
		assert(1 === h.pendingFetches.length, 'blocked-neutral response requested optional suggestions');
		assert('找不到符合的結果，試試其他關鍵字：' === parts.panel.textContent, 'blocked-neutral response did not render fixed neutral empty');
		assert(null === parts.form._ysSsLogProof, 'blocked-neutral response created a proof');
		assertBusy(parts, 'false', 'blocked-neutral settled response');
		h.dispatch(parts.form, 'submit');
		h.advanceTimers(1200);
		assert(0 === h.beacons.length, 'blocked-neutral response sent analytics');
		assert(null === h.sandbox.localStorage.getItem('ysss_recent_v2'), 'blocked-neutral response wrote recent history');
	}],
	['non-scalar q cannot authorize zero fallback proof', async () => {
		const fixtures = [
			{ q: ['not-a-query'], log_receipt: 'receipt-array-q' },
		];
		for (const fixture of fixtures) {
			const h = createHarness();
			const parts = h.forms[0];
			parts.input.value = 'malformed-proof';
			parts.input.focus();
			await h.resolveJson(0, { ...fixture, total: 0, products_total: 0, groups: [], view_all: '' });
			assert(1 === h.pendingFetches.length, 'malformed proof requested optional suggestions');
			assert(null === parts.form._ysSsLogProof, 'malformed proof entered controller authority');
			assert('找不到符合的結果，試試其他關鍵字：' === parts.panel.textContent, 'malformed proof did not settle neutral empty');
		}
	}],
	['cold zero fallback failure preserves empty proof and settles zero analytics without recent', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = 'nothing';
		parts.input.focus();
		assertBusy(parts, 'true', 'active zero query');
		await h.resolveJson(0, { q: 'nothing', total: 0, products_total: 0, groups: [], view_all: '', log_receipt: 'receipt-zero' });
		assert(h.pendingFetches[1] && h.pendingFetches[1].url.endsWith('/suggest'), 'valid zero result did not request optional fallback');
		assertBusy(parts, 'true', 'cold zero fallback');
		await h.rejectFetch(1, new Error('fallback unavailable'));
		assert('找不到符合的結果，試試其他關鍵字：' === parts.panel.textContent, 'fallback failure replaced fixed no-results UI');
		assert(parts.form._ysSsLogProof && 'receipt-zero' === parts.form._ysSsLogProof.receipt, 'fallback failure revoked valid zero proof');
		assertBusy(parts, 'false', 'failed zero fallback settlement');
		h.advanceTimers(1200);
		assert(1 === h.beacons.length, 'valid zero-result settle did not send analytics after fallback failure');
		assert('nothing' === h.beaconPayload(0).q && 'nothing' === h.beaconPayload(0).ingress && 'receipt-zero' === h.beaconPayload(0).receipt, 'zero-result analytics lost its proof identity');
		assert(null === h.sandbox.localStorage.getItem('ysss_recent_v2'), 'zero-result settle wrote recent history');
	}],
	['cold zero fallback success clears busy and settles zero analytics without recent', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = 'nothing';
		parts.input.focus();
		await h.resolveJson(0, { q: 'nothing', total: 0, products_total: 0, groups: [], view_all: '', log_receipt: 'receipt-zero' });
		assertBusy(parts, 'true', 'cold successful zero fallback');
		await h.resolveJson(1, { items: [{ term: 'popular-nova' }] });
		assert(parts.panel.textContent.includes('popular-nova'), 'successful fallback did not append its chip');
		assertBusy(parts, 'false', 'successful zero fallback settlement');
		h.advanceTimers(1200);
		assert(1 === h.beacons.length, 'valid zero-result settle did not send analytics after fallback success');
		assert(null === h.sandbox.localStorage.getItem('ysss_recent_v2'), 'successful zero-result fallback wrote recent history');
	}],
	['query and cold suggestion expose synchronized busy authority', async () => {
		const queryHarness = createHarness();
		const queryParts = queryHarness.forms[0];
		queryParts.input.value = 'nova';
		queryParts.input.focus();
		assertBusy(queryParts, 'true', 'current query');
		await queryHarness.resolveJson(0, result('nova', 'nova-results', 'receipt-nova'));
		assertBusy(queryParts, 'false', 'settled query');

		const suggestHarness = createHarness();
		const suggestParts = suggestHarness.forms[0];
		suggestParts.input.focus();
		assertBusy(suggestParts, 'true', 'cold suggestion');
		await suggestHarness.resolveJson(0, { items: [{ term: 'popular-nova' }] });
		assertBusy(suggestParts, 'false', 'settled suggestion');
	}],
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
		assert('[]' === (h.sandbox.localStorage.getItem('ysss_recent_v2') || '[]'), 'A proof wrote recent history for B');
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
		assert(0 === h.beacons.length && null === h.sandbox.localStorage.getItem('ysss_recent_v2'), 'HTTP failure retained analytics/recent proof');
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
		assert(0 === h.beacons.length && null === h.sandbox.localStorage.getItem('ysss_recent_v2'), 'invalid JSON retained analytics/recent proof');
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
		assert(0 === h.beacons.length && null === h.sandbox.localStorage.getItem('ysss_recent_v2'), 'network failure retained analytics/recent proof');
		assert(!parts.panel.textContent.includes('alpha-secret'), 'network failure retained A');
	}],
	['first zero result loads popular terms from a cold suggest cache', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = 'nothing';
		h.dispatch(parts.input, 'input');
		h.advanceTimers(250);
		await h.resolveJson(0, { q: 'nothing', total: 0, products_total: 0, groups: [], view_all: '', log_receipt: 'receipt-zero' });
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
		await h.resolveJson(0, { q: 'nothing', total: 0, products_total: 0, groups: [], view_all: '', log_receipt: 'receipt-zero' });
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
	['Escape revokes a pending shared suggestion render authority', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = '';
		h.dispatch(parts.input, 'focus');
		assert(1 === h.pendingFetches.length, 'pending suggest fixture did not start');
		h.dispatch(parts.input, 'keydown', { key: 'Escape' });
		await h.resolveJson(0, { items: [{ term: 'late-after-escape' }] });
		assert(parts.panel.hidden, 'late suggestion reopened the panel after Escape');
		assert(!parts.panel.textContent.includes('late-after-escape'), 'late suggestion rendered after Escape');
		assert('false' === parts.input.getAttribute('aria-expanded'), 'late suggestion re-expanded the combobox after Escape');
	}],
	['click outside revokes a pending query response render authority', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = 'outside';
		h.dispatch(parts.input, 'input');
		h.advanceTimers(250);
		assert(1 === h.pendingFetches.length, 'pending query fixture did not start');
		h.document.body.click();
		await h.resolveJson(0, result('outside', 'late-outside-results', 'receipt-outside'));
		assert(parts.panel.hidden, 'late query success reopened the panel after click-outside');
		assert(!parts.panel.textContent.includes('late-outside-results'), 'late query success rendered after click-outside');
	}],
	['click outside cancels query authority before the debounce fires', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = 'debounced';
		h.dispatch(parts.input, 'input');
		h.document.body.click();
		h.advanceTimers(250);
		assert(0 === h.pendingFetches.length, 'closed interaction issued its debounced query');
		assert(parts.panel.hidden, 'debounced query reopened a closed panel');
	}],
	['late current-shaped network error cannot reopen after click outside', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = 'late-error';
		h.dispatch(parts.input, 'input');
		h.advanceTimers(250);
		h.document.body.click();
		await h.rejectFetch(0, new Error('private late network detail'));
		assert(parts.panel.hidden, 'late query error reopened the panel after close');
		assert(!parts.panel.textContent.includes(ERROR), 'late query error rendered localized error after close');
		assert(!parts.panel.textContent.includes('private late network detail'), 'late network detail entered the closed panel');
	}],
	['Escape revokes settled proof and its pending settle timer', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		await search(h, parts, 'proof', result('proof', 'proof-results', 'receipt-proof'));
		h.dispatch(parts.input, 'keydown', { key: 'Escape' });
		h.dispatch(parts.form, 'submit');
		h.advanceTimers(1200);
		assert(0 === h.beacons.length, 'Escape left settled analytics proof or timer active');
		assert(null === h.sandbox.localStorage.getItem('ysss_recent_v2'), 'Escape left settled recent-history proof active');
	}],
	['current suggestion HTTP 500 renders the fixed safe error', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = '';
		h.dispatch(parts.input, 'focus');
		await h.resolveJson(0, { message: 'private suggest server detail' }, 500);
		assert(ERROR === parts.panel.textContent, 'suggest HTTP 500 did not render the fixed localized error');
		assert(!parts.panel.textContent.includes('private suggest server detail'), 'suggest HTTP detail entered panel text');
		assert('false' === parts.input.getAttribute('aria-busy'), 'suggest HTTP failure left combobox busy');
		assert(0 === h.beacons.length && null === h.sandbox.localStorage.getItem('ysss_recent_v2'), 'suggest HTTP failure retained proof side effects');
	}],
	['current suggestion invalid JSON renders the fixed safe error', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = '';
		h.dispatch(parts.input, 'focus');
		await h.resolveInvalidJson(0, 'private suggest parser detail');
		assert(ERROR === parts.panel.textContent, 'suggest invalid JSON did not render the fixed localized error');
		assert(!parts.panel.textContent.includes('private suggest parser detail'), 'suggest parser detail entered panel text');
		assert('false' === parts.input.getAttribute('aria-busy'), 'suggest invalid JSON left combobox busy');
	}],
	['current suggestion network rejection renders the fixed safe error', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = '';
		h.dispatch(parts.input, 'focus');
		await h.rejectFetch(0, new Error('private suggest socket detail'));
		assert(ERROR === parts.panel.textContent, 'suggest network rejection did not render the fixed localized error');
		assert(!parts.panel.textContent.includes('private suggest socket detail'), 'suggest network detail entered panel text');
		assert('false' === parts.input.getAttribute('aria-busy'), 'suggest network rejection left combobox busy');
	}],
	['failed suggestion load is cleared so a later focus can retry successfully', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = '';
		h.dispatch(parts.input, 'focus');
		await h.rejectFetch(0, new Error('first attempt fails'));
		h.dispatch(parts.input, 'focus');
		assert(2 === h.pendingFetches.length, 'later focus reused the permanently failed suggestion Promise');
		await h.resolveJson(1, { items: [{ term: 'retry-popular' }] });
		assert(parts.panel.textContent.includes('retry-popular'), 'successful suggestion retry did not render');
		assert(!parts.panel.textContent.includes(ERROR), 'successful suggestion retry retained the earlier error');
	}],
	['superseded suggestion failure cannot replace a current query success', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		parts.input.value = '';
		h.dispatch(parts.input, 'focus');
		parts.input.value = 'nova';
		h.dispatch(parts.input, 'input');
		h.advanceTimers(250);
		await h.rejectFetch(0, new Error('superseded suggest failure'));
		await h.resolveJson(1, result('nova', 'nova-after-suggest-failure', 'receipt-nova'));
		assert(parts.panel.textContent.includes('nova-after-suggest-failure'), 'current query did not survive superseded suggestion failure');
		assert(!parts.panel.textContent.includes(ERROR), 'superseded suggestion failure rendered as current error');
	}],
	['malformed recent storage is bounded ignored and leaves one safe chip interactive', async () => {
		const h = createHarness();
		const parts = h.forms[0];
		h.sandbox.localStorage.setItem('ysss_recent', JSON.stringify([null, 'legacy-unverified']));
		h.sandbox.localStorage.setItem('ysss_recent_v2', JSON.stringify([
			null, { term: 'forged-object' }, 7, '', '\uD800', 'recent-safe', 'recent-safe', 'x'.repeat(101)
		]));
		parts.input.focus();
		await h.resolveJson(0, { items: [] });
		assert(!parts.panel.textContent.includes(ERROR), 'Malformed recent storage forced the suggestion panel into error mode');
		assert(parts.panel.textContent.includes('recent-safe'), 'The valid bounded recent term was lost');
		assert(!parts.panel.textContent.includes('legacy-unverified'), 'Unverifiable v1 recent storage was migrated');
		assert(!parts.panel.textContent.includes('forged-object'), 'Malformed object member reached a chip');
		const chips = parts.panel.querySelectorAll('.ys-ss-chip--recent');
		assert(1 === chips.length, 'Malformed or duplicate recent entries were not filtered to one chip');
		chips[0].click();
		const requests = h.pendingFetches.filter((request) => request.url.endsWith('/query?q=recent-safe'));
		assert(1 === requests.length, 'The safe recent chip did not issue exactly one query');
	}],
]);
