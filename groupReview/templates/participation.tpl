{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp grpTool">
		<h1 class="app__pageHeading">Reviewer Participation Recording</h1>

		<div class="grpTool__card">
			<div class="grpTool__cardHeader">
				<span>ASRHE Journal Test &mdash; group leader</span>
				<span class="grpTool__bell" aria-hidden="true">&#128276;</span>
			</div>
			<div class="grpTool__cardBody">
				<div class="grpTool__sectionLabel">Active group reviews</div>
				<ul class="grpTool__list">
					{foreach from=$sessions item=session}
						<li class="grpTool__listItem">
							<div>
								<div class="grpTool__listTitle">Submission #{$session.id} group review</div>
								<div class="grp__muted">{$session.reviewerCount} reviewers assigned &middot; {$session.meetingLabel}</div>
							</div>
							<a href="{$session.attendanceUrl|escape}" class="grpTool__openLink">Open &rarr;</a>
						</li>
					{/foreach}
				</ul>
			</div>
		</div>
	</div>
{/block}
