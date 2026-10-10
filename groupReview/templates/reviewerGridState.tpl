<input type="hidden" name="search" value="{$gridFilterParams.search|escape}">
<input type="hidden" name="availableSubmissionId" value="{$gridFilterParams.availableSubmissionId|escape}">
<input type="hidden" name="sort" value="{$gridFilterParams.sort|escape}">
<input type="hidden" name="dir" value="{$gridFilterParams.dir|escape}">
{foreach from=$gridFilterParams.columns item=column}
	<input type="hidden" name="columns[]" value="{$column|escape}">
{/foreach}
{foreach from=$gridFilterParams.labels key=type item=values}
	{foreach from=$values item=value}
		<input type="hidden" name="labels[{$type|escape}][]" value="{$value|escape}">
	{/foreach}
{/foreach}
{foreach from=$gridFilterParams.ranges key=column item=range}
	<input type="hidden" name="ranges[{$column|escape}][min]" value="{$range.min|escape}">
	<input type="hidden" name="ranges[{$column|escape}][max]" value="{$range.max|escape}">
{/foreach}
