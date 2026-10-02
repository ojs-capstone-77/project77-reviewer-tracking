{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grpMon">
		{include file=$monitoringTabsResource}

		<div class="grpMon__panel">
			<div class="grpMon__panelHeader">
				<h2 class="grpMon__panelTitle">{translate key="plugins.generic.groupReview.monitoring.overview.title"}</h2>
			</div>

			{* --- Live section: not affected by year --- *}
			<div class="grpMon__section">
				<h3 class="grpMon__sectionLabel">{translate key="plugins.generic.groupReview.monitoring.overview.reviewers"}</h3>
				<div class="grpMon__statGrid">
					<div class="grpMon__stat">
						<span class="grpMon__statLabel">{translate key="plugins.generic.groupReview.monitoring.overview.total"}</span>
						<span class="grpMon__statValue">{$live.total|escape}</span>
					</div>
					<div class="grpMon__stat grpMon__stat--indent">
						<span class="grpMon__statLabel">{translate key="plugins.generic.groupReview.monitoring.overview.leaders"}</span>
						<span class="grpMon__statValue">{$live.leaders|escape}</span>
					</div>
					<div class="grpMon__stat">
						<span class="grpMon__statLabel">{translate key="plugins.generic.groupReview.monitoring.overview.currentGroups"}</span>
						<span class="grpMon__statValue">{$live.currentGroups|escape}</span>
					</div>
				</div>
			</div>

			{* --- Activity section: filtered by year --- *}
			<div class="grpMon__section">
				<div class="grpMon__sectionHeader">
					<h3 class="grpMon__sectionLabel">{translate key="plugins.generic.groupReview.monitoring.overview.activity"}</h3>
					<select class="grpMon__yearSelect" onchange="window.location.href=this.value">
						{foreach from=$yearOptions item=opt}
							<option value="{$overviewUrl|escape}?year={$opt.value|escape:'url'}"{if $opt.value == $year} selected="selected"{/if}>{$opt.label|escape}</option>
						{/foreach}
					</select>
				</div>
				<div class="grpMon__statGrid">
					<div class="grpMon__stat">
						<span class="grpMon__statLabel">{translate key="plugins.generic.groupReview.monitoring.invited"}</span>
						<span class="grpMon__statValue">{$activity.invited|escape}</span>
					</div>
					<div class="grpMon__stat">
						<span class="grpMon__statLabel">{translate key="plugins.generic.groupReview.monitoring.overview.reviewGroups"}</span>
						<span class="grpMon__statValue">{$activity.reviewGroups|escape}</span>
					</div>
					<div class="grpMon__stat grpMon__stat--indent">
						<span class="grpMon__statLabel">{translate key="plugins.generic.groupReview.monitoring.overview.participated"}</span>
						<span class="grpMon__statValue">{$activity.participated|escape}</span>
					</div>
					<div class="grpMon__stat">
						<span class="grpMon__statLabel">{translate key="plugins.generic.groupReview.monitoring.completed"}</span>
						<span class="grpMon__statValue">{$activity.completed|escape}</span>
					</div>
					<div class="grpMon__stat grpMon__stat--indent">
						<span class="grpMon__statLabel">{translate key="plugins.generic.groupReview.monitoring.overview.notSelected"}</span>
						<span class="grpMon__statValue">{$activity.notSelected|escape}</span>
					</div>
					<div class="grpMon__stat grpMon__stat--indent">
						<span class="grpMon__statLabel">{translate key="plugins.generic.groupReview.monitoring.overview.inactive"}</span>
						<span class="grpMon__statValue">{$activity.inactive|escape}</span>
					</div>
					<div class="grpMon__stat">
						<span class="grpMon__statLabel">{translate key="plugins.generic.groupReview.monitoring.overview.notInvited"}</span>
						<span class="grpMon__statValue">{$activity.notInvited|escape}</span>
					</div>
				</div>
			</div>

			{* --- Labels section: Total (live) + Active (selected year) per label type --- *}
			<div class="grpMon__section">
				<h3 class="grpMon__sectionLabel">{translate key="plugins.generic.groupReview.monitoring.overview.labels"}</h3>
				<div class="grpMon__labelGrid">
					{foreach from=$labels item=labelType}
						<div class="grpMon__labelCol">
							<table class="grpMon__labelTable">
								<thead>
									<tr>
										<th>{$labelType.name|escape}</th>
										<th>{translate key="plugins.generic.groupReview.monitoring.overview.labelTotal"}</th>
										<th>{translate key="plugins.generic.groupReview.monitoring.overview.labelActive"}</th>
									</tr>
								</thead>
								<tbody>
									{foreach from=$labelType.values item=val}
										<tr>
											<td>{$val.label|escape}</td>
											<td>{$val.total|escape}</td>
											<td>{$val.active|escape}</td>
										</tr>
									{/foreach}
								</tbody>
							</table>
						</div>
					{/foreach}
				</div>
			</div>
		</div>
	</div>
{/block}
