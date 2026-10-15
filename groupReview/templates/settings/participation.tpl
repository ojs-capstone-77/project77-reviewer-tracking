{extends file="layouts/backend.tpl"}
{block name="page"}
	<h1 class="app__pageHeading">{translate key="plugins.generic.groupReview.configuration.title"}</h1>
	<p>{translate key="plugins.generic.groupReview.configuration.description"}</p>
	{if $configurationError}<div class="grp__notice grp__notice--error" role="alert">{$configurationError|escape}</div>{/if}
	{if $configurationSaved}<div class="grp__notice" role="status">{translate key="common.changesSaved"}</div>{/if}
	<form method="post" action="{$saveUrl|escape}" class="grpTool__card grpTool__card--wide grpConfig__form">
		{csrf}
		<div class="grpTool__cardBody">
			<fieldset>
				<legend>{translate key="plugins.generic.groupReview.configuration.requiredFields"}</legend>
				<p>{translate key="plugins.generic.groupReview.configuration.requiredHint"}</p>
				{foreach from=$configurableFields key=field item=label}
					<label class="grpTool__check">
						<input type="checkbox" name="requiredFields[]" value="{$field|escape}"{if $configuration.fields[$field]} checked{/if}>
						{$label|escape}
					</label>
				{/foreach}
			</fieldset>
			<p>{translate key="plugins.generic.groupReview.configuration.locked"}</p>
			<fieldset>
				<legend>{translate key="plugins.generic.groupReview.configuration.options"}</legend>
				<div id="grpConfigurationOptions" data-option-label="{translate key="plugins.generic.groupReview.configuration.optionName"}" data-remove-label="{translate key="common.remove"}">
					{foreach from=$configuration.options key=id item=label name=options}
						<div class="grpConfig__option">
							<input type="hidden" name="options[{$smarty.foreach.options.index}][id]" value="{$id|escape}">
							<label>{translate key="plugins.generic.groupReview.configuration.optionName"}
								<input class="pkpFormField__input" type="text" name="options[{$smarty.foreach.options.index}][label]" value="{$label|escape}" required maxlength="150">
							</label>
							<button type="button" class="pkpButton" data-grp-remove-option>{translate key="common.remove"}</button>
						</div>
					{/foreach}
				</div>
				<button type="button" class="pkpButton" id="grpAddConfigurationOption">{translate key="common.add"}</button>
			</fieldset>
			<p><button type="submit" class="pkpButton pkpButton--isPrimary">{translate key="common.save"}</button></p>
		</div>
	</form>
	<script>
	{literal}
	document.addEventListener('click', function (event) {
		if (event.target.matches('[data-grp-remove-option]')) {
			event.target.closest('.grpConfig__option').remove();
		}
		if (event.target.id === 'grpAddConfigurationOption') {
			var container = document.getElementById('grpConfigurationOptions');
			var row = document.createElement('div');
			row.className = 'grpConfig__option';
			var label = document.createElement('label');
			label.appendChild(document.createTextNode(container.dataset.optionLabel));
			var input = document.createElement('input');
			input.className = 'pkpFormField__input';
			input.type = 'text';
			input.required = true;
			input.maxLength = 150;
			label.appendChild(input);
			row.appendChild(label);
			var remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'pkpButton';
			remove.setAttribute('data-grp-remove-option', '');
			remove.textContent = container.dataset.removeLabel;
			row.appendChild(remove);
			var indices = Array.from(container.querySelectorAll('input[name]')).map(function (input) {
				return Number(input.name.match(/\[(\d+)\]/)[1]);
			});
			input.name = 'options[' + (Math.max(-1, ...indices) + 1) + '][label]';
			container.appendChild(row);
			container.lastElementChild.querySelector('input[type="text"]').focus();
		}
	});
	{/literal}
	</script>
{/block}
