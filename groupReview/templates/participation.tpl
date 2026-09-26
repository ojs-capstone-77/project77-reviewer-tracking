{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">Reviewer Participation Recording</h1>

	<div class="grpTool__card grpTool__card--wide">
		<div class="grpTool__cardHeader">
			<span>Your review groups</span>
		</div>
		<div class="grpTool__cardBody">
			<div class="grpTool__sectionLabel">Group reviews</div>
			<ul class="grpTool__list">
				{foreach from=$sessions item=session}
					<li class="grpTool__listItem">
						<div>
							<div class="grpTool__listTitle">
								Submission #{$session.submissionId} group review
								<span class="grpTool__badge grpTool__badge--{$session.status}">{if $session.status === 'submitted'}Submitted{else}Draft{/if}</span>
							</div>
							<div class="grp__muted">Round {$session.round} &middot; {$session.reviewerCount} reviewers assigned &middot; {$session.meetingLabel|escape}</div>
						</div>
						<div class="grpTool__listMeta">
							{if $session.lastSaved}
								<span class="grp__muted">Last saved {$session.lastSaved|escape}</span>
							{/if}
							<a href="{$session.openUrl|escape}" class="grpTool__openLink">Open &rarr;</a>
						</div>
					</li>
				{foreachelse}
					<li class="grpTool__listItem grp__muted">No review groups yet.</li>
				{/foreach}
			</ul>

			<p class="grp__actions">
				<a class="pkp_button" href="{$backUrl|escape}">Back</a>
			</p>
		</div>
	</div>
{/block}
