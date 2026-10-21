<h1 class="app__pageHeading">{translate key="plugins.generic.groupReview.monitoring.dashboardTitle"}</h1>
<nav class="pkpTabs grpMon__tabs">
	<div class="pkpTabs__buttons">
		{if $isEditor !== false}
			<a class="pkpTabs__button" href="{$overviewUrl|escape}"{if $activeTab == 'overview'} aria-current="page"{/if}>{translate key="plugins.generic.groupReview.monitoring.tabs.overview"}</a>
		{/if}
		<a class="pkpTabs__button" href="{$reviewersUrl|escape}"{if $activeTab == 'reviewers'} aria-current="page"{/if}>{translate key="plugins.generic.groupReview.monitoring.tabs.reviewers"}</a>
	</div>
</nav>
