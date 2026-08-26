'use strict';

const {
	createAdminHarness,
	flush,
	response,
} = require('../support/admin-js-harness');

const purgeResponses = [
	() => response(false, { code: 'ys_ss_analytics_mutation_failed', message: 'SECRET SQL' }),
	() => Promise.reject(new Error('SECRET NETWORK')),
	() => response(true, {
		ok: true,
		deleted: 7,
		counts: { queries: 12, daily: 3 },
		cache_status: 'failed',
		cache_warning: 'SECRET SQL WARNING',
	}),
	() => response(true, {
		ok: true,
		counts: { queries: 0, daily: 0 },
		cache_status: 'rotated',
	}),
];
const purgeRequests = [];
const settingsRequests = [];

const harness = createAdminHarness({
	settings: true,
	fetch(url, options) {
		if (url.includes('/purge')) {
			purgeRequests.push({ url, options });
			return purgeResponses.shift()();
		}
		if (url.includes('/settings')) {
			settingsRequests.push({ url, options });
			return response(true, {
				settings: {},
				cache_status: 'failed',
				cache_warning: 'SECRET SQL SETTINGS WARNING',
			});
		}
		throw new Error('unexpected fetch: ' + url);
	},
});

(async () => {
	const message = harness.ids.get('ys-ss-save-msg');
	const counts = harness.ids.get('ys-ss-counts');
	const expiredButton = harness.ids.get('ys-ss-purge-expired');
	const allButton = harness.ids.get('ys-ss-purge-all');

	expiredButton.dispatch('click');
	await flush();
	if (expiredButton.disabled) { throw new Error('expired purge failure did not re-enable its button'); }
	if ('清理失敗，請稍後再試。' !== message.textContent || message.textContent.includes('SECRET')) {
		throw new Error('expired purge did not show a fixed safe failure message');
	}

	allButton.dispatch('click');
	await flush();
	if (allButton.disabled) { throw new Error('full purge failure did not re-enable its button'); }
	if ('清理失敗，請稍後再試。' !== message.textContent || message.textContent.includes('SECRET')) {
		throw new Error('full purge did not show a fixed safe network failure message');
	}

	expiredButton.dispatch('click');
	await flush();
	if ('12 筆原始 / 3 筆彙總' !== counts.textContent) {
		throw new Error('cache-warning expired purge rolled back committed counts');
	}
	if (!message.textContent.includes('已清理 7 筆逾期資料')
		|| !message.textContent.includes('資料已更新，但熱門建議快取可能延遲更新。')) {
		throw new Error('cache-warning expired purge lost result or local warning');
	}
	if (message.textContent.includes('SECRET')) {
		throw new Error('expired purge displayed arbitrary server warning bytes');
	}

	allButton.dispatch('click');
	await flush();
	if ('0 筆原始 / 0 筆彙總' !== counts.textContent) {
		throw new Error('full purge success did not preserve committed counts');
	}
	if ('已清除全部搜尋分析資料。' !== message.textContent) {
		throw new Error('rotated full purge did not use normal fixed success message');
	}

	const saveButton = harness.ids.get('ys-ss-save');
	saveButton.dispatch('click');
	await flush();
	if (saveButton.disabled) { throw new Error('settings cache-warning success left save disabled'); }
	if (!message.textContent.includes('✓ 已儲存')
		|| !message.textContent.includes('資料已更新，但熱門建議快取可能延遲更新。')) {
		throw new Error('settings committed warning did not preserve success and local warning');
	}
	if (message.textContent.includes('SECRET')) {
		throw new Error('settings displayed arbitrary server warning bytes');
	}

	if (4 !== purgeRequests.length || 1 !== settingsRequests.length) {
		throw new Error('unexpected purge/settings request count');
	}
	const payloads = purgeRequests.map((entry) => JSON.parse(entry.options.body));
	if ('POST' !== purgeRequests[0].options.method || 'expired' !== payloads[0].mode || 'expired' !== payloads[2].mode) {
		throw new Error('expired purge request contract changed');
	}
	if ('all' !== payloads[1].mode || 'DELETE' !== payloads[1].confirm
		|| 'all' !== payloads[3].mode || 'DELETE' !== payloads[3].confirm) {
		throw new Error('full purge request contract changed');
	}
})().catch((error) => {
	console.error(error.message);
	process.exitCode = 1;
});
