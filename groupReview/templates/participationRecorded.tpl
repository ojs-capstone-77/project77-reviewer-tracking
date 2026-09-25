{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">Reviewer Participation Recording</h1>

	<div class="grpTool__card">
		<div class="grpTool__cardHeader">
			<span>Submission #{$session.submissionId} participation</span>
		</div>
		<div class="grpTool__cardBody grpTool__confirm">
			<div class="grpTool__checkIcon">&#10003;</div>
			<h2>Submitted</h2>
			<p class="grp__muted">{$session.lastSaved}</p>
			<p class="grp__actions">
				<a class="pkp_button" href="{$backUrl|escape}">Back to review groups</a>
			</p>
		</div>
	</div>
{/block}
