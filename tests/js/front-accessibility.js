'use strict';

const { createHarness, assert, runTests } = require('../support/front-js-harness');

function threeResults() {
	return {
		q: 'nova', total: 3, products_total: 3, log_receipt: 'receipt-nova', view_all: '/shop/?q=nova',
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
	['input and listbox keep synchronized busy state through query error and close', async () => {
		const h = createHarness();
		const { input, panel } = h.forms[0];
		input.value = 'error-query';
		input.focus();
		assert('true' === input.getAttribute('aria-busy'), 'current query did not mark input busy');
		assert('true' === panel.getAttribute('aria-busy'), 'current query did not mark listbox busy');
		await h.resolveJson(0, { message: 'private detail' }, 500);
		assert('false' === input.getAttribute('aria-busy'), 'query error did not clear input busy');
		assert('false' === panel.getAttribute('aria-busy'), 'query error did not clear listbox busy');

		input.value = 'close-query';
		h.dispatch(input, 'input');
		h.advanceTimers(250);
		assert('true' === input.getAttribute('aria-busy'), 'second current query did not mark input busy');
		assert('true' === panel.getAttribute('aria-busy'), 'second current query did not mark listbox busy');
		h.document.body.click();
		assert('false' === input.getAttribute('aria-busy'), 'closed query did not clear input busy');
		assert('false' === panel.getAttribute('aria-busy'), 'closed query did not clear listbox busy');
	}],
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
	['T0 focusout with a null target collapses the panel and still submits proof', async () => {
		const h = createHarness();
		const { form, input, panel } = h.forms[0];
		input.value = 'nova';
		input.focus();
		await h.resolveJson(0, {
			q: 'nova', total: 3, products_total: 3, log_receipt: 'receipt-nova', recent_term: 'nova', view_all: '/shop/?q=nova',
			groups: threeResults().groups,
		});
		assert(h.hasTimer(1200), 'T0 fixture did not schedule the settle timer');
		assert(form._ysSsLogProof && 'receipt-nova' === form._ysSsLogProof.receipt, 'T0 fixture did not keep a signed proof');
		h.dispatch(input, 'focusout', { relatedTarget: null });
		assert(panel.hidden, 'T0 null focusout did not hide the panel');
		assert('false' === input.getAttribute('aria-expanded'), 'T0 null focusout did not set aria-expanded=false');
		assert(null === input.getAttribute('aria-activedescendant'), 'T0 null focusout kept aria-activedescendant');
		assert(form._ysSsLogProof && 'receipt-nova' === form._ysSsLogProof.receipt, 'T0 focusout cleared the search proof');
		assert(h.hasTimer(1200), 'T0 focusout cleared the settle timer');
		h.dispatch(form, 'submit');
		const recent = JSON.parse(h.sandbox.localStorage.getItem('ysss_recent_v2') || '[]');
		assert(1 === h.beacons.length && 'receipt-nova' === (h.beaconPayload(0) || {}).receipt, 'T0 submit did not send the signed beacon');
		assert(1 === recent.length && 'nova' === recent[0], 'T0 submit did not write the recent search');
		assert('receipt-nova' === (form.querySelector('input[name="ys_ss_log_receipt"]') || {}).value, 'T0 submit did not carry ys_ss_log_receipt');
	}],
	['T1 focus leaving the form collapses the open suggestion panel', async () => {
		const h = createHarness();
		const { input, panel } = h.forms[0];
		const outside = h.document.createElement('button');
		outside.type = 'button';
		h.document.body.appendChild(outside);
		input.value = 'nova';
		input.focus();
		await h.resolveJson(0, threeResults());
		h.dispatch(input, 'keydown', { key: 'ArrowDown' });
		assert(null !== input.getAttribute('aria-activedescendant'), 'T1 fixture did not establish an active descendant');
		h.dispatch(input, 'focusout', { relatedTarget: outside });
		assert(panel.hidden, 'T1 external focusout did not hide the panel');
		assert('false' === input.getAttribute('aria-expanded'), 'T1 external focusout did not set aria-expanded=false');
		assert(null === input.getAttribute('aria-activedescendant'), 'T1 external focusout kept aria-activedescendant');
		outside.focus();
		input.focus();
		await h.resolveJson(1, threeResults());
		h.dispatch(input, 'keydown', { key: 'ArrowDown' });
		h.dispatch(input, 'focusout', { relatedTarget: null });
		assert(panel.hidden, 'T1 null relatedTarget did not hide the panel');
		assert('false' === input.getAttribute('aria-expanded'), 'T1 null relatedTarget did not set aria-expanded=false');
		assert(null === input.getAttribute('aria-activedescendant'), 'T1 null relatedTarget kept aria-activedescendant');
	}],
	['T2 focusout inside the form keeps the suggestion panel open', async () => {
		const h = createHarness();
		const { input, panel, submit } = h.forms[0];
		input.value = 'nova';
		input.focus();
		await h.resolveJson(0, threeResults());
		h.dispatch(input, 'keydown', { key: 'ArrowDown' });
		const activeId = input.getAttribute('aria-activedescendant');
		assert(null !== activeId, 'T2 fixture did not establish an active descendant');
		h.dispatch(input, 'focusout', { relatedTarget: submit });
		assert(false === panel.hidden, 'T2 in-form focusout hid the panel');
		assert('true' === input.getAttribute('aria-expanded'), 'T2 in-form focusout cleared aria-expanded');
		assert(activeId === input.getAttribute('aria-activedescendant'), 'T2 in-form focusout cleared aria-activedescendant');
	}],
	['T3 mousedown on an option activates it without leaving the input', async () => {
		const h = createHarness();
		const { input, panel } = h.forms[0];
		input.value = 'nova';
		input.focus();
		await h.resolveJson(0, threeResults());
		const option = panel.querySelector('[role="option"]');
		assert(option && ('A' === option.tagName || 'BUTTON' === option.tagName), 'T3 option is not a focusable anchor or button');
		const down = h.dispatch(option, 'mousedown', { button: 0 });
		let hiddenBeforeClick = panel.hidden;
		if (!down.defaultPrevented) {
			h.dispatch(input, 'focusout', { relatedTarget: option });
			hiddenBeforeClick = panel.hidden;
			option.focus();
		}
		assert(false === hiddenBeforeClick, 'T3 panel collapsed before the option click');
		option.click();
		assert(1 === option.clickCount, 'T3 option click was not activated');
		assert(h.document.activeElement === input, 'T3 focus left the search input');
		assert(false === panel.hidden, 'T3 panel was closed when the option activated');
		assert('true' === input.getAttribute('aria-expanded'), 'T3 collapsed aria-expanded before activation');
	}],
	['T4 focusout from an option collapses the panel when Tab leaves the form', async () => {
		const h = createHarness();
		const { input, panel } = h.forms[0];
		const outside = h.document.createElement('button');
		outside.type = 'button';
		h.document.body.appendChild(outside);
		input.value = 'nova';
		input.focus();
		await h.resolveJson(0, threeResults());
		const option = panel.querySelector('.ys-ss-item');
		assert(option, 'T4 result option is missing');
		option.focus();
		assert(h.document.activeElement === option, 'T4 option did not take focus');
		h.dispatch(option, 'focusout', { relatedTarget: outside });
		assert(panel.hidden, 'T4 focusout from an option did not hide the panel');
		assert('false' === input.getAttribute('aria-expanded'), 'T4 focusout from an option did not set aria-expanded=false');
		assert(null === input.getAttribute('aria-activedescendant'), 'T4 focusout from an option kept aria-activedescendant');
	}],
	['T5 chip and view-all mousedown activate without leaving the input', async () => {
		const h = createHarness();
		const { input, panel } = h.forms[0];
		function pointerActivate(node, label) {
			const down = h.dispatch(node, 'mousedown', { button: 0 });
			if (!down.defaultPrevented) {
				h.dispatch(input, 'focusout', { relatedTarget: node });
				node.focus();
			}
			assert(true === down.defaultPrevented, label + ' mousedown was not cancelled');
			assert(false === panel.hidden, label + ' panel collapsed before activation');
			assert(h.document.activeElement === input, label + ' focus left the search input');
			node.click();
			assert(node.clickCount >= 1, label + ' click was not activated');
			assert(h.document.activeElement === input, label + ' focus left after activation');
			assert(false === panel.hidden, label + ' panel closed on activation');
		}
		input.value = 'nova';
		input.focus();
		await h.resolveJson(0, threeResults());
		const viewAll = panel.querySelector('.ys-ss-viewall');
		assert(viewAll, 'T5 view-all is missing');
		pointerActivate(viewAll, 'T5 view-all');
		const away = h.document.createElement('button');
		away.type = 'button';
		h.document.body.appendChild(away);
		away.focus();
		input.value = '';
		input.focus();
		await h.resolveJson(1, { items: [{ term: 'wool' }] });
		const chip = panel.querySelector('.ys-ss-chip');
		assert(chip, 'T5 chip is missing');
		pointerActivate(chip, 'T5 chip');
	}],
	['T6 mousedown on the search input is not cancelled', async () => {
		const h = createHarness();
		const { input } = h.forms[0];
		const outside = h.document.createElement('button');
		outside.type = 'button';
		h.document.body.appendChild(outside);
		outside.focus();
		const down = h.dispatch(input, 'mousedown', { button: 0 });
		assert(false === down.defaultPrevented, 'T6 input mousedown was cancelled');
		if (!down.defaultPrevented) { input.focus(); }
		assert(h.document.activeElement === input, 'T6 input did not take focus');
	}],
	['T7 panel background pointer does not collapse until the pointer flag clears', async () => {
		async function openPanel() {
			const h = createHarness();
			const { input, panel } = h.forms[0];
			input.value = 'nova';
			input.focus();
			await h.resolveJson(0, threeResults());
			h.dispatch(input, 'keydown', { key: 'ArrowDown' });
			assert(false === panel.hidden, 'T7 fixture panel did not open');
			assert(null !== input.getAttribute('aria-activedescendant'), 'T7 fixture did not establish an active descendant');
			return { h, input, panel };
		}
		const mouseup = await openPanel();
		mouseup.h.dispatch(mouseup.panel, 'mousedown', { button: 0 });
		mouseup.h.dispatch(mouseup.input, 'focusout', { relatedTarget: null });
		assert(false === mouseup.panel.hidden, 'T7 panel collapsed while the pointer was inside');
		assert('true' === mouseup.input.getAttribute('aria-expanded'), 'T7 cleared aria-expanded while the pointer was inside');
		mouseup.h.dispatch(mouseup.h.document, 'mouseup');
		mouseup.h.dispatch(mouseup.input, 'focusout', { relatedTarget: null });
		assert(mouseup.panel.hidden, 'T7 panel stayed open after mouseup cleared the pointer flag');
		assert('false' === mouseup.input.getAttribute('aria-expanded'), 'T7 left aria-expanded true after mouseup cleared the pointer flag');

		const click = await openPanel();
		click.h.dispatch(click.panel, 'mousedown', { button: 0 });
		click.h.dispatch(click.input, 'focusout', { relatedTarget: null });
		assert(false === click.panel.hidden, 'T7 panel collapsed before click cleared the pointer flag');
		click.h.dispatch(click.panel, 'click');
		click.h.dispatch(click.input, 'focusout', { relatedTarget: null });
		assert(click.panel.hidden, 'T7 panel stayed open after click cleared the pointer flag');
	}],
	['T8 popup form focusout collapses the panel and leaves the popup open', async () => {
		const h = createHarness({ formCount: 0, popup: true, triggerCount: 1 });
		h.triggers[0].click();
		h.advanceTimers(30);
		assert(false === h.popup.root.hidden, 'T8 popup did not open');
		assert(h.document.documentElement.classList.contains('ys-ss-noscroll'), 'T8 popup did not set ys-ss-noscroll');
		assert(h.document.activeElement === h.popup.input, 'T8 popup did not focus its search input');
		h.popup.input.value = 'nova';
		h.dispatch(h.popup.input, 'input');
		h.advanceTimers(250);
		await h.resolveJson(1, threeResults());
		assert(false === h.popup.panel.hidden, 'T8 popup suggestion panel did not open');
		h.dispatch(h.popup.input, 'focusout', { relatedTarget: h.popup.close });
		assert(h.popup.panel.hidden, 'T8 focusout to the close button did not hide the panel');
		assert('false' === h.popup.input.getAttribute('aria-expanded'), 'T8 focusout to the close button did not set aria-expanded=false');
		assert(false === h.popup.root.hidden, 'T8 collapsed the popup');
		assert(h.document.documentElement.classList.contains('ys-ss-noscroll'), 'T8 removed ys-ss-noscroll');
	}],
	['T9 middle-button mousedown on an option is not cancelled', async () => {
		const h = createHarness();
		const { input, panel } = h.forms[0];
		input.value = 'nova';
		input.focus();
		await h.resolveJson(0, threeResults());
		const option = panel.querySelector('.ys-ss-item');
		assert(option, 'T9 result option is missing');
		const down = h.dispatch(option, 'mousedown', { button: 1 });
		assert(false === down.defaultPrevented, 'T9 middle-button mousedown was cancelled');
		assert(false === panel.hidden, 'T9 middle-button mousedown hid the panel');
		assert(h.document.activeElement === input, 'T9 middle-button mousedown left the search input');
	}],
	['late in-flight and debounced queries stay collapsed after Tab leaves the form', async () => {
		const failures = [];
		function check(condition, message) {
			if (!condition) { failures.push(message); }
		}
		const outsideA = (() => {
			const h = createHarness();
			const { input, panel, form } = h.forms[0];
			const outside = h.document.createElement('button');
			outside.type = 'button';
			h.document.body.appendChild(outside);
			input.value = 'nova';
			input.focus();
			check(1 === h.pendingFetches.length, 'scenario A did not start the in-flight query');
			h.dispatch(input, 'focusout', { relatedTarget: outside });
			check(panel.hidden, 'scenario A Tab did not hide the panel before the response');
			return { h, input, panel, form };
		})();
		await outsideA.h.resolveJson(0, threeResults());
		check(outsideA.panel.hidden, 'scenario A late response reopened the panel');
		check('false' === outsideA.input.getAttribute('aria-expanded'), 'scenario A late response set aria-expanded');
		check(outsideA.form._ysSsLogProof && 'receipt-nova' === outsideA.form._ysSsLogProof.receipt, 'scenario A late response dropped the search proof');
		outsideA.h.document.body.focus();
		outsideA.input.focus();
		check(2 === outsideA.h.pendingFetches.length, 'refocus after collapse did not start a new query');
		await outsideA.h.resolveJson(1, threeResults());
		check(false === outsideA.panel.hidden, 'refocus after collapse did not reopen the panel');
		check('true' === outsideA.input.getAttribute('aria-expanded'), 'refocus after collapse left aria-expanded false');

		const outsideB = (() => {
			const h = createHarness();
			const { input, panel, form } = h.forms[0];
			const outside = h.document.createElement('button');
			outside.type = 'button';
			h.document.body.appendChild(outside);
			input.value = 'nova';
			h.dispatch(input, 'input');
			check(0 === h.pendingFetches.length, 'scenario B sent the query before debounce');
			h.dispatch(input, 'focusout', { relatedTarget: outside });
			check(panel.hidden, 'scenario B Tab did not hide the panel before debounce');
			h.advanceTimers(250);
			check(1 === h.pendingFetches.length, 'scenario B debounce did not start the query');
			check(panel.hidden, 'scenario B debounced query reopened the panel');
			check('false' === input.getAttribute('aria-expanded'), 'scenario B debounced query set aria-expanded');
			return { h, input, panel, form };
		})();
		await outsideB.h.resolveJson(0, threeResults());
		check(outsideB.panel.hidden, 'scenario B late response reopened the panel');
		check('false' === outsideB.input.getAttribute('aria-expanded'), 'scenario B late response set aria-expanded');
		check(outsideB.form._ysSsLogProof && 'receipt-nova' === outsideB.form._ysSsLogProof.receipt, 'scenario B late response dropped the search proof');
		if (failures.length) { throw new Error(failures.join('; ')); }
	}],
	['S1 held panel mousedown still collapses when Tab moves outside the form', async () => {
		const h = createHarness();
		const { input, panel } = h.forms[0];
		const outside = h.document.createElement('button');
		outside.type = 'button';
		h.document.body.appendChild(outside);
		input.value = 'nova';
		input.focus();
		await h.resolveJson(0, threeResults());
		h.dispatch(panel, 'mousedown', { button: 0 });
		h.dispatch(input, 'focusout', { relatedTarget: outside });
		assert(panel.hidden, 'S1 Tab to an outside control left the panel open');
		assert('false' === input.getAttribute('aria-expanded'), 'S1 Tab to an outside control left aria-expanded true');
	}],
	['S7 panel background mousedown is not cancelled', async () => {
		const h = createHarness();
		const { input, panel } = h.forms[0];
		input.value = 'nova';
		input.focus();
		await h.resolveJson(0, threeResults());
		const down = h.dispatch(panel, 'mousedown', { button: 0 });
		assert(false === down.defaultPrevented, 'S7 background mousedown was cancelled');
		assert(false === panel.hidden, 'S7 background mousedown hid the panel');
	}],
	['S4 middle-button mousedown keeps the panel open through a null focusout', async () => {
		const h = createHarness();
		const { input, panel } = h.forms[0];
		input.value = 'nova';
		input.focus();
		await h.resolveJson(0, threeResults());
		const option = panel.querySelector('.ys-ss-item');
		assert(option, 'S4 result option is missing');
		h.dispatch(option, 'mousedown', { button: 1 });
		h.dispatch(input, 'focusout', { relatedTarget: null });
		assert(false === panel.hidden, 'S4 null focusout hid the panel while the middle button was down');
		assert('true' === input.getAttribute('aria-expanded'), 'S4 null focusout cleared aria-expanded while the middle button was down');
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
		await h.resolveJson(1, { q: 'zero', total: 0, products_total: 0, groups: [], view_all: '', log_receipt: 'receipt-zero' });
		await h.resolveJson(0, { items: [{ term: 'fallback-chip' }] });
		const focusables = h.popup.root.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])');
		assert(4 === focusables.length, 'popup focus cycle did not include the production fallback chip');
		assert(focusables[3].classList.contains('ys-ss-chip'), 'production fallback chip is not the last popup focusable');
		focusables[3].focus();
		h.dispatch(h.document, 'keydown', { key: 'Tab' });
		assert(h.document.activeElement === h.popup.close, 'Tab did not wrap from dynamic chip to popup close');
	}],
]);
