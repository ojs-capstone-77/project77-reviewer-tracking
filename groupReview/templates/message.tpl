{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		<h1 class="app__pageHeading">{$pageTitle|escape}</h1>
		<div class="grp__notice grp__notice--warning">{$message|escape}</div>
		<p><a class="pkp_button" href="{$dashboardUrl|escape}">{translate key="plugins.generic.groupReview.dashboard.title"}</a></p>
	</div>
{/block}

