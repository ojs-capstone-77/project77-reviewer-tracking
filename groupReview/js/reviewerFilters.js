(function () {
	'use strict';

	const initialised = new WeakSet();

	function initialise() {
		const form = document.getElementById('grpReviewerFilters');
		if (!form || initialised.has(form)) {
			return;
		}
		initialised.add(form);
		form.addEventListener('click', function (event) {
			const button = event.target.closest('[data-grp-clear]');
			if (!button) {
				return;
			}
			const group = button.closest('[data-grp-filter-group]');
			group.querySelectorAll('input').forEach(function (input) {
				if (input.type === 'checkbox') {
					input.checked = false;
				} else {
					input.value = '';
				}
			});
			form.requestSubmit();
		});
		form.addEventListener('change', function (event) {
			if (event.target.matches('input[type="checkbox"], select')) {
				form.requestSubmit();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialise);
	} else {
		initialise();
	}
	new MutationObserver(initialise).observe(document.body, { childList: true, subtree: true });
})();
