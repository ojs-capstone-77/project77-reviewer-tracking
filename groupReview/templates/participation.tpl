{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">Reviewer Participation Recording</h1>

	<div class="grpTool__card grpTool__card--wide">
		<div class="grpTool__cardHeader">
			<span>{$submissionTitle|escape}</span>
			{if $firstAuthor}<span>{$firstAuthor|escape}</span>{/if}
		</div>
		<div class="grpTool__cardBody">
			{if $createUrl && !$hasReviewers}
				<div class="grp__notice grp__notice--warning">{translate key="plugins.generic.groupReview.participation.create.noReviewers"}</div>
			{/if}
			<div class="grpTool__sectionLabel">Group reviews</div>
			<ul class="grpTool__list">
				{foreach from=$sessions item=session}
					<li class="grpTool__listItem">
						<div>
							<div class="grpTool__listTitle">
								Round {$session.round}
								<span class="grpTool__badge grpTool__badge--{$session.status}">{if $session.status === 'submitted'}Submitted{elseif $session.status === 'cancelled'}Cancelled{else}Draft{/if}</span>
							</div>
							<div class="grp__muted">Led by {$session.leaderName|escape}, {$session.reviewerCount} reviewers</div>
						</div>
						<div class="grpTool__listMeta">
							{if $session.lastSaved}
								<span class="grp__muted">Last saved {$session.lastSaved|escape}</span>
							{/if}
							<a href="{$session.openUrl|escape}" class="grpTool__openLink">Open</a>
						</div>
					</li>
				{foreachelse}
					<li class="grpTool__listItem grp__muted">No review groups yet.</li>
				{/foreach}
			</ul>

			<div class="grp__actions">
				<a class="pkp_button" href="{$backUrl|escape}">Back</a>
				{if $createUrl}
					<form class="grpTool__createForm" method="post" action="{$createUrl|escape}">
						{csrf}
						<input type="hidden" name="submissionId" value="{$submissionId|intval}">
						<button type="submit" class="pkp_button pkp_button_primary"{if !$hasReviewers} disabled{/if}>{translate key="common.create"}</button>
					</form>
				{/if}
			</div>
		</div>
	</div>
{/block}
