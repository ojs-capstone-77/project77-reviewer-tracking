{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		<h1 class="app__pageHeading">{translate key="plugins.generic.groupReview.reference.pageTitle"}</h1>

		<div class="grpMon__page">
			<div class="grpMon__reviewerHeader">
				<h2 class="grpMon__reviewerName">{$reviewer.name|escape}</h2>
			</div>

			<div class="grpMon__sectionRow">
				<form class="grpMon__filters" method="get" action="{$referenceUrl|escape}">
					<input type="hidden" name="reviewerId" value="{$reviewer.userId|intval}">
					<select class="pkpFormField__input pkpFormField--select__input" name="year" id="grpReferenceYear" aria-label="{translate key="plugins.generic.groupReview.monitoring.year"}" onchange="this.form.submit()">
						{foreach from=$yearOptions item=yearOption}
							<option value="{$yearOption.value|escape}"{if $year == $yearOption.value} selected{/if}>{$yearOption.label|escape}</option>
						{/foreach}
					</select>
				</form>
			</div>

			<form method="post" action="{$generatePdfUrl|escape}">
				{csrf}
				<input type="hidden" name="reviewerId" value="{$reviewer.userId|intval}">
				<input type="hidden" name="year" value="{$year|escape}">
				<textarea class="grpMon__referenceLetter" name="letterContent">{$letterContent}</textarea>
				<div class="grpMon__referenceActions">
					<button type="submit" class="pkpButton pkpButton--isPrimary">{translate key="plugins.generic.groupReview.reference.generatePdf"}</button>
				</div>
			</form>

			<a class="grpMon__backLink" href="{$backUrl|escape}">{translate key="plugins.generic.groupReview.reference.backToReviewer"}</a>
		</div>
	</div>
{/block}
