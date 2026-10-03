{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		{include file=$monitoringTabsResource activeTab="overview" overviewUrl=$overviewUrl reviewersUrl=$reviewersUrl}

		<div class="pkpTab grpMon__page">
			<div class="grpMon__sectionRow">
				<h2 class="grpMon__sectionHeading">{translate key="plugins.generic.groupReview.monitoring.live"}</h2>
			</div>
			<div class="grpMon__statsColumns">
				<dl class="grp__summary">
					<dt>{translate key="plugins.generic.groupReview.monitoring.totalReviewers"}</dt>
					<dd>{$live.total|intval}</dd>
				</dl>
				<dl class="grp__summary">
					<dt>{translate key="plugins.generic.groupReview.monitoring.leaders"}</dt>
					<dd>{$live.leaders|intval}</dd>
				</dl>
				<dl class="grp__summary">
					<dt>{translate key="plugins.generic.groupReview.monitoring.currentGroups"}</dt>
					<dd>{$live.currentGroups|intval}</dd>
				</dl>
			</div>

			<div class="grpMon__sectionRow">
				<h2 class="grpMon__sectionHeading">{translate key="plugins.generic.groupReview.monitoring.activity"}</h2>
				<form class="grpMon__filters" method="get" action="{$overviewActionUrl|escape}">
					<select class="pkpFormField__input pkpFormField--select__input" name="year" id="grpMonYear" aria-label="{translate key="plugins.generic.groupReview.monitoring.year"}" onchange="this.form.submit()">
						{foreach from=$yearOptions item=yearOption}
							<option value="{$yearOption.value|escape}"{if $year == $yearOption.value} selected{/if}>{$yearOption.label|escape}</option>
						{/foreach}
					</select>
				</form>
			</div>
			<dl class="grpMon__kv grpMon__kv--aligned">
				<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.invited"}</dt><dd>{$activity.invited|intval}</dd></div>
				<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.participated"}</dt><dd>{$activity.participated|intval}</dd></div>
				<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.notSelected"}</dt><dd>{$activity.notSelected|intval}</dd></div>
				<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.inactive"}</dt><dd>{$activity.inactive|intval}</dd></div>
				<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.notInvited"}</dt><dd>{$activity.notInvited|intval}</dd></div>
				<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.reviewGroups"}</dt><dd>{$activity.reviewGroups|intval}</dd></div>
				<div class="grpMon__kvRow"><dt>{translate key="plugins.generic.groupReview.monitoring.completed"}</dt><dd>{$activity.completed|intval}</dd></div>
			</dl>

			<h2 class="grpMon__sectionHeading">{translate key="plugins.generic.groupReview.monitoring.labels"}</h2>
			{foreach from=$labels item=labelGroup}
				<h3 class="grpMon__subheading">{$labelGroup.name|escape}</h3>
				<div class="grp__tableWrap">
					<table class="grpMon__table grpMon__table--labels">
						<thead>
							<tr>
								<th>{translate key="plugins.generic.groupReview.monitoring.labelValue"}</th>
								<th>{translate key="plugins.generic.groupReview.monitoring.labelTotal"}</th>
								<th>{translate key="plugins.generic.groupReview.monitoring.labelActive"}</th>
							</tr>
						</thead>
						<tbody>
							{foreach from=$labelGroup.values item=value}
								<tr>
									<td>{$value.label|escape}</td>
									<td>{$value.total|intval}</td>
									<td>{$value.active|intval}</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
				</div>
			{/foreach}
		</div>
	</div>
{/block}
