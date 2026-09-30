<h1 class="app__pageHeading">{translate key="plugins.generic.groupReview.monitoring.dashboardTitle"}</h1>
<div class="grpMon__tabs">
	<a class="grpMon__tab{if $activeTab == 'overview'} grpMon__tab--active{/if}" href="{$overviewUrl|escape}">{translate key="plugins.generic.groupReview.monitoring.tabs.overview"}</a>
	<a class="grpMon__tab{if $activeTab == 'reviewers'} grpMon__tab--active{/if}" href="{$reviewersUrl|escape}">{translate key="plugins.generic.groupReview.monitoring.tabs.reviewers"}</a>
</div>
