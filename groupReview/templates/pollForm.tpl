{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		<h1 class="app__pageHeading">
			{if $editing}{translate key="plugins.generic.groupReview.edit.title"}{else}{translate key="plugins.generic.groupReview.create.title"}{/if}
		</h1>
		{if $submission}
			<p class="app__pageDescription"><strong>{$submission->getLocalizedTitle()|escape}</strong></p>
		{/if}

		{if $errors}
			<div class="grp__notice grp__notice--error" role="alert">
				<strong>{translate key="plugins.generic.groupReview.error.correct"}</strong>
				<ul>
					{foreach from=$errors item=error}<li>{$error|escape}</li>{/foreach}
				</ul>
			</div>
		{/if}
		{if $editing}
			<div class="grp__notice grp__notice--warning">
				{translate key="plugins.generic.groupReview.edit.clearsResponses"}
			</div>
		{/if}

		<form class="grp__form" action="{$formUrl|escape}" method="post">
			{csrf}
			<input type="hidden" name="submissionId" value="{$form.submission_id|intval}">
			<input type="hidden" name="reviewRoundId" value="{$form.review_round_id|intval}">

			<div class="grp__grid">
				<label class="grp__field">
					<span>{translate key="plugins.generic.groupReview.deadline"}</span>
					<input type="datetime-local" name="deadline" value="{$form.deadline|escape}" required>
				</label>
				<label class="grp__field">
					<span>{translate key="plugins.generic.groupReview.timezone"}</span>
					<input type="text" name="timezone" value="{$form.timezone|escape}" maxlength="64" required>
					<small>{translate key="plugins.generic.groupReview.timezone.help"}</small>
				</label>
				<label class="grp__field">
					<span>{translate key="plugins.generic.groupReview.duration"}</span>
					<input type="number" name="duration" value="{$form.duration|intval}" min="5" max="480" required>
				</label>
				<label class="grp__field">
					<span>{translate key="plugins.generic.groupReview.reminderHours"}</span>
					<input type="number" name="reminderHours" value="{$form.reminder_hours|intval}" min="1" max="720" required>
				</label>
			</div>

			<label class="grp__check">
				<input type="checkbox" name="sendReminder" value="1"{if $form.send_reminder} checked{/if}>
				<span>{translate key="plugins.generic.groupReview.sendReminder"}</span>
			</label>

			<label class="grp__field">
				<span>{translate key="plugins.generic.groupReview.meetingUrl"}</span>
				<input type="url" name="meetingUrl" value="{$form.meeting_url|escape}" maxlength="2048" placeholder="https://">
				<small>{translate key="plugins.generic.groupReview.meetingUrl.help"}</small>
			</label>

			<fieldset class="grp__fieldset">
				<legend>{translate key="plugins.generic.groupReview.slots"}</legend>
				<p class="grp__muted">{translate key="plugins.generic.groupReview.slots.help"}</p>
				<div id="grpSlots">
					{foreach from=$form.slots item=slot}
						<div class="grp__slotInput">
							<input type="datetime-local" name="slots[]" value="{$slot|escape}" required>
							<button class="pkp_button grpRemoveSlot" type="button">{translate key="common.remove"}</button>
						</div>
					{/foreach}
				</div>
				<button class="pkp_button" id="grpAddSlot" type="button">{translate key="plugins.generic.groupReview.addSlot"}</button>
			</fieldset>

			{if $editing}<fieldset class="grp__fieldset">
				<legend>{translate key="plugins.generic.groupReview.members"}</legend>
				<p class="grp__muted">{translate key="plugins.generic.groupReview.members.help"}</p>
				<input id="grpMemberSearch" class="grp__search" type="search" placeholder="{translate key="common.search"}">
				<div id="grpMemberList" class="grp__members">
					{foreach from=$members item=member}
						<label class="grp__member" data-name="{$member.name|escape} {$member.email|escape}">
							<input type="checkbox" name="memberIds[]" value="{$member.user_id|intval}"{if isset($selectedLookup[$member.user_id])} checked{/if}>
							<span><strong>{$member.name|escape}</strong><small>{$member.email|escape}</small></span>
						</label>
					{foreachelse}
						<p>{translate key="plugins.generic.groupReview.members.none"}</p>
					{/foreach}
				</div>
			</fieldset>{/if}

			<p class="grp__actions">
				<button class="pkp_button pkp_button_primary" type="submit">
					{if $editing}{translate key="common.save"}{else}{translate key="plugins.generic.groupReview.create.submit"}{/if}
				</button>
				<a class="pkp_button" href="{$dashboardUrl|escape}">{translate key="common.cancel"}</a>
			</p>
		</form>
	</div>

		<script>
		var groupReviewRemoveLabel = '{translate|escape:"javascript" key="common.remove"}';
		{literal}
	(function () {
		var slots = document.getElementById('grpSlots');
		var add = document.getElementById('grpAddSlot');
		if (add && slots) {
			add.addEventListener('click', function () {
				if (slots.querySelectorAll('.grp__slotInput').length >= 20) return;
				var row = document.createElement('div');
				row.className = 'grp__slotInput';
				var input = document.createElement('input');
				input.type = 'datetime-local';
				input.name = 'slots[]';
				input.required = true;
				var remove = document.createElement('button');
				remove.className = 'pkp_button grpRemoveSlot';
				remove.type = 'button';
				remove.textContent = groupReviewRemoveLabel;
				row.appendChild(input);
				row.appendChild(document.createTextNode(' '));
				row.appendChild(remove);
				slots.appendChild(row);
			});
			slots.addEventListener('click', function (event) {
				if (!event.target.classList.contains('grpRemoveSlot')) return;
				if (slots.querySelectorAll('.grp__slotInput').length <= 1) return;
				event.target.closest('.grp__slotInput').remove();
			});
		}
		var search = document.getElementById('grpMemberSearch');
		if (search) {
			search.addEventListener('input', function () {
				var query = search.value.toLowerCase();
				document.querySelectorAll('#grpMemberList .grp__member').forEach(function (item) {
					item.hidden = item.dataset.name.toLowerCase().indexOf(query) === -1;
				});
			});
		}
	}());
	{/literal}
	</script>
{/block}
