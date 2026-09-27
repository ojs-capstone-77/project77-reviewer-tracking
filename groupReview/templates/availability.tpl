{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		<h1 class="app__pageHeading">{translate key="plugins.generic.groupReview.availability.title"}</h1>
		<p class="app__pageDescription"><strong>{$bundle.submission->getLocalizedTitle()|escape}</strong></p>

		{if $saved}
			<div class="grp__notice grp__notice--success">{translate key="plugins.generic.groupReview.availability.saved"}</div>
		{/if}

		<div class="grp__summary">
			<div><strong>{translate key="plugins.generic.groupReview.deadline"}:</strong> {$bundle.poll.deadline_display|escape}</div>
			<div><strong>{translate key="plugins.generic.groupReview.duration"}:</strong> {$bundle.poll.meeting_duration_minutes|intval} {translate key="plugins.generic.groupReview.minutes"}</div>
			{if $bundle.leader}<div><strong>{translate key="plugins.generic.groupReview.role.leader"}:</strong> {$bundle.leader->getFullName()|escape}</div>{/if}
		</div>

		{if $bundle.submission->getLocalizedAbstract()}
			<details class="grp__abstract">
				<summary>{translate key="common.abstract"}</summary>
				<div>{$bundle.submission->getLocalizedAbstract()|strip_unsafe_html}</div>
			</details>
		{/if}

		{if $bundle.poll.status == 0}
			<form action="{$formUrl|escape}" method="post">
				{csrf}
				<fieldset class="grp__fieldset">
					<legend>{translate key="plugins.generic.groupReview.availability.select"}</legend>
					<p class="grp__muted">{translate key="plugins.generic.groupReview.availability.emptyAllowed"}</p>
					{foreach from=$bundle.slots item=slot}
						<label class="grp__slotChoice">
							<input type="checkbox" name="slotIds[]" value="{$slot.slot_id|intval}"{if in_array($slot.slot_id, $selectedSlotIds)} checked{/if}>
							<span><strong>{$slot.display|escape}</strong><small>{translate key="plugins.generic.groupReview.until"} {$slot.end_display|escape}</small></span>
						</label>
					{/foreach}
				</fieldset>
				<p class="grp__actions">
					<button class="pkp_button pkp_button_primary" type="submit">{translate key="common.save"}</button>
					<a class="pkp_button" href="{$dashboardUrl|escape}">{translate key="plugins.generic.groupReview.dashboard.title"}</a>
				</p>
			</form>
		{else}
			<div class="grp__notice">{translate key="plugins.generic.groupReview.availability.closed"}</div>
			{if $bundle.poll.status == 1}
				<div class="grp__summary">
					{foreach from=$bundle.slots item=slot}
						{if $slot.slot_id == $bundle.poll.selected_slot_id}
							<div><strong>{translate key="plugins.generic.groupReview.selectedSlot"}:</strong> {$slot.display|escape}</div>
						{/if}
					{/foreach}
					{if $bundle.poll.meeting_url}
						<div><a href="{$bundle.poll.meeting_url|escape}" rel="noopener noreferrer">{translate key="plugins.generic.groupReview.meetingLink"}</a></div>
					{/if}
				</div>
			{/if}
		{/if}
	</div>
{/block}

