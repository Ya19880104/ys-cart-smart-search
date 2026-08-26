'use strict';

const { createHarness, assert, runTests } = require('../support/front-js-harness');

function threeResults() {
	return {
		q: 'nova', total: 3, log_receipt: 'receipt-nova', view_all: '/shop/?q=nova',
		groups: [{
			type: 'products', label: 'results', total: 3,
			items: [
				{ title: 'Nova zero', url: '/nova-0' },
				{ title: 'Nova one', url: '/nova-1' },
				{ title: 'Nova two', url: '/nova-2' },
			],
		}],
	};
}

runTests([
	['combobox arrows retain input focus and Enter activates only current option', async () => {
		const h = createHarness();
		const { input, panel } = h.forms[0];
		input.value = 'nova';
		input.focus();
		assert(1 === h.pendingFetches.length, 'focus() did not start the populated combobox query');
		await h.resolveJson(0, threeResults());
		for (let index = 0; index < 3; index += 1) {
			h.dispatch(input, 'keydown', { key: 'ArrowDown' });
			assert('ys-ss-panel-fixture-0-option-' + index === input.getAttribute('aria-activedescendant'), 'ArrowDown did not select option ID ' + index);
			assert(h.document.activeElement === input, 'ArrowDown moved DOM focus away from input');
		}
		h.dispatch(input, 'keydown', { key: 'ArrowUp' });
		assert('ys-ss-panel-fixture-0-option-1' === input.getAttribute('aria-activedescendant'), 'ArrowUp did not move back to option ID 1');
		const options = panel.querySelectorAll('[role="option"]');
		h.dispatch(input, 'keydown', { key: 'Enter' });
		assert(0 === options[0].clickCount && 1 === options[1].clickCount && 0 === options[2].clickCount, 'Enter did not activate only the current option');
	}],
	['Escape clears active descendant and collapses the combobox', async () => {
		const h = createHarness();
		const { input, panel } = h.forms[0];
		input.value = 'nova';
		input.focus();
		assert(1 === h.pendingFetches.length, 'focus() did not start the Escape fixture query');
		await h.resolveJson(0, threeResults());
		h.dispatch(input, 'keydown', { key: 'ArrowDown' });
		assert(null !== input.getAttribute('aria-activedescendant'), 'ArrowDown did not establish an active descendant before Escape');
		assert('true' === input.getAttribute('aria-expanded'), 'rendered options did not expand the combobox before Escape');
		h.dispatch(input, 'keydown', { key: 'Escape' });
		assert(null === input.getAttribute('aria-activedescendant'), 'Escape retained aria-activedescendant');
		assert(panel.hidden, 'Escape did not collapse the result panel');
		assert('false' === input.getAttribute('aria-expanded'), 'Escape did not set aria-expanded=false');
	}],
	['focus dispatches the production focus interaction', async () => {
		const h = createHarness();
		const { input } = h.forms[0];
		input.value = 'focus-query';
		input.focus();
		assert(1 === h.pendingFetches.length, 'focus() did not dispatch the production focus handler');
		assert(h.pendingFetches[0].url.endsWith('/query?q=focus-query'), 'focus handler did not issue the literal populated query');
	}],
	['popup traps across rendered result links and view-all then restores the exact second opener', async () => {
		const h = createHarness({ formCount: 0, popup: true, triggerCount: 2 });
		const secondTrigger = h.triggers[1];
		secondTrigger.focus();
		secondTrigger.click();
		h.advanceTimers(30);
		assert(h.document.activeElement === h.popup.input, 'popup did not focus its search input');
		assert(1 === h.pendingFetches.length && h.pendingFetches[0].url.endsWith('/suggest'), 'popup input focus did not start its empty suggestion interaction');
		h.popup.input.value = 'nova';
		h.dispatch(h.popup.input, 'input');
		h.advanceTimers(250);
		await h.resolveJson(1, threeResults());
		const focusables = h.popup.root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])');
		assert(7 === focusables.length, 'popup focus cycle did not include three production result links and view-all');
		assert(focusables[3].classList.contains('ys-ss-item'), 'first dynamic result link is missing from the popup focus cycle');
		assert(focusables[6].classList.contains('ys-ss-viewall'), 'dynamic view-all is not the last popup focusable');
		focusables[focusables.length - 1].focus();
		h.dispatch(h.document, 'keydown', { key: 'Tab' });
		assert(h.document.activeElement === focusables[0], 'Tab did not wrap from last to first popup control');
		focusables[0].focus();
		h.dispatch(h.document, 'keydown', { key: 'Tab', shiftKey: true });
		assert(h.document.activeElement === focusables[focusables.length - 1], 'Shift+Tab did not wrap from first to last popup control');
		h.dispatch(h.document, 'keydown', { key: 'Escape' });
		assert(h.popup.root.hidden, 'Escape did not close popup');
		assert(h.document.activeElement === secondTrigger, 'popup focus did not return to the exact second opener');
	}],
	['popup focus cycle includes a production-rendered fallback chip', async () => {
		const h = createHarness({ formCount: 0, popup: true, triggerCount: 2 });
		h.triggers[1].click();
		h.advanceTimers(30);
		assert(1 === h.pendingFetches.length && h.pendingFetches[0].url.endsWith('/suggest'), 'popup focus did not start the cold suggest fixture');
		h.popup.input.value = 'zero';
		h.dispatch(h.popup.input, 'input');
		h.advanceTimers(250);
		await h.resolveJson(1, { q: 'zero', total: 0, groups: [], view_all: '', log_receipt: 'receipt-zero' });
		await h.resolveJson(0, { items: [{ term: 'fallback-chip' }] });
		const focusables = h.popup.root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])');
		assert(4 === focusables.length, 'popup focus cycle did not include the production fallback chip');
		assert(focusables[3].classList.contains('ys-ss-chip'), 'production fallback chip is not the last popup focusable');
		focusables[3].focus();
		h.dispatch(h.document, 'keydown', { key: 'Tab' });
		assert(h.document.activeElement === h.popup.close, 'Tab did not wrap from dynamic chip to popup close');
	}],
]);
