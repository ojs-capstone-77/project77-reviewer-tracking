{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">Reviewer Participation Recording</h1>

	<div class="grpTool__card grpTool__card--wide grpTool__participationForm">
		<div class="grpTool__cardHeader">
			<span>{$session.submissionTitle|escape}</span>
			{if $session.firstAuthor}<span>{$session.firstAuthor|escape}</span>{/if}
		</div>
		<div class="grpTool__cardBody">
			<div class="grpTool__infoBar">
				<div>
					<div class="grpTool__infoLabel">Review round</div>
					<div class="grpTool__infoValue">Round {$session.round}</div>
				</div>
				<div>
					<div class="grpTool__infoLabel">Review Group Leader</div>
					<div class="grpTool__infoValue">{$session.leaderName|escape}</div>
				</div>
			</div>

			{if $isCancelled}
				<div class="grp__notice grp__notice--warning">{translate key="plugins.generic.groupReview.participation.cancelledNotice"}</div>
			{/if}
			{if $saveError}
				<div class="grp__notice grp__notice--error">{translate key="plugins.generic.groupReview.participation.error.saveFailed"}</div>
			{/if}
			{if $validationError}<div class="grp__notice grp__notice--error" role="alert">{$validationError|escape}</div>{/if}
			{if $missingRequired}
				<div class="grp__notice grp__notice--error" role="alert">
					{translate key="plugins.generic.groupReview.participation.error.submitBlocked"}
					<ul>
						{foreach from=$missingRequired item=line}<li>{$line|escape}</li>{/foreach}
					</ul>
				</div>
			{/if}

			<form id="grpParticipationForm" method="post" action="{$saveUrl|escape}" data-grp-current-page="{$page|intval}" data-grp-page-count="{$pageCount|intval}">
			{csrf}
			<input type="hidden" name="formId" value="{$formId|intval}">
			<input type="hidden" name="page" value="{$page|intval}">
			<input type="hidden" name="configurationToken" value="{$configurationToken|escape}">

			<div class="grpTool__pagination">
				<div class="grpTool__paginationGroup">
					<button type="button" class="pkpButton grpTool__pageNav" data-grp-page-step="-1"{if $page === 0} disabled{/if}>Previous</button>
					{foreach from=$pages item=pageInfo}
						<span class="grpTool__dots" data-grp-page="{$pageInfo.index}"{if $pageInfo.index !== $page} hidden{/if}>
							{foreach from=$pages item=dot}
								<span class="grpTool__dot{if $dot.index === $pageInfo.index} grpTool__dot--active{/if}"></span>
							{/foreach}
						</span>
					{/foreach}
				</div>
				<div class="grpTool__paginationGroup">
					{foreach from=$pages item=pageInfo}
						<span class="grp__muted grpTool__pageRange" data-grp-page="{$pageInfo.index}"{if $pageInfo.index !== $page} hidden{/if}>{$pageInfo.rangeLabel}</span>
					{/foreach}
					<button type="button" class="pkpButton grpTool__pageNav grpTool__pageNav--next" data-grp-page-step="1"{if $page === $lastPage} disabled{/if}><span>Next</span></button>
				</div>
			</div>

			<fieldset class="grpTool__fieldset"{if $isCancelled} disabled{/if}>
			<table class="grpTool__matrix">
				<colgroup>
					<col style="width: 18%">
					{foreach from=$reviewerSlots item=_reviewerSlot}
						<col style="width: 41%">
					{/foreach}
				</colgroup>
				<thead>
					<tr>
						<th>Criterion</th>
						{foreach from=$reviewers item=reviewer}
							<th data-grp-page="{$reviewer.page}"{if $reviewer.page !== $page} hidden{/if}>{$reviewer.name|escape}</th>
						{/foreach}
						{foreach from=$emptyReviewerSlots item=_emptySlot}
							<th data-grp-page="{$lastPage}"{if $lastPage !== $page} hidden{/if}></th>
						{/foreach}
					</tr>
				</thead>
				<tbody>
					<tr class="grpTool__matrixSection">
						<td colspan="{$columnCount}">Review meeting</td>
					</tr>
					<tr>
						<td colspan="{$columnCount}">
							<label class="grpTool__check"><input type="checkbox" name="noMeeting" value="1" v-model="grpNoMeeting"> No meeting</label>
						</td>
					</tr>
					<tr v-show="!grpNoMeeting">
						<td>{$configuration.text.attendance.label|escape} <span class="grpTool__matrixHint">{if $configuration.fields.attendance}{translate key="plugins.generic.groupReview.configuration.required"}{else}{translate key="plugins.generic.groupReview.configuration.optional"}{/if}</span>{if $configuration.text.attendance.description}<div class="grpTool__matrixHint">{$configuration.text.attendance.description|escape}</div>{/if}</td>
						{foreach from=$reviewers item=reviewer}
							<td data-grp-page="{$reviewer.page}"{if $reviewer.page !== $page} hidden{/if}>
								<select aria-label="{$configuration.text.attendance.label|escape}: {$reviewer.name|escape}" aria-required="{if $configuration.fields.attendance}true{else}false{/if}" class="pkpFormField__input pkpFormField--select__input grpTool__attendanceSelect" name="reviewers[{$reviewer.id|intval}][attendance]">
									{foreach from=$attendanceOptions key=optionKey item=optionLabel}
										<option value="{$optionKey}"{if $reviewer.attendance === $optionKey} selected{/if}>{$optionLabel|escape}</option>
									{/foreach}
								</select>
								<input type="text" class="pkpFormField__input pkpFormField--text__input grpTool__attendanceNote" name="reviewers[{$reviewer.id|intval}][attendanceOther]" value="{$reviewer.attendanceNote|escape}"{if $reviewer.attendance !== 'other'} hidden{/if}>
							</td>
						{/foreach}
						{foreach from=$emptyReviewerSlots item=_emptySlot}
							<td data-grp-page="{$lastPage}"{if $lastPage !== $page} hidden{/if}></td>
						{/foreach}
					</tr>
					<tr v-show="!grpNoMeeting">
						<td>
							{$configuration.text.contribution_comments.label|escape}
							<span class="grpTool__matrixHint">{if $configuration.fields.contribution_comments}{translate key="plugins.generic.groupReview.configuration.required"}{else}{translate key="plugins.generic.groupReview.configuration.optional"}{/if}</span>
							{if $configuration.text.contribution_comments.description}<div class="grpTool__matrixHint">{$configuration.text.contribution_comments.description|escape}</div>{/if}
						</td>
						{foreach from=$reviewers item=reviewer}
							<td data-grp-page="{$reviewer.page}"{if $reviewer.page !== $page} hidden{/if}><textarea class="grpTool__matrixTextarea" name="reviewers[{$reviewer.id|intval}][contributionComments]">{$reviewer.meetingComments|escape}</textarea></td>
						{/foreach}
						{foreach from=$emptyReviewerSlots item=_emptySlot}
							<td data-grp-page="{$lastPage}"{if $lastPage !== $page} hidden{/if}></td>
						{/foreach}
					</tr>

					<tr class="grpTool__matrixSection">
						<td colspan="{$columnCount}">Feedback response</td>
					</tr>
					<tr>
						<td>{$configuration.text.shaping_feedback_types.label|escape} <span class="grpTool__matrixHint">{if $configuration.fields.shaping_feedback_types}{translate key="plugins.generic.groupReview.configuration.required"}{else}{translate key="plugins.generic.groupReview.configuration.optional"}{/if}</span>{if $configuration.text.shaping_feedback_types.description}<div class="grpTool__matrixHint">{$configuration.text.shaping_feedback_types.description|escape}</div>{/if}</td>
						{foreach from=$reviewers item=reviewer}
							<td data-grp-page="{$reviewer.page}"{if $reviewer.page !== $page} hidden{/if}>
								<div class="grpTool__checks grpTool__checks--stacked">
									{assign var="checkedMap" value=$reviewer.contributionChecked}
									{foreach from=$contributionOptions key=optionKey item=optionLabel}
										<label class="grpTool__check">
											<input type="checkbox" name="reviewers[{$reviewer.id|intval}][shapingFeedbackTypes][]" value="{$optionKey|escape}"{if $checkedMap[$optionKey]} checked{/if}>
											{$optionLabel|escape}
										</label>
									{/foreach}
								</div>
							</td>
						{/foreach}
						{foreach from=$emptyReviewerSlots item=_emptySlot}
							<td data-grp-page="{$lastPage}"{if $lastPage !== $page} hidden{/if}></td>
						{/foreach}
					</tr>
					<tr>
						<td>
							{$configuration.text.shaping_feedback_comments.label|escape}
							<span class="grpTool__matrixHint">{if $configuration.fields.shaping_feedback_comments}{translate key="plugins.generic.groupReview.configuration.required"}{else}{translate key="plugins.generic.groupReview.configuration.optional"}{/if}</span>
							{if $configuration.text.shaping_feedback_comments.description}<div class="grpTool__matrixHint">{$configuration.text.shaping_feedback_comments.description|escape}</div>{/if}
						</td>
						{foreach from=$reviewers item=reviewer}
							<td data-grp-page="{$reviewer.page}"{if $reviewer.page !== $page} hidden{/if}><textarea class="grpTool__matrixTextarea" name="reviewers[{$reviewer.id|intval}][shapingFeedbackComments]">{$reviewer.feedbackComments|escape}</textarea></td>
						{/foreach}
						{foreach from=$emptyReviewerSlots item=_emptySlot}
							<td data-grp-page="{$lastPage}"{if $lastPage !== $page} hidden{/if}></td>
						{/foreach}
					</tr>

					<tr class="grpTool__matrixSection">
						<td colspan="{$columnCount}">Other</td>
					</tr>
					<tr>
						<td>{$configuration.text.other_contribution.label|escape}{if $configuration.text.other_contribution.description}<div class="grpTool__matrixHint">{$configuration.text.other_contribution.description|escape}</div>{/if}</td>
						{foreach from=$reviewers item=reviewer}
							<td data-grp-page="{$reviewer.page}"{if $reviewer.page !== $page} hidden{/if}><textarea class="grpTool__matrixTextarea" name="reviewers[{$reviewer.id|intval}][otherContribution]">{$reviewer.otherComments|escape}</textarea></td>
						{/foreach}
						{foreach from=$emptyReviewerSlots item=_emptySlot}
							<td data-grp-page="{$lastPage}"{if $lastPage !== $page} hidden{/if}></td>
						{/foreach}
					</tr>
				</tbody>
			</table>
			</fieldset>

			<div class="grpTool__pagination">
				<div class="grpTool__paginationGroup">
					<button type="button" class="pkpButton grpTool__pageNav" data-grp-page-step="-1"{if $page === 0} disabled{/if}>Previous</button>
					{foreach from=$pages item=pageInfo}
						<span class="grpTool__dots" data-grp-page="{$pageInfo.index}"{if $pageInfo.index !== $page} hidden{/if}>
							{foreach from=$pages item=dot}
								<span class="grpTool__dot{if $dot.index === $pageInfo.index} grpTool__dot--active{/if}"></span>
							{/foreach}
						</span>
					{/foreach}
				</div>
				<div class="grpTool__paginationGroup">
					{foreach from=$pages item=pageInfo}
						<span class="grp__muted grpTool__pageRange" data-grp-page="{$pageInfo.index}"{if $pageInfo.index !== $page} hidden{/if}>{$pageInfo.rangeLabel}</span>
					{/foreach}
					<button type="button" class="pkpButton grpTool__pageNav grpTool__pageNav--next" data-grp-page-step="1"{if $page === $lastPage} disabled{/if}><span>Next</span></button>
				</div>
			</div>

			<label class="grp__field grpTool__generalComments">
				<span>{$configuration.text.general_comments.label|escape}</span>
				{if $configuration.text.general_comments.description}<div class="grp__muted">{$configuration.text.general_comments.description|escape}</div>{/if}
				<textarea name="generalComments"{if $isCancelled} disabled{/if}>{$generalComments|escape}</textarea>
			</label>

			<p class="grp__actions grpTool__formActions">
				<span class="grpTool__formActionsLeft">
					{if !$isCancelled}
						<button type="submit" name="formAction" value="save" class="pkp_button" data-grp-needs-change disabled>Save</button>
					{/if}
					<a class="pkp_button" href="{$cancelUrl|escape}">{translate key="common.back"}</a>
					<button type="button" class="pkp_button" @click="$modal.show('grpParticipationHistory')">{translate key="submission.history"}</button>
					{if $cancelFormUrl}
						<button type="button" class="pkp_button grpTool__cancelButton" @click="$modal.show('grpCancelForm')">{translate key="plugins.generic.groupReview.participation.cancel"}</button>
					{/if}
				</span>
				{if !$isCancelled}
					<pkp-button :is-primary="true" @click="$modal.show('grpParticipationSubmit')">Submit</pkp-button>
				{/if}
			</p>
			</form>
			{if $cancelFormUrl}<form id="grpCancelFormForm" method="post" action="{$cancelFormUrl|escape}" hidden>{csrf}</form>{/if}
			<div class="grp__muted grpTool__lastSaved">
				{if $isSubmitted}
					<div>Submitted {$session.submittedAt|escape} by {$session.submittedBy|escape}{if $session.submissionComment}: {$session.submissionComment|escape|nl2br}{/if}</div>
				{/if}
				{if $session.lastSaved}
					<div>Last saved {$session.lastSaved|escape} by {$session.lastSavedBy|escape}</div>
				{/if}
			</div>
		</div>
	</div>

	<pkp-modal name="grpParticipationHistory" title="{translate key="submission.history"}" close-label="{translate key="common.close"}">
		{foreach from=$history item=entry}
			<p>
				<strong>{translate key="plugins.generic.groupReview.participation.submittedBy" date=$entry.date|escape name=$entry.name|escape}</strong>
				{if $entry.comment}<br>{$entry.comment|escape|nl2br}{/if}
			</p>
		{foreachelse}
			<p>{translate key="plugins.generic.groupReview.participation.history.empty"}</p>
		{/foreach}
	</pkp-modal>
	{if $cancelFormUrl}
	<pkp-modal name="grpCancelForm" title="{translate key="plugins.generic.groupReview.participation.cancel"}" close-label="{translate key="common.close"}">
		<p>{translate key="plugins.generic.groupReview.participation.cancel.confirm"}</p>
		<template slot="footer">
			<pkp-button :is-warnable="true" @click="$modal.hide('grpCancelForm')">{translate key="common.no"}</pkp-button>
			<button type="submit" form="grpCancelFormForm" class="pkpButton pkpButton--isPrimary">{translate key="common.yes"}</button>
		</template>
	</pkp-modal>
	{/if}

	<pkp-modal
		name="grpParticipationSubmit"
		title="Submit"
		close-label="{translate key="common.close"}"
	>
		<label class="grp__field">
			<span>Comments</span>
			<textarea id="grpParticipationModalComments" name="submissionComment" form="grpParticipationForm">{$submissionComment|escape}</textarea>
		</label>
		<template slot="footer">
			<pkp-button :is-warnable="true" @click="$modal.hide('grpParticipationSubmit')">Cancel</pkp-button>
			<button type="submit" form="grpParticipationForm" name="formAction" value="submit" class="pkpButton pkpButton--isPrimary">Submit</button>
		</template>
	</pkp-modal>
	<script>
	{literal}
		/**
		 * Paging: every reviewer is in the form; Previous/Next only show and hide
		 * their columns.
		 * Save: enabled only while the form differs from how it was loaded.
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
			form.querySelectorAll('[data-grp-page-step]').forEach(function (navButton) {
				var target = page + parseInt(navButton.dataset.grpPageStep, 10);
				navButton.disabled = target < 0 || target > lastPage;
			});
		});
	{/literal}
	</script>
{/block}
