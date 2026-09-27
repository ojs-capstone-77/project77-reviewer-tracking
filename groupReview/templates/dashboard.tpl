{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp">
		<h1 class="app__pageHeading">{translate key="plugins.generic.groupReview.dashboard.title"}</h1>
		<p class="app__pageDescription">{translate key="plugins.generic.groupReview.dashboard.description"}</p>

		{if empty($polls)}
			<div class="grp__notice">{translate key="plugins.generic.groupReview.dashboard.empty"}</div>
		{else}
			<div class="grp__tableWrap">
				<table class="grp__table">
					<thead>
						<tr>
							<th>{translate key="submission.title"}</th>
							<th>{translate key="common.status"}</th>
							<th>{translate key="plugins.generic.groupReview.deadline"}</th>
							<th>{translate key="common.action"}</th>
						</tr>
					</thead>
					<tbody>
						{foreach from=$polls item=poll}
							<tr>
								<td>
									<strong>{$poll.submission_title|escape}</strong>
									<div class="grp__muted">
										{if $poll.is_leader}{translate key="plugins.generic.groupReview.role.leader"}{/if}
										{if $poll.is_leader && $poll.is_invited} · {/if}
										{if $poll.is_invited}{translate key="plugins.generic.groupReview.role.member"}{/if}
									</div>
								</td>
								<td><span class="grp__status grp__status--{$poll.status|intval}">{$poll.status_label|escape}</span></td>
								<td>{$poll.deadline_display|escape}</td>
								<td><a class="pkp_button" href="{$poll.action_url|escape}">{translate key="common.view"}</a></td>
							</tr>
						{/foreach}
					</tbody>
				</table>
			</div>
		{/if}
	</div>
{/block}

