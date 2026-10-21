<tab id="reviewerLabels" label="{translate key="plugins.generic.groupReview.labels.tab"}">
	<div class="grp">
		<h3 class="grpMon__sectionHeading">{translate key="plugins.generic.groupReview.labels.tab"}</h3>
		<div class="grp__actions">
			<p class="grp__muted">{translate key="plugins.generic.groupReview.labels.tabDescription"}</p>
			<button type="button" class="pkpButton pkpButton--isPrimary" @click="$modal.show('grpAddLabelType')">{translate key="plugins.generic.groupReview.labels.addType"}</button>
		</div>

		{if $labelsError}
			<div class="grp__notice grp__notice--error">{translate key="plugins.generic.groupReview.labels.saveError"}</div>
		{/if}

		<div class="grp__tableWrap">
			<table class="grp__table">
				<thead>
					<tr>
						<th>{translate key="plugins.generic.groupReview.labels.column.type"}</th>
						<th>{translate key="plugins.generic.groupReview.labels.column.choice"}</th>
						<th>{translate key="plugins.generic.groupReview.labels.column.values"}</th>
						<th>{translate key="plugins.generic.groupReview.labels.column.action"}</th>
					</tr>
				</thead>
				<tbody>
					{foreach from=$labelTypes item=type}
						<tr>
							<td><strong>{$type.name|escape}</strong></td>
							<td>
								{if $type.multiple}
									{translate key="plugins.generic.groupReview.labels.multipleChoice"}
								{else}
									{translate key="plugins.generic.groupReview.labels.singleChoice"}
								{/if}
							</td>
							<td>
								<div class="grpLabels__values">
									{foreach from=$type.values item=value}
										<span class="grpLabels__valueTag">
											{$value.label|escape}
											<form method="post" action="{$removeValueUrl|escape}" class="grpLabels__inlineForm">
												{csrf}
												<input type="hidden" name="typeKey" value="{$type.key|escape}">
												<input type="hidden" name="valueKey" value="{$value.key|escape}">
												<button type="submit" class="grpLabels__valueRemove" aria-label="{translate key="common.remove"}">&times;</button>
											</form>
										</span>
									{/foreach}
									<form method="post" action="{$addValueUrl|escape}" class="grpLabels__inlineForm grpLabels__addValueForm">
										{csrf}
										<input type="hidden" name="typeKey" value="{$type.key|escape}">
										<input type="text" name="label" placeholder="{translate key="plugins.generic.groupReview.labels.addValue"}" class="grpLabels__valueInput">
										<button type="submit" class="grpLabels__addValuePill">+ {translate key="plugins.generic.groupReview.labels.addValue"}</button>
									</form>
								</div>
							</td>
							<td class="grpLabels__actions">
								<button type="button" class="grpLabels__link" @click="$modal.show('grpRenameLabelType_{$type.key|escape}')">{translate key="plugins.generic.groupReview.labels.rename"}</button>
								<button type="button" class="grpLabels__link grpLabels__link--danger" @click="$modal.show('grpRemoveLabelType_{$type.key|escape}')">{translate key="common.remove"}</button>
							</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
		</div>
	</div>

	<pkp-modal
		name="grpAddLabelType"
		title="{translate key="plugins.generic.groupReview.labels.addType"}"
		close-label="{translate key="common.close"}"
	>
		<form id="grpAddLabelTypeForm" class="grp__form" method="post" action="{$addTypeUrl|escape}">
			{csrf}
			<label class="grp__field">
				<span>{translate key="plugins.generic.groupReview.labels.typeName"} *</span>
				<input type="text" name="name" required>
			</label>
			<fieldset class="grp__fieldset">
				<legend>{translate key="plugins.generic.groupReview.labels.choice"} *</legend>
				<label class="grpMon__labelChoice">
					<input type="radio" name="multiple" value="0" checked>
					{translate key="plugins.generic.groupReview.labels.singleChoice"}
				</label>
				<label class="grpMon__labelChoice">
					<input type="radio" name="multiple" value="1">
					{translate key="plugins.generic.groupReview.labels.multipleChoice"}
				</label>
			</fieldset>
			<p class="grp__muted">{translate key="plugins.generic.groupReview.labels.choiceLocked"}</p>
		</form>
		<template slot="footer">
			<pkp-button :is-warnable="true" @click="$modal.hide('grpAddLabelType')">{translate key="common.cancel"}</pkp-button>
			<button type="submit" form="grpAddLabelTypeForm" class="pkpButton pkpButton--isPrimary">{translate key="common.add"}</button>
		</template>
	</pkp-modal>

	{foreach from=$labelTypes item=type}
		<pkp-modal
			name="grpRenameLabelType_{$type.key|escape}"
			title="{translate key="plugins.generic.groupReview.labels.renameType"}"
			close-label="{translate key="common.close"}"
		>
			<form id="grpRenameLabelTypeForm_{$type.key|escape}" class="grp__form" method="post" action="{$renameTypeUrl|escape}">
				{csrf}
				<input type="hidden" name="typeKey" value="{$type.key|escape}">
				<label class="grp__field">
					<span>{translate key="plugins.generic.groupReview.labels.typeName"} *</span>
					<input type="text" name="name" value="{$type.name|escape}" required>
				</label>
				{foreach from=$type.values item=value}
					<label class="grp__field">
						<span>{translate key="plugins.generic.groupReview.labels.valueName"} *</span>
						<input type="text" name="values[{$value.key|escape}]" value="{$value.label|escape}" required>
					</label>
				{/foreach}
			</form>
			<template slot="footer">
				<pkp-button :is-warnable="true" @click="$modal.hide('grpRenameLabelType_{$type.key|escape}')">{translate key="common.cancel"}</pkp-button>
				<button type="submit" form="grpRenameLabelTypeForm_{$type.key|escape}" class="pkpButton pkpButton--isPrimary">{translate key="common.save"}</button>
			</template>
		</pkp-modal>

		<pkp-modal
			name="grpRemoveLabelType_{$type.key|escape}"
			title="{translate key="plugins.generic.groupReview.labels.removeType"}"
			close-label="{translate key="common.close"}"
		>
			<p><strong>{translate key="plugins.generic.groupReview.labels.removeTypeConfirm" name=$type.name|escape}</strong></p>
			<p class="grp__muted">{translate key="plugins.generic.groupReview.labels.removeTypeWarning" reviewerCount=$type.reviewerCount}</p>
			<form id="grpRemoveLabelTypeForm_{$type.key|escape}" method="post" action="{$removeTypeUrl|escape}">
				{csrf}
				<input type="hidden" name="typeKey" value="{$type.key|escape}">
			</form>
			<template slot="footer">
				<pkp-button :is-warnable="true" @click="$modal.hide('grpRemoveLabelType_{$type.key|escape}')">{translate key="common.cancel"}</pkp-button>
				<button type="submit" form="grpRemoveLabelTypeForm_{$type.key|escape}" class="pkpButton pkpButton--isWarnable">{translate key="plugins.generic.groupReview.labels.removeType"}</button>
			</template>
		</pkp-modal>
	{/foreach}
</tab>
