{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		<h1 class="app__pageHeading">{translate key="plugins.generic.groupReview.reviewMessages.title"}</h1>
		<p class="app__pageDescription"><strong>{$bundle.submission->getLocalizedTitle()|escape}</strong></p>
		<div class="grp__summary"><strong>{translate key="plugins.generic.groupReview.selectedSlot"}:</strong> {$slot.display|escape} – {$slot.end_display|escape}</div>
		<p class="grp__muted">{translate key="plugins.generic.groupReview.reviewMessages.variables"}</p>

		<form class="grp__form" action="{$formUrl|escape}" method="post">
			{csrf}<input type="hidden" name="slotId" value="{$slot.slot_id|intval}">
			{foreach from=$selectedUserIds item=userId}<input type="hidden" name="selectedUserIds[]" value="{$userId|intval}">{/foreach}

			<fieldset class="grp__fieldset">
				<legend>{translate key="plugins.generic.groupReview.reviewMessages.selected"}</legend>
				<ul>{foreach from=$bundle.members item=member}{if isset($selectedLookup[$member.user_id])}<li>{$member.name|escape}</li>{/if}{/foreach}</ul>
				<label class="grp__field"><span>{translate key="emails.body"}</span><textarea name="selectedBody" rows="10" maxlength="100000" required>{$selectedTemplate.body|escape}</textarea></label>
			</fieldset>

			<fieldset class="grp__fieldset">
				<legend>{translate key="plugins.generic.groupReview.reviewMessages.unselected"}</legend>
				<ul>{foreach from=$bundle.members item=member}{if !isset($selectedLookup[$member.user_id])}<li>{$member.name|escape}</li>{/if}{/foreach}</ul>
				<label class="grp__field"><span>{translate key="emails.body"}</span><textarea name="unselectedBody" rows="10" maxlength="100000">{$unselectedTemplate.body|escape}</textarea></label>
			</fieldset>

			<label class="grp__field"><span>{translate key="plugins.generic.groupReview.meetingUrl"}</span><input type="url" name="meetingUrl" value="{$bundle.poll.meeting_url|escape}" maxlength="2048" placeholder="https://"></label>
			<label class="grp__field"><span>{translate key="plugins.generic.groupReview.notes"}</span><textarea name="notes" rows="5" maxlength="4000"></textarea></label>
			<p class="grp__actions">
				<button class="pkp_button pkp_button_primary" type="submit">{translate key="plugins.generic.groupReview.reviewMessages.send"}</button>
				<a class="pkp_button" href="{$backUrl|escape}">{translate key="common.cancel"}</a>
			</p>
		</form>
	</div>
{/block}
