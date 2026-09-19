{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp grpTool">
		<h1 class="app__pageHeading">Editor Monitoring Dashboard</h1>

		<div class="grpTool__card">
			<div class="grpTool__cardHeader">
				<span>{$reviewer.name|escape} profile</span>
				<span class="grpTool__bell" aria-hidden="true">&#128276;</span>
			</div>
			<div class="grpTool__cardBody">
				<dl class="grpTool__profile">
					<div class="grpTool__profileRow">
						<dt>Total reviews completed</dt>
						<dd>{$reviewer.totalReviews|escape}</dd>
					</div>
					<div class="grpTool__profileRow">
						<dt>Current load</dt>
						<dd>{$reviewer.currentLoad|escape}</dd>
					</div>
					<div class="grpTool__profileRow">
						<dt>Experience level</dt>
						<dd>{$reviewer.experienceLevel|escape}</dd>
					</div>
					<div class="grpTool__profileRow">
						<dt>Methodology background</dt>
						<dd>{$reviewer.methodology|escape}</dd>
					</div>
					<div class="grpTool__profileRow">
						<dt>Strengths</dt>
						<dd><strong>{$reviewer.strengths|escape}</strong></dd>
					</div>
					<div class="grpTool__profileRow">
						<dt>Development areas</dt>
						<dd><strong>{$reviewer.development|escape}</strong></dd>
					</div>
				</dl>

				<div class="grpTool__sectionLabel">Contribution history</div>
				<ul class="grpTool__history">
					{foreach from=$reviewer.history item=entry}
						<li class="grpTool__historyItem">
							<div class="grpTool__historyHead">
								<span class="grpTool__historyLabel">{$entry.label|escape}</span>
								<span class="grp__muted">{$entry.when|escape}</span>
							</div>
							<div class="grp__muted">{$entry.detail|escape}</div>
						</li>
					{/foreach}
				</ul>

				<p class="grp__actions">
					<button type="button" class="pkp_button">Generate reference</button>
					<a class="pkp_button" href="{$backUrl|escape}">Back to all reviewers</a>
				</p>
			</div>
		</div>
	</div>
{/block}
