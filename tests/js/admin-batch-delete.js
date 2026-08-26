'use strict';

const {
	createAdminHarness,
	flush,
	response,
	walk,
} = require('../support/admin-js-harness');

const requests = [];
let overviewCalls = 0;
const harness = createAdminHarness({
	analytics: true,
	fetch(url, options) {
		if (url.includes('/overview?')) {
			overviewCalls++;
			return response(true, {
				kpi: { total: 3, unique: 2, zero: 1, zero_rate: 33.3 },
				trend: [],
				top: [
					{ term: 'C:\\Docs\\<vector>', hits: 2, zero_hits: 0 },
					{ term: 'nova', hits: 1, zero_hits: 1 },
				],
				zero: [{ term: 'nova', hits: 1, zero_hits: 1 }],
			});
		}
		if (url.endsWith('/terms')) {
			requests.push({ url, options });
			return response(true, { ok: true, deleted: { queries: 3, daily: 2, total: 5 }, cache_status: 'rotated' });
		}
		if (url.endsWith('/purge')) {
			requests.push({ url, options });
			return response(true, { ok: true, counts: { queries: 0, daily: 0 }, cache_status: 'rotated' });
		}
		throw new Error('unexpected fetch: ' + url);
	},
});

(async () => {
	await flush();
	const topBody = harness.ids.get('ys-ss-top-body');
	const checkboxes = walk(topBody).filter((node) => 'INPUT' === node.tagName && 'checkbox' === node.type);
	if (2 !== checkboxes.length) { throw new Error('analytics rows did not render selectable checkboxes'); }

	checkboxes[0].checked = true;
	checkboxes[0].dispatch('change');
	checkboxes[1].checked = true;
	checkboxes[1].dispatch('change');
	const batch = harness.ids.get('ys-ss-delete-selected');
	if (batch.disabled) { throw new Error('batch delete stayed disabled after selection'); }
	batch.dispatch('click');
	await flush();

	if (1 !== requests.length || !requests[0].url.endsWith('/terms') || 'DELETE' !== requests[0].options.method) {
		throw new Error('selected terms did not use the bounded batch-delete endpoint');
	}
	const batchPayload = JSON.parse(requests[0].options.body);
	if (JSON.stringify(['C:\\Docs\\<vector>', 'nova']) !== JSON.stringify(batchPayload.terms)) {
		throw new Error('batch delete did not preserve exact unique term bytes');
	}
	if (2 !== overviewCalls) { throw new Error('successful batch delete did not reload the active range once'); }

	const all = harness.ids.get('ys-ss-delete-all-analytics');
	all.dispatch('click');
	await flush();
	if (2 !== requests.length || !requests[1].url.endsWith('/purge') || 'POST' !== requests[1].options.method) {
		throw new Error('analytics full-delete control did not use the existing purge endpoint');
	}
	const allPayload = JSON.parse(requests[1].options.body);
	if ('all' !== allPayload.mode || 'DELETE' !== allPayload.confirm) {
		throw new Error('analytics full-delete control omitted its explicit confirmation contract');
	}
})().catch((error) => {
	console.error(error.message);
	process.exitCode = 1;
});
