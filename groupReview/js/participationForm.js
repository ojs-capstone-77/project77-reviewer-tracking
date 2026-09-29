/**
 * @file js/participationForm.js
 *
 * Client-side behaviour for the reviewer participation form.
 *
 * Paging: every reviewer is in the form; Previous/Next only show and hide
 * their columns.
 * Save, and Submit on an already-submitted form: enabled only while the form
 * differs from how it was loaded.
 * Attendance: the details box shows only when "Other" is selected.
 */
function grpParticipationForm(element) {
	return element.closest ? element.closest('form[data-grp-current-page]') : null;
}

/** Serialize the user-editable fields; the page field changes when paging. */
function grpParticipationFormState(form) {
	var data = new FormData(form);
	data.delete('page');
	return new URLSearchParams(data).toString();
}

// Capture the loaded state before the first edit; focus precedes any input.
document.addEventListener('focusin', function (event) {
	var form = grpParticipationForm(event.target);
	if (form && form.dataset.grpInitialState === undefined) {
		form.dataset.grpInitialState = grpParticipationFormState(form);
	}
});

document.addEventListener('input', function (event) {
	var form = grpParticipationForm(event.target);
	if (form && event.target.classList.contains('grpTool__attendanceSelect')) {
		var note = event.target.parentNode.querySelector('.grpTool__attendanceNote');
		if (note) {
			note.hidden = event.target.value !== 'other';
		}
	}
	if (form) {
		var unchanged = form.dataset.grpInitialState !== undefined
			&& grpParticipationFormState(form) === form.dataset.grpInitialState;
		form.querySelectorAll('[data-grp-needs-change]').forEach(function (button) {
			button.disabled = unchanged;
		});
	}
});

document.addEventListener('click', function (event) {
	var button = event.target.closest('[data-grp-page-step]');
	var form = button && grpParticipationForm(button);
	if (!form) {
		return;
	}

	var pageCount = parseInt(form.dataset.grpPageCount, 10);
	var lastPage = pageCount - 1;
	var page = parseInt(form.dataset.grpCurrentPage, 10) + parseInt(button.dataset.grpPageStep, 10);
	if (page < 0 || page > lastPage) {
		return;
	}

	form.dataset.grpCurrentPage = page;
	form.elements.page.value = page;
	form.querySelectorAll('[data-grp-page]').forEach(function (element) {
		element.hidden = parseInt(element.dataset.grpPage, 10) !== page;
	});
	form.querySelectorAll('[data-grp-before-last-page]').forEach(function (element) {
		element.hidden = page === lastPage;
	});
	form.querySelectorAll('[data-grp-page-step]').forEach(function (navButton) {
		var target = page + parseInt(navButton.dataset.grpPageStep, 10);
		navButton.disabled = target < 0 || target > lastPage;
	});
});
