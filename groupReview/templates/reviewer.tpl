{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		<h1 class="app__pageHeading">{translate key="plugins.generic.groupReview.monitoring.dashboardTitle"}</h1>

		<div class="grpMon__page">
			<div class="grpMon__reviewerHeader">
				<h2 class="grpMon__reviewerName">{$reviewer.name|escape}</h2>
				<button type="button" class="pkpButton pkpButton--isPrimary" @click="$modal.show('grpEditLabels')">{translate key="plugins.generic.groupReview.monitoring.editLabels"}</button>
			</div>

			<dl class="grpMon__kv grpMon__kv--aligned">
				{foreach from=$reviewer.labels item=label}
					<div class="grpMon__kvRow"><dt>{$label.name|escape}</dt><dd>{$label.valuesText|escape}</dd></div>
				{/foreach}
				<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.reviewerSince"}</dt><dd>{$reviewer.reviewerSince|escape}</dd></div>
				<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.lastActivity"}</dt><dd>{$reviewer.lastActivity|escape}</dd></div>
			</dl>

			<div class="grpMon__sectionRow">
				<h3 class="grpMon__caps">{translate key="plugins.generic.groupReview.monitoring.activity"}</h3>
				<form class="grpMon__filters" method="get" action="{$reviewerUrl|escape}">
					<input type="hidden" name="reviewerId" value="{$reviewer.userId|intval}">
					<select name="year" id="grpMonYear" aria-label="{translate key="plugins.generic.groupReview.monitoring.year"}" onchange="this.form.submit()">
						<option value=""{if $year === null} selected{/if}>{translate key="plugins.generic.groupReview.monitoring.year.allTime"}</option>
						{foreach from=$yearOptions item=yearOption}
							<option value="{$yearOption|escape}"{if $year == $yearOption} selected{/if}>{$yearOption|escape}</option>
						{/foreach}
					</select>
				</form>
			</div>

			<div class="grpMon__statsColumns">
				<div>
					<dl class="grpMon__kv grpMon__kv--aligned">
						<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.invited"}</dt><dd>{$stats.invited|intval}</dd></div>
						<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.available"}</dt><dd>{$stats.available|intval}</dd></div>
						<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.selected"}</dt><dd>{$stats.selected|intval}</dd></div>
					</dl>
					<dl class="grpMon__kv grpMon__kv--aligned grpMon__kv--gap">
						<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.notSelected"}</dt><dd>{$stats.notSelected|intval}</dd></div>
						<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.notAvailable"}</dt><dd>{$stats.notAvailable|intval}</dd></div>
						<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.noResponse"}</dt><dd>{$stats.noResponse|intval}</dd></div>
					</dl>
				</div>
				<div>
					<dl class="grpMon__kv grpMon__kv--aligned">
						<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.completed"}</dt><dd>{$stats.completed|intval}</dd></div>
						<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.current"}</dt><dd>{$stats.current|intval}</dd></div>
						<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.timesLed"}</dt><dd>{$stats.timesLed|intval}</dd></div>
					</dl>
				</div>
			</div>

			<div class="grpMon__twoCol">
				<div>
					<h3 class="grpMon__subheading">{translate key="plugins.generic.groupReview.monitoring.attendanceCounts"}</h3>
					{if empty($attendanceCounts)}
						<p class="grp__muted">{translate key="plugins.generic.groupReview.monitoring.noCounts"}</p>
					{else}
						<dl class="grpMon__kv grpMon__kv--aligned">
							{foreach from=$attendanceCounts item=count}
								<div class="grpMon__kvRow"><dt>{$count.label|escape}</dt><dd>{$count.count|intval}</dd></div>
							{/foreach}
						</dl>
					{/if}
				</div>
				<div>
					<h3 class="grpMon__subheading">{translate key="plugins.generic.groupReview.monitoring.contributionCounts"}</h3>
					{if empty($contributionCounts)}
						<p class="grp__muted">{translate key="plugins.generic.groupReview.monitoring.noCounts"}</p>
					{else}
						<dl class="grpMon__kv grpMon__kv--aligned">
							{foreach from=$contributionCounts item=count}
								<div class="grpMon__kvRow"><dt>{$count.label|escape}</dt><dd>{$count.count|intval}</dd></div>
							{/foreach}
						</dl>
					{/if}
				</div>
			</div>

			<h3 class="grpMon__caps">{translate key="plugins.generic.groupReview.monitoring.history"}</h3>
			{if empty($history)}
				<p class="grp__muted">{translate key="plugins.generic.groupReview.monitoring.history.empty"}</p>
			{else}
				<div class="grpMon__historyList">
					{foreach from=$history item=entry}
						<details class="grpMon__historyRow">
							<summary class="grpMon__historySummary">
								<a class="grpMon__historyLink" href="{$entry.submissionUrl|escape}">{translate key="plugins.generic.groupReview.monitoring.history.submission"}{$entry.submissionId|intval} {translate key="plugins.generic.groupReview.monitoring.history.groupReviewLabel"}</a>
								<span class="grpMon__historyRound">{translate key="plugins.generic.groupReview.monitoring.history.round"} {$entry.round|intval}</span>
								{if $entry.isLeader}
									<span class="grpMon__pill">{translate key="plugins.generic.groupReview.monitoring.history.groupLeader"}</span>
								{else}
									<span class="grpMon__historyLed">{translate key="plugins.generic.groupReview.monitoring.history.ledBy"} {$entry.leaderName|escape}</span>
								{/if}
								<span class="grpMon__historyDate">{$entry.date|escape}</span>
							</summary>
							<div class="grpMon__historyBody">
								{if $entry.isLeader}
									<h4 class="grpMon__historySection">{translate key="plugins.generic.groupReview.monitoring.history.generalComments"}</h4>
									{if $entry.generalComments}
										<p>{$entry.generalComments|escape|nl2br}</p>
									{else}
										<p class="grp__muted">{translate key="plugins.generic.groupReview.monitoring.history.noAnswers"}</p>
									{/if}
								{elseif empty($entry.sections)}
									<p class="grp__muted">{translate key="plugins.generic.groupReview.monitoring.history.noAnswers"}</p>
								{else}
									{foreach from=$entry.sections item=section}
										<h4 class="grpMon__historySection">{$section.section|escape}</h4>
										<dl class="grpMon__kv grpMon__kv--aligned">
											{foreach from=$section.rows item=row}
												<div class="grpMon__kvRow"><dt>{$row.label|escape}</dt><dd>{$row.value|escape|nl2br}</dd></div>
											{/foreach}
										</dl>
									{/foreach}
								{/if}
							</div>
						</details>
					{/foreach}
				</div>
			{/if}

			<a class="grpMon__backLink" href="{$backUrl|escape}">{translate key="plugins.generic.groupReview.monitoring.backToReviewers"}</a>

			<pkp-modal
				name="grpEditLabels"
				title="{translate key="plugins.generic.groupReview.labels.editTitle"}"
				close-label="{translate key="common.close"}"
			>
				<form id="grpEditLabelsForm" action="{$saveLabelsUrl|escape}" method="post">
					{csrf}
					<input type="hidden" name="reviewerId" value="{$reviewer.userId|intval}">
					<input type="hidden" name="year" value="{$year|escape}">

					<div class="grp__grid">
						{foreach from=$labelOptions key=type item=definition}
							{if !$definition.multiple}
								<label class="grp__field">
									<span>{$definition.name|escape}</span>
									<select name="{$type|escape}">
										<option value=""{if empty($labelValues[$type])} selected{/if}>{translate key="plugins.generic.groupReview.labels.notSetOption"}</option>
										{foreach from=$definition.options item=option}
											<option value="{$option.value|escape}"{if in_array($option.value, $labelValues[$type])} selected{/if}>{$option.label|escape}</option>
										{/foreach}
									</select>
								</label>
							{/if}
						{/foreach}
					</div>

					{foreach from=$labelOptions key=type item=definition}
						{if $definition.multiple}
							<div class="grp__field">
								<span>{$definition.name|escape}</span>
								<div class="grpMon__labelChecks">
									{foreach from=$definition.options item=option}
										<label class="grpMon__labelChoice">
											<input type="checkbox" name="{$type|escape}[]" value="{$option.value|escape}"{if in_array($option.value, $labelValues[$type])} checked{/if}>
											{$option.label|escape}
										</label>
									{/foreach}
								</div>
							</div>
						{/if}
					{/foreach}

					<label class="grp__field">
						<span>{translate key="plugins.generic.groupReview.labels.note"}</span>
						<textarea name="note"></textarea>
					</label>

					<h3 class="grpMon__subheading">{translate key="plugins.generic.groupReview.labels.changeHistory"}</h3>
					{if empty($labelHistory)}
						<p class="grp__muted">{translate key="plugins.generic.groupReview.labels.changeHistory.empty"}</p>
					{else}
						<div class="grp__tableWrap">
							<table class="grpMon__table">
								<thead>
									<tr>
										<th>{translate key="plugins.generic.groupReview.labels.changeHistory.date"}</th>
										<th>{translate key="plugins.generic.groupReview.labels.changeHistory.user"}</th>
										<th>{translate key="plugins.generic.groupReview.labels.changeHistory.change"}</th>
									</tr>
								</thead>
								<tbody>
									{foreach from=$labelHistory item=change}
										<tr>
											<td>{$change.changedAt|escape}</td>
											<td>{$change.changedByName|escape}</td>
											<td>{$change.summary|escape}{if $change.note} &quot;{$change.note|escape|nl2br}&quot;{/if}</td>
										</tr>
									{/foreach}
								</tbody>
							</table>
						</div>
					{/if}
				</form>
				<template slot="footer">
					<button type="submit" form="grpEditLabelsForm" class="pkpButton pkpButton--isPrimary">{translate key="common.save"}</button>
					<pkp-button :is-warnable="true" @click="$modal.hide('grpEditLabels')">{translate key="common.cancel"}</pkp-button>
				</template>
			</pkp-modal>
		</div>
	</div>
{/block}
