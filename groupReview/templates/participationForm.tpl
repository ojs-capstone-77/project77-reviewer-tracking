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

			<div class="grpTool__pagination">
				<div class="grpTool__paginationGroup">
					{if $previousUrl}
						<a class="grpTool__pageNav" href="{$previousUrl|escape}">&larr; Previous</a>
					{else}
						<span class="grpTool__pageNav grpTool__pageNav--disabled">&larr; Previous</span>
					{/if}
					<span class="grpTool__dots">
						{foreach from=$pageDots item=isActive}
							<span class="grpTool__dot{if $isActive} grpTool__dot--active{/if}"></span>
						{/foreach}
					</span>
				</div>
				<div class="grpTool__paginationGroup">
					<span class="grp__muted grpTool__pageRange">{if $reviewersOnPage === 1}Reviewer{else}Reviewers{/if} {$rangeLabel} of {$reviewerCount}</span>
					{if $nextUrl}
						<a class="grpTool__pageNav" href="{$nextUrl|escape}">Next &rarr;</a>
					{else}
						<span class="grpTool__pageNav grpTool__pageNav--disabled">Next &rarr;</span>
					{/if}
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
						{foreach from=$pageReviewers item=reviewer}
							<th>{$reviewer.name|escape}</th>
						{/foreach}
						{foreach from=$emptyReviewerSlots item=_emptySlot}
							<th></th>
						{/foreach}
					</tr>
				</thead>
				<tbody>
					<tr class="grpTool__matrixSection">
						<td colspan="{$columnCount}">Review meeting</td>
					</tr>
					<tr>
						<td>Meeting attendance</td>
						{foreach from=$pageReviewers item=reviewer}
							<td>
								<select class="grpTool__attendanceSelect">
									{foreach from=$attendanceOptions key=optionKey item=optionLabel}
										<option value="{$optionKey}"{if $reviewer.attendance === $optionKey} selected{/if}>{$optionLabel|escape}</option>
									{/foreach}
								</select>
								{if $reviewer.attendance === 'other'}
									<input type="text" class="grpTool__attendanceNote" value="{$reviewer.attendanceNote|escape}">
								{/if}
							</td>
						{/foreach}
						{foreach from=$emptyReviewerSlots item=_emptySlot}
							<td></td>
						{/foreach}
					</tr>
					<tr>
						<td>
							Comments on contributions
							<div class="grpTool__matrixHint">e.g. level of preparedness, interaction with others, depth of contributions. Focus on elements that stand out by their strengths or indicate where professional development might be required; no need to comment on expected levels of contributions.</div>
						</td>
						{foreach from=$pageReviewers item=reviewer}
							<td><textarea class="grpTool__matrixTextarea">{$reviewer.meetingComments|escape}</textarea></td>
						{/foreach}
						{foreach from=$emptyReviewerSlots item=_emptySlot}
							<td></td>
						{/foreach}
					</tr>

					<tr class="grpTool__matrixSection">
						<td colspan="{$columnCount}">Feedback response</td>
					</tr>
					<tr>
						<td>Contributions</td>
						{foreach from=$pageReviewers item=reviewer}
							<td>
								<div class="grpTool__checks grpTool__checks--stacked">
									{assign var="checkedMap" value=$reviewer.contributionChecked}
									{foreach from=$contributionOptions key=optionKey item=optionLabel}
										<label class="grpTool__check">
											<input type="checkbox"{if $checkedMap[$optionKey]} checked{/if}>
											{$optionLabel|escape}
										</label>
									{/foreach}
								</div>
							</td>
						{/foreach}
						{foreach from=$emptyReviewerSlots item=_emptySlot}
							<td></td>
						{/foreach}
					</tr>
					<tr>
						<td>
							Comments on contributions
							<div class="grpTool__matrixHint">Again, focus on elements that stand out by their strengths or indicate where professional development might be required; no need to comment on expected levels of contributions.</div>
						</td>
						{foreach from=$pageReviewers item=reviewer}
							<td><textarea class="grpTool__matrixTextarea">{$reviewer.feedbackComments|escape}</textarea></td>
						{/foreach}
						{foreach from=$emptyReviewerSlots item=_emptySlot}
							<td></td>
						{/foreach}
					</tr>

					<tr class="grpTool__matrixSection">
						<td colspan="{$columnCount}">Other</td>
					</tr>
					<tr>
						<td>Other comments</td>
						{foreach from=$pageReviewers item=reviewer}
							<td><textarea class="grpTool__matrixTextarea">{$reviewer.otherComments|escape}</textarea></td>
						{/foreach}
						{foreach from=$emptyReviewerSlots item=_emptySlot}
							<td></td>
						{/foreach}
					</tr>
				</tbody>
			</table>

			<div class="grpTool__pagination">
				<div class="grpTool__paginationGroup">
					{if $previousUrl}
						<a class="grpTool__pageNav" href="{$previousUrl|escape}">&larr; Previous</a>
					{else}
						<span class="grpTool__pageNav grpTool__pageNav--disabled">&larr; Previous</span>
					{/if}
					<span class="grpTool__dots">
						{foreach from=$pageDots item=isActive}
							<span class="grpTool__dot{if $isActive} grpTool__dot--active{/if}"></span>
						{/foreach}
					</span>
				</div>
				<div class="grpTool__paginationGroup">
					<span class="grp__muted grpTool__pageRange">{if $reviewersOnPage === 1}Reviewer{else}Reviewers{/if} {$rangeLabel} of {$reviewerCount}</span>
					{if $nextUrl}
						<a class="grpTool__pageNav" href="{$nextUrl|escape}">Next &rarr;</a>
					{else}
						<span class="grpTool__pageNav grpTool__pageNav--disabled">Next &rarr;</span>
					{/if}
				</div>
			</div>

			<label class="grp__field grpTool__generalComments">
				<span>General comments for the editors</span>
				<textarea></textarea>
			</label>

			<p class="grp__actions grpTool__formActions">
				<span class="grpTool__formActionsLeft">
					<button type="button" class="pkp_button">Save</button>
					<a class="pkp_button grpTool__cancelButton" href="{$cancelUrl|escape}">Cancel</a>
				</span>
				<pkp-button id="grpParticipationSubmit" :is-primary="true" :is-disabled="{if !$canSubmit}true{else}false{/if}" @click="$modal.show('grpParticipationSubmit')">{$submitLabel}</pkp-button>
			</p>
			<div class="grp__muted grpTool__lastSaved">
				{if $isSubmitted}
					<div>Submitted {$session.submittedAt} by {$session.submittedBy|escape}</div>
				{/if}
				<div>Last saved {$session.lastSaved} by {$session.lastSavedBy|escape}</div>
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
			<textarea id="grpParticipationModalComments"></textarea>
		</label>
		<template slot="footer">
			<pkp-button @click="$modal.hide('grpParticipationSubmit')">Cancel</pkp-button>
			<pkp-button element="a" href="{$submitUrl|escape}" :is-primary="true">{$submitLabel}</pkp-button>
		</template>
	</pkp-modal>
{/block}
