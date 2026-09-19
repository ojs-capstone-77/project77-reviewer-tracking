{**
 * Isolated Group Review tab inserted through Template::Workflow.
 *}
<tab id="groupReview" label="{translate key="plugins.generic.groupReview.workflowTab"}">
	<div class="grp grp--workflow">
		{if $groupReviewPoll}
			<p>{translate key="plugins.generic.groupReview.workflow.existing"}</p>
		{else}
			<p>{translate key="plugins.generic.groupReview.workflow.none"}</p>
		{/if}
		<p class="grp__actions">
			<a class="pkp_button pkp_button_primary" href="{$groupReviewUrl|escape}">
				{if $groupReviewPoll}
					{translate key="plugins.generic.groupReview.workflow.open"}
				{else}
					{translate key="plugins.generic.groupReview.workflow.create"}
				{/if}
			</a>
			<a class="pkp_button" href="{$groupReviewDashboardUrl|escape}">
				{translate key="plugins.generic.groupReview.dashboard.title"}
			</a>
			<a class="pkp_button" href="{$groupReviewParticipationUrl|escape}">
				Record reviewer participation
			</a>
			<a class="pkp_button" href="{$groupReviewEditorMonitoringUrl|escape}">
				Editor monitoring dashboard
			</a>
		</p>
	</div>
</tab>

