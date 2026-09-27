{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		<h1 class="app__pageHeading">{translate key="plugins.generic.groupReview.invite.title"}</h1>
		<p class="app__pageDescription"><strong>{$bundle.submission->getLocalizedTitle()|escape}</strong></p>
		<p>{translate key="plugins.generic.groupReview.invite.description"}</p>

		<form class="grp__form" action="{$formUrl|escape}" method="post">
			{csrf}
			<fieldset class="grp__fieldset">
				<legend>{translate key="plugins.generic.groupReview.members"}</legend>
				<p class="grp__muted">{translate key="plugins.generic.groupReview.members.help"}</p>
				<input id="grpMemberSearch" class="grp__search" type="search" placeholder="{translate key="common.search"}">
				<div id="grpMemberList" class="grp__members">
					{foreach from=$members item=member}
						<label class="grp__member" data-name="{$member.name|escape} {$member.email|escape}">
							<input type="checkbox" name="memberIds[]" value="{$member.user_id|intval}">
							<span><strong>{$member.name|escape}</strong><small>{$member.email|escape}</small></span>
						</label>
					{foreachelse}
						<p>{translate key="plugins.generic.groupReview.members.none"}</p>
					{/foreach}
				</div>
			</fieldset>
			<p class="grp__actions">
				<button class="pkp_button pkp_button_primary" type="submit">{translate key="plugins.generic.groupReview.invite.submit"}</button>
			</p>
		</form>
		<form action="{$cancelUrl|escape}" method="post" onsubmit="return confirm('{translate|escape:"javascript" key="plugins.generic.groupReview.cancel.confirm"}');">
			{csrf}<button class="pkp_button pkp_button_offset" type="submit">{translate key="common.cancel"}</button>
		</form>
	</div>
	{literal}<script>
	(function () {
		var search = document.getElementById('grpMemberSearch');
		if (!search) return;
		search.addEventListener('input', function () {
			var query = search.value.toLowerCase();
			document.querySelectorAll('#grpMemberList .grp__member').forEach(function (item) {
				item.hidden = item.dataset.name.toLowerCase().indexOf(query) === -1;
			});
		});
	}());
	</script>{/literal}
{/block}
