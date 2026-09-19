{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp grpTool">
		<h1 class="app__pageHeading">Editor Monitoring Dashboard</h1>

		<div class="grpTool__card grpTool__card--wide">
			<div class="grpTool__cardHeader">
				<span>ASRHE Journal Test editor</span>
				<span class="grpTool__bell" aria-hidden="true">&#128276;</span>
			</div>
			<div class="grpTool__monitorBody">
				<nav class="grpTool__monitorNav" aria-label="Reviewer filters">
					<a class="grpTool__monitorNavItem{if $filter === 'all'} grpTool__monitorNavItem--active{/if}" href="{$allUrl|escape}">All reviewers</a>
					<a class="grpTool__monitorNavItem{if $filter === 'overloaded'} grpTool__monitorNavItem--active{/if}" href="{$overloadedUrl|escape}">Overloaded</a>
					<a class="grpTool__monitorNavItem{if $filter === 'unresponsive'} grpTool__monitorNavItem--active{/if}" href="{$unresponsiveUrl|escape}">Unresponsive</a>
				</nav>

				<div class="grpTool__monitorContent">
					{if $filter === 'all'}
						<div class="grpTool__monitorToolbar">
							<input type="text" class="grp__search" placeholder="Search reviewers">
							<button type="button" class="pkp_button">Filters</button>
						</div>
					{else}
						<div class="grpTool__filterBar">
							<span class="grpTool__filterChip">
								{if $filter === 'overloaded'}Load &gt; 5{else}No response &gt; 14 days{/if}
								<a href="{$allUrl|escape}" aria-label="Clear filter">&times;</a>
							</span>
							<a class="pkp_button" href="{$allUrl|escape}">Clear filter</a>
						</div>
					{/if}

					<table class="grp__table grpTool__table">
						<thead>
							<tr>
								<th>Reviewer</th>
								<th>Load</th>
								{if $filter === 'all'}
									<th>Response rate</th>
									<th>Selection rate</th>
								{elseif $filter === 'unresponsive'}
									<th>Last activity</th>
								{/if}
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							{foreach from=$reviewers item=reviewer}
								<tr>
									<td>{$reviewer.name|escape}</td>
									<td{if $reviewer.loadHigh} class="grpTool__loadHigh"{/if}>{$reviewer.load|escape}</td>
									{if $filter === 'all'}
										<td>{$reviewer.responseRate|escape}%</td>
										<td>{$reviewer.selectionRate|escape}%</td>
									{elseif $filter === 'unresponsive'}
										<td>{$reviewer.lastActivityDays|escape} days ago</td>
									{/if}
									<td><a href="{$reviewer.viewUrl|escape}">View</a></td>
								</tr>
							{foreachelse}
								<tr>
									<td colspan="5">No reviewers match this filter.</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
{/block}
