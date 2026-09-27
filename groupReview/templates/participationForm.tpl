{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">Reviewer Participation Recording</h1>

	<div class="grpTool__card grpTool__card--wide grpTool__participationForm">
		<div class="grpTool__cardHeader">
			<span>Submission #{$session.submissionId} participation</span>
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
				<div>
					<div class="grpTool__infoLabel">Meeting</div>
					<div class="grpTool__infoValue">{$session.meetingLabel|escape}</div>
				</div>
			</div>

			{if $saveError}
				<div class="grp__notice grp__notice--error">{translate key="plugins.generic.groupReview.participation.error.saveFailed"}</div>
			{/if}

			<form id="grpParticipationForm" method="post" action="{$saveUrl|escape}" data-grp-current-page="{$page|intval}" data-grp-page-count="{$pageCount|intval}">
			{csrf}
			<input type="hidden" name="sessionId" value="{$sessionId|intval}">
			<input type="hidden" name="page" value="{$page|intval}">

			<div class="grpTool__pagination">
				<div class="grpTool__paginationGroup">
					<button type="button" class="grpTool__pageNav{if $page === 0} grpTool__pageNav--disabled{/if}" data-grp-page-step="-1"{if $page === 0} disabled{/if}>Previous</button>
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
					<button type="button" class="grpTool__pageNav grpTool__pageNav--next{if $page === $lastPage} grpTool__pageNav--disabled{/if}" data-grp-page-step="1"{if $page === $lastPage} disabled{/if}><span>Next</span></button>
				</div>
			</div>

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
						<td>Meeting attendance</td>
						{foreach from=$reviewers item=reviewer}
							<td data-grp-page="{$reviewer.page}"{if $reviewer.page !== $page} hidden{/if}>
								<select class="grpTool__attendanceSelect" name="reviewers[{$reviewer.id|intval}][attendance]">
									{foreach from=$attendanceOptions key=optionKey item=optionLabel}
										<option value="{$optionKey}"{if $reviewer.attendance === $optionKey} selected{/if}>{$optionLabel|escape}</option>
									{/foreach}
								</select>
								<input type="text" class="grpTool__attendanceNote" name="reviewers[{$reviewer.id|intval}][attendanceOther]" value="{$reviewer.attendanceNote|escape}"{if $reviewer.attendance !== 'other'} hidden{/if}>
							</td>
						{/foreach}
						{foreach from=$emptyReviewerSlots item=_emptySlot}
							<td data-grp-page="{$lastPage}"{if $lastPage !== $page} hidden{/if}></td>
						{/foreach}
					</tr>
					<tr>
						<td>
							Comments on contributions
							<div class="grpTool__matrixHint">e.g. level of preparedness, interaction with others, depth of contributions. Focus on elements that stand out by their strengths or indicate where professional development might be required; no need to comment on expected levels of contributions.</div>
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
						<td>Contributions</td>
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
							Comments on contributions
							<div class="grpTool__matrixHint">Again, focus on elements that stand out by their strengths or indicate where professional development might be required; no need to comment on expected levels of contributions.</div>
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
						<td>Other comments</td>
						{foreach from=$reviewers item=reviewer}
							<td data-grp-page="{$reviewer.page}"{if $reviewer.page !== $page} hidden{/if}><textarea class="grpTool__matrixTextarea" name="reviewers[{$reviewer.id|intval}][otherContribution]">{$reviewer.otherComments|escape}</textarea></td>
						{/foreach}
						{foreach from=$emptyReviewerSlots item=_emptySlot}
							<td data-grp-page="{$lastPage}"{if $lastPage !== $page} hidden{/if}></td>
						{/foreach}
					</tr>
				</tbody>
			</table>

			<div class="grpTool__pagination">
				<div class="grpTool__paginationGroup">
					<button type="button" class="grpTool__pageNav{if $page === 0} grpTool__pageNav--disabled{/if}" data-grp-page-step="-1"{if $page === 0} disabled{/if}>Previous</button>
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
					<button type="button" class="grpTool__pageNav grpTool__pageNav--next{if $page === $lastPage} grpTool__pageNav--disabled{/if}" data-grp-page-step="1"{if $page === $lastPage} disabled{/if}><span>Next</span></button>
				</div>
			</div>

			<label class="grp__field grpTool__generalComments">
				<span>General comments for the editors</span>
				<textarea name="generalComments">{$generalComments|escape}</textarea>
			</label>

			<p class="grp__actions grpTool__formActions">
				<span class="grpTool__formActionsLeft">
					<button type="submit" name="formAction" value="save" class="pkp_button" data-grp-save disabled>Save</button>
					<a class="pkp_button grpTool__cancelButton" href="{$cancelUrl|escape}">Cancel</a>
				</span>
				{if $isSubmitted}
					<pkp-button :is-primary="true" @click="$modal.show('grpParticipationSubmit')">{$submitLabel}</pkp-button>
				{else}
					<pkp-button :is-primary="true" @click="$modal.show('grpParticipationSubmit')" data-grp-page="{$lastPage}"{if $lastPage !== $page} hidden{/if}>{$submitLabel}</pkp-button>
					<pkp-button :is-primary="true" :is-disabled="true" data-grp-before-last-page{if $lastPage === $page} hidden{/if}>{$submitLabel}</pkp-button>
				{/if}
			</p>
			</form>
			<div class="grp__muted grpTool__lastSaved">
				{if $isSubmitted}
					<div>Submitted {$session.submittedAt|escape} by {$session.submittedBy|escape}</div>
				{/if}
				{if $session.lastSaved}
					<div>Last saved {$session.lastSaved|escape} by {$session.lastSavedBy|escape}</div>
				{/if}
			</div>
		</div>
	</div>

	<pkp-modal
		name="grpParticipationSubmit"
		title="{$submitLabel|escape}"
		close-label="{translate key="common.close"}"
	>
		<label class="grp__field">
			<span>Comments</span>
			<textarea id="grpParticipationModalComments" name="submissionComment" form="grpParticipationForm"></textarea>
		</label>
		<template slot="footer">
			<pkp-button @click="$modal.hide('grpParticipationSubmit')">Cancel</pkp-button>
			<button type="submit" form="grpParticipationForm" name="formAction" value="submit" class="pkpButton pkpButton--isPrimary">{$submitLabel}</button>
		</template>
	</pkp-modal>
{/block}
