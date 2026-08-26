'use strict';

const {
	createAdminHarness,
	findNode,
	flush,
	response,
} = require('../support/admin-js-harness');

const termRequests = [];
const termResponses = [
	{ ok: false, payload: { code: 'ys_ss_analytics_busy', message: 'SECRET SQL ONE' } },
	{ ok: false, payload: { code: 'ys_ss_analytics_mutation_failed', message: 'SECRET SQL TWO' } },
	{ ok: true, payload: { ok: true, deleted: { queries: 2, daily: 1, total: 3 }, cache_status: 'rotated' } },
	{ ok: true, payload: { ok: true, deleted: { queries: 3, daily: 1, total: 4 }, cache_status: 'failed', cache_warning: 'SECRET SQL WARNING' } },
	{ ok: false, payload: { code: 'ys_ss_analytics_busy', message: 'SECRET SQL THREE' } },
];
let overviewCalls = 0;

const harness = createAdminHarness({
	analytics: true,
	fetch(url, options) {
		if (url.includes('/overview?')) {
			overviewCalls++;
			return response(true, {
				kpi: { total: 1, unique: 1, zero: 0, zero_rate: 0 },
				trend: [],
				top: [{ term: 'C:\\Docs\\<vector>', hits: 1, zero_hits: 0 }],
				zero: [],
			});
		}
		if (url.includes('/term?')) {
			if ('DELETE' !== options.method) { throw new Error('exact term request did not use DELETE'); }
			termRequests.push(url);
			const next = termResponses.shift();
			return response(next.ok, next.payload);
		}
		throw new Error('unexpected fetch: ' + url);
	},
});

function latestDeleteButton() {
	return [...harness.buttons].reverse().find((button) => '刪除此關鍵字的全部紀錄' === button.title);
}

(async () => {
	await flush();
	let deleteButton = latestDeleteButton();
	if (!deleteButton) { throw new Error('analytics exact-delete button was not rendered'); }
	const actionMessage = harness.ids.get('ys-ss-action-msg');

	deleteButton.dispatch('click');
	await flush();
	if (deleteButton.disabled) { throw new Error('busy failure did not re-enable the delete button'); }
	if ('搜尋分析正在更新，請稍後再試。' !== actionMessage.textContent) {
		throw new Error('busy failure did not show the fixed safe message');
	}

	deleteButton.dispatch('click');
	await flush();
	if (deleteButton.disabled) { throw new Error('database failure did not re-enable the delete button'); }
	if ('刪除失敗，請稍後再試。' !== actionMessage.textContent || actionMessage.textContent.includes('SECRET')) {
		throw new Error('database failure did not stay fixed and secret-free');
	}
	if (1 !== overviewCalls) { throw new Error('failed delete unexpectedly reloaded analytics'); }

	deleteButton.dispatch('click');
	await flush();
	if ('已刪除 3 筆搜尋紀錄。' !== actionMessage.textContent) {
		throw new Error('success did not report the trusted server deletion total');
	}
	if (2 !== overviewCalls) { throw new Error('successful delete did not reload analytics'); }

	deleteButton = latestDeleteButton();
	deleteButton.dispatch('click');
	await flush();
	if (!actionMessage.textContent.includes('已刪除 4 筆搜尋紀錄。')
		|| !actionMessage.textContent.includes('資料已更新，但熱門建議快取可能延遲更新。')) {
		throw new Error('committed cache-warning delete lost its result or local warning');
	}
	if (actionMessage.textContent.includes('SECRET')) {
		throw new Error('arbitrary server cache_warning leaked into delete feedback');
	}
	if (3 !== overviewCalls) { throw new Error('cache-warning success did not reload committed analytics'); }

	deleteButton = latestDeleteButton();
	deleteButton.dispatch('click');
	await flush();
	if ('搜尋分析正在更新，請稍後再試。' !== actionMessage.textContent) {
		throw new Error('new failure did not replace the previous success message');
	}
	for (const timer of harness.timers.values()) {
		if (!timer.cancelled && 4000 === timer.delay) { timer.callback(); }
	}
	if ('搜尋分析正在更新，請稍後再試。' !== actionMessage.textContent) {
		throw new Error('stale success timer erased a newer failure message');
	}

	if (5 !== termRequests.length || !termRequests.every((url) => url.includes('term=C%3A%5CDocs%5C%3Cvector%3E'))) {
		throw new Error('exact term bytes were not encoded consistently in delete requests');
	}
	if (!findNode(harness.ids.get('ys-ss-top-body'), (node) => 'BUTTON' === node.tagName)) {
		throw new Error('analytics reload did not render replacement row controls');
	}
})().catch((error) => {
	console.error(error.message);
	process.exitCode = 1;
});
