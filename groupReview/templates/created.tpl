{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		<h1 class="app__pageHeading">{translate key="plugins.generic.groupReview.created.title"}</h1>
		<div class="grp__notice grp__notice--success">{translate key="plugins.generic.groupReview.created"}</div>
		{if $mailFailures}<div class="grp__notice grp__notice--warning">{translate key="plugins.generic.groupReview.mailFailures" count=$mailFailures}</div>{/if}
		<label class="grp__field">
			<span>{translate key="plugins.generic.groupReview.inviteeLink"}</span>
			<input type="text" value="{$pollUrl|escape}" readonly onclick="this.select();">
		</label>
		<p class="grp__actions">
			<a class="pkp_button pkp_button_primary" href="{$backUrl|escape}">{translate key="common.back"}</a>
			<a class="pkp_button" href="{$viewUrl|escape}">{translate key="plugins.generic.groupReview.workflow.open"}</a>
		</p>
	</div>
{/block}
