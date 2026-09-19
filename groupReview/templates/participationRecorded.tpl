{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp grpTool">
		<h1 class="app__pageHeading">Reviewer Participation Recording</h1>

		<div class="grpTool__card">
			<div class="grpTool__cardHeader">
				<span>Submission #{$sessionId} attendance</span>
				<span class="grpTool__bell" aria-hidden="true">&#128276;</span>
			</div>
			<div class="grpTool__cardBody grpTool__confirm">
				<div class="grpTool__checkIcon">&#10003;</div>
				<h2>Recorded</h2>
				<p class="grp__muted">{$count} reviewer{if $count != 1}s{/if} logged for submission #{$sessionId}</p>
				<p class="grp__actions">
					<a class="pkp_button" href="{$backUrl|escape}">Back to sessions</a>
				</p>
			</div>
		</div>
	</div>
{/block}
