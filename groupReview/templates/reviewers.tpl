{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		{include file=$monitoringTabsResource activeTab="reviewers" overviewUrl=$overviewUrl reviewersUrl=$reviewersUrl}
		<div class="pkpTab grpMon__page">
			<h2 class="grpMon__sectionHeading">{translate key="plugins.generic.groupReview.monitoring.reviewers.title"}</h2>
			{foreach from=$gridFilters.errors item=error}
				<div class="grp__notice grp__notice--error" role="alert">{$error|escape}</div>
			{/foreach}
			<form id="grpReviewerFilters" class="grpMon__gridForm" method="get" action="{$reviewersActionUrl|escape}">
				<input type="hidden" name="sort" value="{$sort|escape}">
				<input type="hidden" name="dir" value="{$dir|escape}">
				<input type="hidden" name="columns[]" value="">
				<div class="grpMon__toolbar">
					<label class="grpMon__search">
						<span class="-screenReader">{translate key="plugins.generic.groupReview.filtering.search"}</span>
						<input class="pkpFormField__input" type="search" name="search" value="{$gridFilters.search|escape}" maxlength="100" placeholder="{translate key="plugins.generic.groupReview.filtering.search"}">
					</label>
					<label>
						<span class="-screenReader">{translate key="plugins.generic.groupReview.monitoring.year"}</span>
						<select class="pkpFormField__input pkpFormField--select__input" name="year" id="grpMonYear">
							{foreach from=$yearOptions item=yearOption}
								<option value="{$yearOption.value|escape}"{if $year == $yearOption.value} selected{/if}>{$yearOption.label|escape}</option>
							{/foreach}
						</select>
					</label>
					<details class="grpMon__columns">
						<summary class="pkpButton">{translate key="plugins.generic.groupReview.filtering.columns"}</summary>
						<fieldset class="grpMon__columnOptions">
							<legend class="-screenReader">{translate key="plugins.generic.groupReview.filtering.columns"}</legend>
							{foreach from=$gridColumns key=column item=label}
								<label><input type="checkbox" name="columns[]" value="{$column|escape}"{if in_array($column, $gridFilters.columns)} checked{/if}> {translate key=$label}</label>
							{/foreach}
						</fieldset>
					</details>
					<button type="submit" class="pkpButton pkpButton--isPrimary">{translate key="plugins.generic.groupReview.filtering.apply"}</button>
				</div>
				<div class="grpMon__gridLayout">
					<details class="grpMon__filterPanel" open>
						<summary class="pkpButton">{translate key="plugins.generic.groupReview.filtering.filters"}</summary>
						<div class="grpMon__filterBody">
							<a class="grpMon__viewLink" href="{$resetFiltersUrl|escape}">{translate key="plugins.generic.groupReview.filtering.clearAll"}</a>
							<fieldset data-grp-filter-group>
								<legend>{translate key="plugins.generic.groupReview.filtering.openPoll"}</legend>
								<label for="grpAvailableSubmission">{translate key="plugins.generic.groupReview.filtering.submissionId"}</label>
								<input id="grpAvailableSubmission" class="pkpFormField__input" type="number" name="availableSubmissionId" min="1" step="1" value="{$gridFilters.availableSubmissionId|escape}">
								<button class="pkpButton grpMon__clear" type="button" data-grp-clear aria-label="{translate key="plugins.generic.groupReview.filtering.clear"}">&times;</button>
							</fieldset>
							{foreach from=$labelFilters key=type item=definition}
								<fieldset data-grp-filter-group>
									<legend>{$definition.name|escape}</legend>
									{foreach from=$definition.options item=option}
										<label class="grpMon__choice"><input type="checkbox" name="labels[{$type|escape}][]" value="{$option.value|escape}"{if in_array($option.value, $gridFilters.labels[$type])} checked{/if}> {$option.label|escape}</label>
									{/foreach}
									{if $definition.multiple}<p class="grp__muted">{translate key="plugins.generic.groupReview.filtering.matchAll"}</p>{/if}
									<button class="pkpButton grpMon__clear" type="button" data-grp-clear aria-label="{translate key="plugins.generic.groupReview.filtering.clear"}">&times;</button>
								</fieldset>
							{/foreach}
							<h3 class="grpMon__subheading">{translate key="plugins.generic.groupReview.monitoring.activity"}</h3>
							{foreach from=$gridColumns key=column item=label}
								<fieldset data-grp-filter-group>
									<legend>{translate key=$label}</legend>
									<div class="grpMon__range">
										<label>{translate key="plugins.generic.groupReview.filtering.min"}<input class="pkpFormField__input" type="number" name="ranges[{$column|escape}][min]" min="0" step="1"{if strpos($column, 'Percent') !== false} max="100"{/if} value="{$gridFilters.ranges[$column].min|escape}"></label>
										<label>{translate key="plugins.generic.groupReview.filtering.max"}<input class="pkpFormField__input" type="number" name="ranges[{$column|escape}][max]" min="0" step="1"{if strpos($column, 'Percent') !== false} max="100"{/if} value="{$gridFilters.ranges[$column].max|escape}"></label>
									</div>
									<button class="pkpButton grpMon__clear" type="button" data-grp-clear aria-label="{translate key="plugins.generic.groupReview.filtering.clear"}">&times;</button>
								</fieldset>
							{/foreach}
							<button type="submit" class="pkpButton pkpButton--isPrimary">{translate key="plugins.generic.groupReview.filtering.apply"}</button>
						</div>
					</details>
					<div class="grpMon__results">
						<p role="status" aria-live="polite">{translate key="plugins.generic.groupReview.filtering.results" resultCount=count($reviewers) total=$totalReviewers}</p>
						{if empty($reviewers)}
							<div class="grp__notice">{translate key="plugins.generic.groupReview.filtering.empty"}</div>
						{else}
							<div class="grp__tableWrap">
								<table class="grpMon__table">
									<thead><tr>
										<th scope="col"><a class="grpMon__sortLink" href="{$sortUrls.name|escape}">{translate key="plugins.generic.groupReview.monitoring.reviewer"}</a>{if $sort == 'name'}<span class="grpMon__sortArrow">{if $dir == 'asc'}&#9650;{else}&#9660;{/if}</span>{/if}</th>
										{foreach from=$gridColumns key=column item=label}
											{if in_array($column, $gridFilters.columns)}
												<th scope="col"><a class="grpMon__sortLink" href="{$sortUrls[$column]|escape}">{translate key=$label}</a>{if $sort == $column}<span class="grpMon__sortArrow">{if $dir == 'asc'}&#9650;{else}&#9660;{/if}</span>{/if}</th>
											{/if}
										{/foreach}
										<th scope="col">{translate key="plugins.generic.groupReview.monitoring.action"}</th>
									</tr></thead>
									<tbody>{foreach from=$reviewers item=reviewer}
										<tr>
											<th scope="row">{$reviewer.name|escape}{if $reviewer.labelsText}<div class="grpMon__labels">{$reviewer.labelsText|escape}</div>{/if}</th>
											{foreach from=$gridColumns key=column item=label}
												{if in_array($column, $gridFilters.columns)}
													<td>{if $reviewer[$column] !== null}{$reviewer[$column]|intval}{if strpos($column, 'Percent') !== false}%{/if}{else}<span class="grp__muted" aria-hidden="true">&ndash;</span><span class="-screenReader">{translate key="plugins.generic.groupReview.monitoring.notApplicable"}</span>{/if}</td>
												{/if}
											{/foreach}
											<td><a class="grpMon__viewLink" href="{$reviewer.url|escape}">{translate key="common.view"}</a></td>
										</tr>
									{/foreach}</tbody>
								</table>
							</div>
						{/if}
					</div>
				</div>
			</form>
		</div>
	</div>
{/block}
