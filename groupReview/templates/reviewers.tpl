{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		{include file=$monitoringTabsResource activeTab="reviewers" overviewUrl=$overviewUrl reviewersUrl=$reviewersUrl}

		<div class="pkpTab grpMon__page">
			<div class="grpMon__sectionRow">
				<h2 class="grpMon__sectionHeading">{translate key="plugins.generic.groupReview.monitoring.reviewers.title"}</h2>
				<form class="grpMon__filters" method="get" action="{$reviewersActionUrl|escape}">
					<input type="hidden" name="sort" value="{$sort|escape}">
					<input type="hidden" name="dir" value="{$dir|escape}">
					<select class="pkpFormField__input pkpFormField--select__input" name="year" id="grpMonYear" aria-label="{translate key="plugins.generic.groupReview.monitoring.year"}" onchange="this.form.submit()">
						{foreach from=$yearOptions item=yearOption}
							<option value="{$yearOption.value|escape}"{if $year == $yearOption.value} selected{/if}>{$yearOption.label|escape}</option>
						{/foreach}
					</select>
				</form>
			</div>

			{if empty($reviewers)}
				<div class="grp__notice">{translate key="plugins.generic.groupReview.monitoring.reviewers.empty"}</div>
			{else}
				<div class="grp__tableWrap">
					<table class="grpMon__table">
						<thead>
							<tr>
								<th>
									<a class="grpMon__sortLink" href="{$sortUrls.name|escape}">{translate key="plugins.generic.groupReview.monitoring.reviewer"}</a>{if $sort == 'name'}<span class="grpMon__sortArrow">{if $dir == 'asc'}▲{else}▼{/if}</span>{/if}
								</th>
								<th>
									<a class="grpMon__sortLink" href="{$sortUrls.completed|escape}">{translate key="plugins.generic.groupReview.monitoring.completed"}</a>{if $sort == 'completed'}<span class="grpMon__sortArrow">{if $dir == 'asc'}▲{else}▼{/if}</span>{/if}
								</th>
								<th>
									<a class="grpMon__sortLink" href="{$sortUrls.current|escape}">{translate key="plugins.generic.groupReview.monitoring.current"}</a>{if $sort == 'current'}<span class="grpMon__sortArrow">{if $dir == 'asc'}▲{else}▼{/if}</span>{/if}
								</th>
								<th>
									<a class="grpMon__sortLink" href="{$sortUrls.attended|escape}">{translate key="plugins.generic.groupReview.monitoring.attended"}</a>{if $sort == 'attended'}<span class="grpMon__sortArrow">{if $dir == 'asc'}▲{else}▼{/if}</span>{/if}
								</th>
								<th>
									<a class="grpMon__sortLink" href="{$sortUrls.invited|escape}">{translate key="plugins.generic.groupReview.monitoring.invited"}</a>{if $sort == 'invited'}<span class="grpMon__sortArrow">{if $dir == 'asc'}▲{else}▼{/if}</span>{/if}
								</th>
								<th>
									<a class="grpMon__sortLink" href="{$sortUrls.available|escape}">{translate key="plugins.generic.groupReview.monitoring.available"}</a>{if $sort == 'available'}<span class="grpMon__sortArrow">{if $dir == 'asc'}▲{else}▼{/if}</span>{/if}
								</th>
								<th>
									<a class="grpMon__sortLink" href="{$sortUrls.selected|escape}">{translate key="plugins.generic.groupReview.monitoring.selected"}</a>{if $sort == 'selected'}<span class="grpMon__sortArrow">{if $dir == 'asc'}▲{else}▼{/if}</span>{/if}
								</th>
								<th>{translate key="plugins.generic.groupReview.monitoring.action"}</th>
							</tr>
						</thead>
						<tbody>
							{foreach from=$reviewers item=reviewer}
								<tr>
									<td>
										{$reviewer.name|escape}
										{if $reviewer.labelsText}<div class="grpMon__labels">{$reviewer.labelsText|escape}</div>{/if}
									</td>
									<td>{$reviewer.completed|intval}</td>
									<td>{$reviewer.current|intval}</td>
									<td>{$reviewer.attended|intval}{if $reviewer.attendedPercent !== null} <span class="grpMon__percent">({$reviewer.attendedPercent|intval}%)</span>{/if}</td>
									<td>{$reviewer.invited|intval}</td>
									<td>{$reviewer.available|intval}{if $reviewer.availablePercent !== null} <span class="grpMon__percent">({$reviewer.availablePercent|intval}%)</span>{/if}</td>
									<td>{$reviewer.selected|intval}{if $reviewer.selectedPercent !== null} <span class="grpMon__percent">({$reviewer.selectedPercent|intval}%)</span>{/if}</td>
									<td><a class="grpMon__viewLink" href="{$reviewer.url|escape}">{translate key="common.view"}</a></td>
								</tr>
							{/foreach}
						</tbody>
					</table>
				</div>
			{/if}
		</div>
	</div>
{/block}
