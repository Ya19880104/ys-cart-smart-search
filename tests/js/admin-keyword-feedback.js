'use strict';

const {
	createAdminHarness,
	deferred,
	findNode,
	flush,
	response,
} = require('../support/admin-js-harness');

const WARNING = '資料已更新，但熱門建議快取可能延遲更新。';
const initialItems = [{ id: 1, keyword: 'Alpha', sort_order: 0, is_active: true }];

function keywordControl(harness, className) {
	return findNode(harness.keywordBody, (node) => className === node.className);
}

function jsonResponse(ok, payload) {
	return { ok, json: () => Promise.resolve(payload) };
}

async function testQueueRecovery() {
	const first = deferred();
	const second = deferred();
	const third = deferred();
	const pending = [first, second, third];
	const keywordFetches = [];
	const harness = createAdminHarness({
		settings: true,
		initialKeywords: initialItems,
		fetch(url, options) {
			if (!url.includes('/keywords/1')) { throw new Error('unexpected fetch: ' + url); }
			keywordFetches.push({ url, options });
			return pending[keywordFetches.length - 1].promise;
		},
	});

	let edit = keywordControl(harness, 'ys-ss-kw-edit');
	let sort = keywordControl(harness, 'ys-ss-kw-sort');
	edit.value = 'Alpha client';
	edit.dispatch('change');
	if (!edit.disabled) { throw new Error('keyword control was not disabled at enqueue time'); }
	edit.dispatch('change');
	sort.value = '5';
	sort.dispatch('change');
	if (!sort.disabled) { throw new Error('queued keyword control was not disabled at enqueue time'); }
	await flush();
	if (1 !== keywordFetches.length) { throw new Error('duplicate activation sent a second request or bypassed serialization'); }

	first.resolve(jsonResponse(true, {
		items: [{ id: 1, keyword: 'Alpha confirmed', sort_order: 1, is_active: true }],
		cache_status: 'rotated',
	}));
	await flush();
	if (2 !== keywordFetches.length) { throw new Error('newer queued mutation did not begin after older completion'); }
	if ('Alpha confirmed' === keywordControl(harness, 'ys-ss-kw-edit').value) {
		throw new Error('older response redrew over a newer queued operation');
	}

	second.reject(new Error('SECRET queue rejection'));
	await flush();
	edit = keywordControl(harness, 'ys-ss-kw-edit');
	sort = keywordControl(harness, 'ys-ss-kw-sort');
	if ('Alpha confirmed' !== edit.value || '1' !== String(sort.value)) {
		throw new Error('newer failure did not restore the earlier server-confirmed snapshot');
	}
	if (harness.ids.get('ys-ss-save-msg').textContent.includes('SECRET')
		|| '關鍵字更新失敗，請稍後再試。' !== harness.ids.get('ys-ss-save-msg').textContent) {
		throw new Error('queue rejection did not stay on fixed local feedback');
	}

	const toggle = findNode(harness.keywordBody, (node) => 'checkbox' === node.type);
	toggle.checked = false;
	toggle.dispatch('change');
	await flush();
	if (3 !== keywordFetches.length) { throw new Error('a rejected mutation permanently blocked the queue'); }
	third.resolve(jsonResponse(true, {
		items: [{ id: 1, keyword: 'Alpha confirmed', sort_order: 1, is_active: false }],
		cache_status: 'bypass_fresh',
	}));
	await flush();
	const confirmedToggle = findNode(harness.keywordBody, (node) => 'checkbox' === node.type);
	if (confirmedToggle.checked) { throw new Error('queue recovery success did not own final render'); }
}

async function testInvalidResponsesRestore() {
	const cases = [
		() => response(false, { code: 'ys_ss_keyword_write_failed', message: 'SECRET SQL HTTP' }),
		() => response(true, { items: 'SECRET SQL not array', cache_status: 'rotated' }),
		() => response(true, { items: [{ id: 'bad', keyword: 42, sort_order: 'x', is_active: 'yes' }], cache_status: 'rotated' }),
		() => Promise.reject(new Error('SECRET NETWORK')),
	];

	for (const makeResponse of cases) {
		const harness = createAdminHarness({
			settings: true,
			initialKeywords: initialItems,
			fetch() { return makeResponse(); },
		});
		const add = harness.ids.get('ys-ss-kw-add');
		const input = harness.ids.get('ys-ss-kw-input');
		input.value = 'C++ <vector> 入門';
		add.dispatch('click');
		if (!add.disabled) { throw new Error('add control did not disable at enqueue'); }
		await flush();
		if (add.disabled) { throw new Error('failed add did not re-enable its control'); }
		if ('C++ <vector> 入門' !== input.value) { throw new Error('failed add did not restore its entered bytes'); }
		if ('Alpha' !== keywordControl(harness, 'ys-ss-kw-edit').value) {
			throw new Error('failed or invalid response replaced authoritative keyword items');
		}
		const message = harness.ids.get('ys-ss-save-msg').textContent;
		if ('關鍵字更新失敗，請稍後再試。' !== message || message.includes('SECRET')) {
			throw new Error('failed or invalid response rendered server detail');
		}
	}
}

async function testAllSettingsOperationsAndWarnings() {
	let items = initialItems;
	const requests = [];
	const harness = createAdminHarness({
		settings: true,
		initialKeywords: initialItems,
		fetch(url, options) {
			requests.push({ url, options });
			const body = options.body ? JSON.parse(options.body) : {};
			if (url.endsWith('/keywords')) {
				items = [...items, { id: 2, keyword: body.keyword, sort_order: 0, is_active: true }];
			} else if ('DELETE' === options.method) {
				items = items.filter((item) => 2 !== item.id);
			} else if (Object.prototype.hasOwnProperty.call(body, 'keyword')) {
				items = items.map((item) => 2 === item.id ? { ...item, keyword: body.keyword } : item);
			} else if (Object.prototype.hasOwnProperty.call(body, 'sort_order')) {
				items = items.map((item) => 2 === item.id ? { ...item, sort_order: body.sort_order } : item);
			} else if (Object.prototype.hasOwnProperty.call(body, 'is_active')) {
				items = items.map((item) => 2 === item.id ? { ...item, is_active: body.is_active } : item);
			}
			return response(true, {
				items,
				cache_status: 1 === requests.length ? 'failed' : (2 === requests.length ? 'bypass_fresh' : 'rotated'),
				cache_warning: 'SECRET SQL WARNING',
			});
		},
	});

	const add = harness.ids.get('ys-ss-kw-add');
	const input = harness.ids.get('ys-ss-kw-input');
	input.value = 'C++ <vector> 入門';
	add.dispatch('click');
	await flush();
	if ('' !== input.value) { throw new Error('successful add did not clear its input'); }
	const renderedTerms = () => harness.keywordBody.children
		.map((row) => findNode(row, (node) => 'ys-ss-kw-edit' === node.className))
		.filter(Boolean)
		.map((node) => node.value);
	if (!renderedTerms().includes('C++ <vector> 入門')) { throw new Error('add success did not render exact technical bytes'); }
	let message = harness.ids.get('ys-ss-save-msg').textContent;
	if (!message.includes(WARNING) || message.includes('SECRET')) {
		throw new Error('failed cache status did not map to the fixed local warning');
	}

	let rows = harness.keywordBody.children;
	let secondRow = rows.find((row) => {
		const field = findNode(row, (node) => 'ys-ss-kw-edit' === node.className);
		return field && 'C++ <vector> 入門' === field.value;
	});
	let edit = findNode(secondRow, (node) => 'ys-ss-kw-edit' === node.className);
	edit.value = 'C++ confirmed';
	edit.dispatch('change');
	await flush();
	message = harness.ids.get('ys-ss-save-msg').textContent;
	if (!message.includes('✓ 關鍵字已更新') || message.includes(WARNING)) {
		throw new Error('bypass success did not use normal fixed success feedback');
	}

	secondRow = harness.keywordBody.children.find((row) => {
		const field = findNode(row, (node) => 'ys-ss-kw-edit' === node.className);
		return field && 'C++ confirmed' === field.value;
	});
	const sort = findNode(secondRow, (node) => 'ys-ss-kw-sort' === node.className);
	sort.value = '0';
	sort.dispatch('change');
	await flush();
	secondRow = harness.keywordBody.children.find((row) => {
		const field = findNode(row, (node) => 'ys-ss-kw-edit' === node.className);
		return field && 'C++ confirmed' === field.value;
	});
	const toggle = findNode(secondRow, (node) => 'checkbox' === node.type);
	toggle.checked = false;
	toggle.dispatch('change');
	await flush();
	secondRow = harness.keywordBody.children.find((row) => {
		const field = findNode(row, (node) => 'ys-ss-kw-edit' === node.className);
		return field && 'C++ confirmed' === field.value;
	});
	findNode(secondRow, (node) => 'BUTTON' === node.tagName && '刪除' === node.textContent).dispatch('click');
	await flush();

	if (renderedTerms().includes('C++ confirmed')) { throw new Error('delete success did not replace authoritative items'); }
	if (5 !== requests.length) { throw new Error('add/edit/sort/toggle/delete did not each use one mutation request'); }
	const bodies = requests.map((entry) => entry.options.body ? JSON.parse(entry.options.body) : {});
	if ('C++ <vector> 入門' !== bodies[0].keyword || 'C++ confirmed' !== bodies[1].keyword
		|| 0 !== bodies[2].sort_order || false !== bodies[3].is_active || 'DELETE' !== requests[4].options.method) {
		throw new Error('keyword mutation request bytes or zero/false semantics changed');
	}
}

async function testAnalyticsOnlySharedRunner() {
	const keyword = deferred();
	let keywordCalls = 0;
	const harness = createAdminHarness({
		analytics: true,
		fetch(url, options) {
			if (url.includes('/overview?')) {
				return response(true, {
					kpi: { total: 1, unique: 1, zero: 0, zero_rate: 0 },
					trend: [],
					top: [{ term: 'nova', hits: 1, zero_hits: 0 }],
					zero: [],
				});
			}
			if (url.endsWith('/keywords')) {
				keywordCalls++;
				return keyword.promise;
			}
			throw new Error('unexpected fetch: ' + url);
		},
	});
	await flush();
	const add = [...harness.buttons].find((button) => '＋設為關鍵字' === button.textContent);
	if (!add) { throw new Error('analytics keyword control did not render'); }
	add.dispatch('click');
	if (!add.disabled) { throw new Error('analytics keyword control did not disable at enqueue'); }
	add.dispatch('click');
	await flush();
	if (1 !== keywordCalls) { throw new Error('analytics duplicate activation bypassed shared mutation authority'); }
	keyword.resolve(jsonResponse(true, {
		items: [{ id: 7, keyword: 'nova', sort_order: 0, is_active: true }],
		cache_status: 'failed',
		cache_warning: 'SECRET SQL ANALYTICS WARNING',
	}));
	await flush();
	if ('✓ 已加入' !== add.textContent || !add.disabled) {
		throw new Error('analytics committed add did not keep its confirmed control state');
	}
	const message = harness.ids.get('ys-ss-action-msg').textContent;
	if (!message.includes(WARNING) || message.includes('SECRET')) {
		throw new Error('analytics-only runner did not show a fixed local cache warning');
	}
}

(async () => {
	await testQueueRecovery();
	await testInvalidResponsesRestore();
	await testAllSettingsOperationsAndWarnings();
	await testAnalyticsOnlySharedRunner();
})().catch((error) => {
	console.error(error.message);
	process.exitCode = 1;
});
