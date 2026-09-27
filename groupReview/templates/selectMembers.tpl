{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		<h1 class="app__pageHeading">{translate key="plugins.generic.groupReview.selectMembers.title"}</h1>
		<p class="app__pageDescription"><strong>{$bundle.submission->getLocalizedTitle()|escape}</strong></p>
		<div class="grp__summary"><strong>{translate key="plugins.generic.groupReview.selectedSlot"}:</strong> {$slot.display|escape} – {$slot.end_display|escape}</div>
		<p>{translate key="plugins.generic.groupReview.selectMembers.description"}</p>
		<form class="grp__form" action="{$formUrl|escape}" method="post">
			{csrf}<input type="hidden" name="slotId" value="{$slot.slot_id|intval}">
			<fieldset class="grp__fieldset">
				<legend>{translate key="plugins.generic.groupReview.selectedMembers"}</legend>
				{if empty($slot.available_user_ids)}<p>{translate key="plugins.generic.groupReview.selectMembers.none"}</p>{/if}
				{foreach from=$bundle.members item=member}
					{if isset($availableLookup[$member.user_id])}
						<label class="grp__check">
							<input type="checkbox" name="selectedUserIds[]" value="{$member.user_id|intval}" checked>
							<span>{$member.name|escape}</span>
						</label>
					{/if}
				{/foreach}
			</fieldset>
			<p class="grp__actions">
				<button class="pkp_button pkp_button_primary" type="submit">{translate key="plugins.generic.groupReview.selectMembers.submit"}</button>
				<a class="pkp_button" href="{$backUrl|escape}">{translate key="common.back"}</a>
			</p>
		</form>
	</div>
{/block}
