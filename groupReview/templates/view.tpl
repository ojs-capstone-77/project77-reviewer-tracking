{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		<h1 class="app__pageHeading">{translate key="plugins.generic.groupReview.view.title"}</h1>
		<p class="app__pageDescription"><strong>{$bundle.submission->getLocalizedTitle()|escape}</strong></p>

		{if $created}<div class="grp__notice grp__notice--success">{translate key="plugins.generic.groupReview.created"}</div>{/if}
		{if $updated}<div class="grp__notice grp__notice--success">{translate key="plugins.generic.groupReview.updated"}</div>{/if}
		{if $finalized}<div class="grp__notice grp__notice--success">{translate key="plugins.generic.groupReview.finalized"}</div>{/if}
		{if $resent}<div class="grp__notice grp__notice--success">{translate key="plugins.generic.groupReview.resent" count=$resent}</div>{/if}
		{if $mailFailures}<div class="grp__notice grp__notice--warning">{translate key="plugins.generic.groupReview.mailFailures" count=$mailFailures}</div>{/if}

		<div class="grp__summary">
			<div><strong>{translate key="common.status"}:</strong> <span class="grp__status grp__status--{$bundle.poll.status|intval}">{$statusLabel|escape}</span></div>
			<div><strong>{translate key="plugins.generic.groupReview.deadline"}:</strong> {$bundle.poll.deadline_display|escape}</div>
			<div><strong>{translate key="plugins.generic.groupReview.timezone"}:</strong> {$bundle.poll.timezone|escape}</div>
			<div><strong>{translate key="plugins.generic.groupReview.duration"}:</strong> {$bundle.poll.meeting_duration_minutes|intval} {translate key="plugins.generic.groupReview.minutes"}</div>
			<div><strong>{translate key="plugins.generic.groupReview.inviteeLink"}:</strong> <a href="{$availabilityUrl|escape}">{$availabilityUrl|escape}</a></div>
		</div>

		<div class="grp__tableWrap">
			<table class="grp__table grp__matrix">
				<thead>
					<tr>
						<th>{translate key="plugins.generic.groupReview.members"}</th>
						{foreach from=$bundle.slots item=slot}<th>{$slot.display|escape}<br><span class="grp__muted">{translate key="plugins.generic.groupReview.until"} {$slot.end_display|escape}<br>{$bundle.poll.meeting_duration_minutes|intval} {translate key="plugins.generic.groupReview.minutes"}</span></th>{/foreach}
					</tr>
				</thead>
				<tbody>
					{foreach from=$bundle.members item=member}
						<tr>
							<th>
								{$member.name|escape}
								{if !$member.responded_at}<span class="grp__muted"> — {translate key="plugins.generic.groupReview.noResponse"}</span>{/if}
								{if $member.selected}<span class="grp__selected">{translate key="plugins.generic.groupReview.selected"}</span>{/if}
							</th>
							{foreach from=$bundle.slots item=slot}
								<td class="grp__answer">
									{if !empty($bundle.availability[$member.user_id][$slot.slot_id])}
										<span aria-label="{translate key="common.yes"}">✓</span>
									{else}
										<span class="grp__muted" aria-label="{translate key="common.no"}">—</span>
									{/if}
								</td>
							{/foreach}
						</tr>
					{/foreach}
				</tbody>
			</table>
		</div>

		{if $bundle.poll.status == 0}
			<form class="grp__form grp__finalize" action="{$selectMeetingMembersUrl|escape}" method="post">
				{csrf}
				<h2>{translate key="plugins.generic.groupReview.finalize.title"}</h2>
				<fieldset class="grp__fieldset">
					<legend>{translate key="plugins.generic.groupReview.selectedSlot"}</legend>
					{foreach from=$bundle.slots item=slot}
						<label class="grp__slotChoice">
							<input type="radio" name="slotId" value="{$slot.slot_id|intval}" required>
							<span><strong>{$slot.display|escape}</strong><small>{$slot.available_user_ids|@count} {translate key="plugins.generic.groupReview.available"}</small></span>
						</label>
					{/foreach}
				</fieldset>
				<button class="pkp_button pkp_button_primary" type="submit">{translate key="plugins.generic.groupReview.selectSlot.submit"}</button>
			</form>

			<div class="grp__actions">
				<a class="pkp_button" href="{$editUrl|escape}">{translate key="common.edit"}</a>
				<form action="{$resendUrl|escape}" method="post">
					{csrf}
					<button class="pkp_button" type="submit">{translate key="plugins.generic.groupReview.resend"}</button>
				</form>
				<form action="{$cancelUrl|escape}" method="post" onsubmit="return confirm('{translate|escape:"javascript" key="plugins.generic.groupReview.cancel.confirm"}');">
					{csrf}
					<button class="pkp_button pkp_button_offset" type="submit">{translate key="common.cancel"}</button>
				</form>
				<a class="pkp_button" href="{$dashboardUrl|escape}">{translate key="plugins.generic.groupReview.dashboard.title"}</a>
			</div>
		{else}
			{if $bundle.poll.status == 1}
				<div class="grp__summary grp__summary--final">
					<h2>{translate key="plugins.generic.groupReview.finalizedDetails"}</h2>
					{foreach from=$bundle.slots item=slot}
						{if $slot.slot_id == $bundle.poll.selected_slot_id}
							<div><strong>{translate key="plugins.generic.groupReview.selectedSlot"}:</strong> {$slot.display|escape} – {$slot.end_display|escape}</div>
						{/if}
					{/foreach}
					{if $bundle.poll.meeting_url}<div><a href="{$bundle.poll.meeting_url|escape}" rel="noopener noreferrer">{translate key="plugins.generic.groupReview.meetingLink"}</a></div>{/if}
					{if $bundle.poll.notes}<div class="grp__notes">{$bundle.poll.notes|escape|nl2br}</div>{/if}
				</div>
			{/if}
			<p><a class="pkp_button" href="{$dashboardUrl|escape}">{translate key="plugins.generic.groupReview.dashboard.title"}</a></p>
		{/if}
	</div>
{/block}
