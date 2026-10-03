{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		{include file=$monitoringTabsResource activeTab="overview" overviewUrl=$overviewUrl reviewersUrl=$reviewersUrl}

		<div class="pkpTab grpMon__page">
			<h2 class="grpMon__sectionHeading">{translate key="plugins.generic.groupReview.monitoring.tabs.overview"}</h2>

			<div class="grpMon__sectionRow">
				<h3 class="grpMon__caps">{translate key="plugins.generic.groupReview.monitoring.reviewers.title"}</h3>
			</div>
			<div class="grpMon__statsColumns">
				<dl class="grpMon__kv grpMon__kv--aligned">
					<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.totalReviewers"}</dt><dd>{$live.total|intval}</dd></div>
					<div class="grpMon__kvRow grpMon__kvRow--indent"><dt>{translate key="plugins.generic.groupReview.monitoring.leaders"}</dt><dd>{$live.leaders|intval}</dd></div>
				</dl>
				<dl class="grpMon__kv grpMon__kv--aligned">
					<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.currentGroups"}</dt><dd>{$live.currentGroups|intval}</dd></div>
				</dl>
			</div>

			<div class="grpMon__sectionRow">
				<h3 class="grpMon__caps">{translate key="plugins.generic.groupReview.monitoring.activity"}</h3>
				<form class="grpMon__filters" method="get" action="{$overviewActionUrl|escape}">
					<select class="pkpFormField__input pkpFormField--select__input" name="year" id="grpMonYear" aria-label="{translate key="plugins.generic.groupReview.monitoring.year"}" onchange="this.form.submit()">
						{foreach from=$yearOptions item=yearOption}
							<option value="{$yearOption.value|escape}"{if $year == $yearOption.value} selected{/if}>{$yearOption.label|escape}</option>
						{/foreach}
					</select>
				</form>
			</div>
			<div class="grpMon__statsColumns">
				<div>
					<dl class="grpMon__kv grpMon__kv--aligned">
						<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.invited"}</dt><dd>{$activity.invited|intval}</dd></div>
						<div class="grpMon__kvRow grpMon__kvRow--indent"><dt>{translate key="plugins.generic.groupReview.monitoring.participated"}</dt><dd>{$activity.participated|intval}</dd></div>
						<div class="grpMon__kvRow grpMon__kvRow--indent"><dt>{translate key="plugins.generic.groupReview.monitoring.notSelected"}</dt><dd>{$activity.notSelected|intval}</dd></div>
						<div class="grpMon__kvRow grpMon__kvRow--indent"><dt>{translate key="plugins.generic.groupReview.monitoring.inactive"}</dt><dd>{$activity.inactive|intval}</dd></div>
					</dl>
					<dl class="grpMon__kv grpMon__kv--aligned grpMon__kv--gap">
						<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.notInvited"}</dt><dd>{$activity.notInvited|intval}</dd></div>
					</dl>
				</div>
				<dl class="grpMon__kv grpMon__kv--aligned">
					<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.reviewGroups"}</dt><dd>{$activity.reviewGroups|intval}</dd></div>
					<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.completed"}</dt><dd>{$activity.completed|intval}</dd></div>
				</dl>
			</div>

			<div class="grpMon__sectionRow">
				<h3 class="grpMon__caps">{translate key="plugins.generic.groupReview.monitoring.labels"}</h3>
			</div>
			<div class="grp__tableWrap">
				<table class="grpMon__table grpMon__table--labels">
					<thead>
						<tr>
							<th><span class="-screenReader">{translate key="plugins.generic.groupReview.monitoring.labelValue"}</span></th>
							<th>{translate key="plugins.generic.groupReview.monitoring.labelTotal"}</th>
							<th>{translate key="plugins.generic.groupReview.monitoring.labelActive"}</th>
						</tr>
					</thead>
					{foreach from=$labels item=labelGroup}
						<tbody>
							<tr>
								<th class="grpMon__labelType" colspan="3" scope="rowgroup">{$labelGroup.name|escape}</th>
							</tr>
							{foreach from=$labelGroup.values item=value}
								<tr>
									<td class="grpMon__labelValue">{$value.label|escape}</td>
									<td>{$value.total|intval}</td>
									<td>{$value.active|intval}</td>
								</tr>
							{/foreach}
						</tbody>
					{/foreach}
				</table>
			</div>
		</div>
	</div>
{/block}
