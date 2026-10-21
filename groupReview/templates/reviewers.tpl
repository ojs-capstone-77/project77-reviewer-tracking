{extends file="layouts/backend.tpl"}

{block name="page"}
	{capture assign="grpApply"}$nextTick(() => document.getElementById('grpReviewerFilters').submit());{/capture}
	{capture assign="grpReset"}{foreach from=$labelFilters key=type item=definition}grpLabels.{$type} = []; {/foreach}grpFilters = {ldelim}{rdelim}; document.getElementById('grpAvailableSubmission').value = ''; {$grpApply}{/capture}
	<div class="grp">
		{include file=$monitoringTabsResource activeTab="reviewers" overviewUrl=$overviewUrl reviewersUrl=$reviewersUrl}

		<div class="pkpTab">
			{foreach from=$gridFilters.errors item=error}
				<div class="grp__notice grp__notice--error" role="alert">{$error|escape}</div>
			{/foreach}

			<form id="grpReviewerFilters" method="get" action="{$reviewersActionUrl|escape}"></form>
			<input type="hidden" form="grpReviewerFilters" name="sort" value="{$sort|escape}">
			<input type="hidden" form="grpReviewerFilters" name="dir" value="{$dir|escape}">
			<input type="hidden" form="grpReviewerFilters" name="showFilters" :value="grpShowFilters ? 1 : ''">
			<input type="hidden" form="grpReviewerFilters" name="columns[]" value="">
			<input v-for="column in grpColumns" :key="'column' + column" type="hidden" form="grpReviewerFilters" name="columns[]" :value="column">
			{foreach from=$labelFilters key=type item=definition}
				<input v-for="value in grpLabels.{$type}" :key="'{$type|escape}' + value" type="hidden" form="grpReviewerFilters" name="labels[{$type|escape}][]" :value="value">
			{/foreach}
			{foreach from=$columns item=column}
				<input type="hidden" form="grpReviewerFilters" name="ranges[{$column.key|escape}][min]" :value="grpFilters.{$column.key} ? grpFilters.{$column.key}[0] : ''">
				<input type="hidden" form="grpReviewerFilters" name="ranges[{$column.key|escape}][max]" :value="grpFilters.{$column.key} && grpFilters.{$column.key}[1] < {$column.max|intval} ? grpFilters.{$column.key}[1] : ''">
			{/foreach}

			<div class="pkpStats__panel">
				<pkp-header>
					<h2 id="grpReviewersLabel">{translate key="plugins.generic.groupReview.monitoring.reviewers.title"}</h2>
					<template slot="actions">
						<div class="pkpStats__itemsOfTotal">{translate key="plugins.generic.groupReview.filtering.results" resultCount=$reviewers|@count total=$totalReviewers}</div>
						<div class="pkpSearch">
							<label>
								<span class="-screenReader">{translate key="plugins.generic.groupReview.filtering.search"}</span>
								<input type="search" class="pkpSearch__input" form="grpReviewerFilters" name="search" value="{$gridFilters.search|escape}" maxlength="100" placeholder="{translate key="plugins.generic.groupReview.filtering.search"}" @keydown.enter.prevent="{$grpApply}">
								<span class="pkpSearch__icons"><icon icon="search" class="pkpSearch__icons--search"></icon></span>
							</label>
						</div>
						<select class="pkpFormField__input pkpFormField--select__input grpMon__headerSelect" form="grpReviewerFilters" name="year" aria-label="{translate key="plugins.generic.groupReview.monitoring.year"}" @change="{$grpApply}">
							{foreach from=$yearOptions item=yearOption}
								<option value="{$yearOption.value|escape}"{if $year == $yearOption.value} selected{/if}>{$yearOption.label|escape}</option>
							{/foreach}
						</select>
						<pkp-dropdown label="{translate key="plugins.generic.groupReview.filtering.columns"}">
							<ul>
								{foreach from=$columns item=column}
									{assign var="grpColumnKey" value=$column.key|json_encode|escape}
									<li>
										<button type="button" class="pkpDropdown__action" role="menuitemcheckbox" :aria-checked="grpColumns.includes({$grpColumnKey}) ? 'true' : 'false'" @click="grpColumns.includes({$grpColumnKey}) ? grpColumns.splice(grpColumns.indexOf({$grpColumnKey}), 1) : grpColumns.push({$grpColumnKey}); {$grpApply}">
											<icon :icon="grpColumns.includes({$grpColumnKey}) ? 'check-square-o' : 'square-o'" :inline="true"></icon>
											{$column.label|escape}
										</button>
									</li>
								{/foreach}
							</ul>
						</pkp-dropdown>
						<pkp-button :is-active="grpShowFilters" @click="grpShowFilters = !grpShowFilters">
							<icon icon="filter" :inline="true"></icon>
							{translate key="common.filter"}
						</pkp-button>
					</template>
				</pkp-header>

				<div class="listPanel__body">
					<transition name="listPanel__sidebar">
						<div v-show="grpShowFilters" class="listPanel__sidebar">
							<pkp-header :is-one-line="false">
								<h3><icon icon="filter" :inline="true"></icon> {translate key="common.filter"}</h3>
							</pkp-header>
							<div class="listPanel__block">
								<pkp-button :is-primary="true" @click="{$grpApply}">{translate key="plugins.generic.groupReview.filtering.apply"}</pkp-button>
								<pkp-button @click="{$grpReset}">{translate key="common.reset"}</pkp-button>
							</div>
							{foreach from=$labelFilters key=type item=definition}
								<div class="listPanel__block">
									<pkp-header><h4>{$definition.name|escape}</h4></pkp-header>
									{foreach from=$definition.options item=option}
										<pkp-filter
											param="{$type|escape}"
											title="{$option.label|escape}"
											value="{$option.value|escape}"
											:is-filter-active="grpLabels.{$type}.includes({$option.value|json_encode|escape})"
											@add-filter="(param, value) => grpLabels[param].push(value)"
											@remove-filter="(param, value) => grpLabels[param].splice(grpLabels[param].indexOf(value), 1)"
										></pkp-filter>
									{/foreach}
								</div>
							{/foreach}
							<div class="listPanel__block">
								<pkp-header><h4>{translate key="plugins.generic.groupReview.filtering.openPoll"}</h4></pkp-header>
								<label class="-screenReader" for="grpAvailableSubmission">{translate key="plugins.generic.groupReview.filtering.submissionId"}</label>
								<div class="pkpFormField--sizesmall"><input class="pkpFormField__input pkpFormField--text__input" id="grpAvailableSubmission" type="text" inputmode="numeric" form="grpReviewerFilters" name="availableSubmissionId" value="{$gridFilters.availableSubmissionId|escape}" placeholder="{translate key="plugins.generic.groupReview.filtering.submissionId"}" @keydown.enter.prevent="{$grpApply}"></div>
							</div>
							<div class="listPanel__block">
								<pkp-header><h4>{translate key="plugins.generic.groupReview.monitoring.activity"}</h4></pkp-header>
								{foreach from=$columns item=column}
									<pkp-filter-slider-multirange
										param="{$column.key|escape}"
										title="{$column.label|escape}"
										:min="0"
										:max="{$column.max|intval}"
										:value="{$column.value|json_encode|escape}"
										value-label="{translate key="common.range"}"
										more-than-label="{translate key="common.moreThan"}"
										less-than-label="{translate key="common.lessThan"}"
										:is-filter-active="!!grpFilters.{$column.key}"
										@add-filter="(param, value) => $set(grpFilters, param, value)"
										@update-filter="(param, value) => $set(grpFilters, param, value)"
										@remove-filter="(param) => $delete(grpFilters, param)"
									></pkp-filter-slider-multirange>
								{/foreach}
							</div>
						</div>
					</transition>

					<div class="listPanel__items">
						{if empty($reviewers)}
							<div class="pkpStats__noRecords">{if !$isEditor && !$totalReviewers}{translate key="plugins.generic.groupReview.monitoring.reviewers.noneAvailable"}{else}{translate key="plugins.generic.groupReview.filtering.empty"}{/if}</div>
						{else}
							<div class="grp__tableWrap">
								<table class="pkpTable" aria-labelledby="grpReviewersLabel">
									<thead>
										<tr>
											<th scope="col" aria-sort="true"{if $sort == 'name'} class="-isActive"{/if}>
												<button type="submit" form="grpReviewerFilters" name="order" value="{$sortOrders.name|escape}">
													{translate key="plugins.generic.groupReview.monitoring.reviewer"}
													<icon icon="{if $sort == 'name' && $dir == 'asc'}caret-up{else}caret-down{/if}" class="pkpTable__sortIcon"></icon>
												</button>
											</th>
											{foreach from=$columns item=column}
												{if in_array($column.key, $gridFilters.columns)}
													<th scope="col" aria-sort="true"{if $sort == $column.key} class="-isActive"{/if}>
														<button type="submit" form="grpReviewerFilters" name="order" value="{$sortOrders[$column.key]|escape}">
															{$column.label|escape}
															<icon icon="{if $sort == $column.key && $dir == 'asc'}caret-up{else}caret-down{/if}" class="pkpTable__sortIcon"></icon>
														</button>
													</th>
												{/if}
											{/foreach}
										</tr>
									</thead>
									<tbody>
										{foreach from=$reviewers item=reviewer}
											<tr>
												<td>
													{if $reviewer.url}<a href="{$reviewer.url|escape}">{$reviewer.name|escape}</a>{else}{$reviewer.name|escape}{/if}
													{if $reviewer.labelsText}<div class="grpMon__labels">{$reviewer.labelsText|escape}</div>{/if}
												</td>
												{foreach from=$columns item=column}
													{if in_array($column.key, $gridFilters.columns)}
														{assign var="grpValue" value=$reviewer[$column.key]}
														<td>
															{if $grpValue === null}
																<span aria-hidden="true">–</span><span class="-screenReader">{translate key="plugins.generic.groupReview.monitoring.notApplicable"}</span>
															{elseif $column.isPercent}
																{$grpValue|intval}%
															{else}
																{$grpValue|intval}
															{/if}
														</td>
													{/if}
												{/foreach}
											</tr>
										{/foreach}
									</tbody>
								</table>
							</div>
						{/if}
					</div>
				</div>
			</div>
		</div>
	</div>
{/block}
