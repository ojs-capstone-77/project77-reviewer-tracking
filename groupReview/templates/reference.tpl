{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		<h1 class="app__pageHeading">{translate key="plugins.generic.groupReview.reference.pageTitle"}</h1>

		<div class="pkpTab grpMon__page">
			<div class="grpMon__reviewerHeader">
				<h2 class="grpMon__reviewerName">{$reviewer.name|escape}</h2>
			</div>

			<div class="grpMon__sectionRow">
				<form class="grpMon__filters" method="get" action="{$referenceUrl|escape}">
					<input type="hidden" name="reviewerId" value="{$reviewer.userId|intval}">
					<label class="pkpFormFieldLabel" for="grpReferenceYear">{translate key="plugins.generic.groupReview.monitoring.year"}</label>
					<select class="pkpFormField__input pkpFormField--select__input" name="year" id="grpReferenceYear" onchange="this.form.submit()">
						{foreach from=$yearOptions item=yearOption}
							<option value="{$yearOption.value|escape}"{if $year == $yearOption.value} selected{/if}>{$yearOption.label|escape}</option>
						{/foreach}
					</select>
				</form>
			</div>

			<form id="grpReference" method="post" action="{$generatePdfUrl|escape}">
				{csrf}
				<input type="hidden" name="reviewerId" value="{$reviewer.userId|intval}">
				<input type="hidden" name="year" value="{$year|escape}">
				<pkp-field-rich-textarea
					name="letterContent"
					form-id="grpReference"
					label="{translate key="plugins.generic.groupReview.reference.pageTitle"}"
					size="large"
					plugins="paste,link,lists"
					toolbar="bold italic underline | bullist numlist | link"
					:init="{ldelim}content_style: 'ol {ldelim} list-style-type: lower-alpha; {rdelim}'{rdelim}"
					value="{$letterContent|escape}"
					@change="(name, prop, value) => document.getElementById('grpLetterContent').value = value"
				></pkp-field-rich-textarea>
				<input type="hidden" id="grpLetterContent" name="letterContent" value="{$letterContent|escape}">
				<label class="grp__field">
					<span>{translate key="common.fileName"}</span>
					<input type="text" name="fileName" value="{$fileName|escape}" maxlength="255" @keydown.enter.prevent>
				</label>
				<div class="grpMon__referenceActions">
					<button type="submit" class="pkpButton" name="preview" value="1" formtarget="_blank">{translate key="common.preview"}</button>
					<button type="submit" class="pkpButton pkpButton--isPrimary">{translate key="common.download"}</button>
				</div>
			</form>

			<a class="grpMon__backLink" href="{$backUrl|escape}">{translate key="plugins.generic.groupReview.reference.backToReviewer"}</a>
		</div>
	</div>
{/block}
