{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp grpTool">
		<h1 class="app__pageHeading">Reviewer Participation Recording</h1>

		<div class="grpTool__card">
			<div class="grpTool__cardHeader">
				<span>Submission #{$sessionId} attendance</span>
				<span class="grpTool__bell" aria-hidden="true">&#128276;</span>
			</div>
			<div class="grpTool__cardBody">
				<form method="get" action="{$continueUrl|escape}" id="grpAttendanceForm">
					<input type="hidden" name="sessionId" value="{$sessionId|escape}">
					<input type="hidden" name="step" value="0">
					<table class="grp__table grpTool__table">
						<thead><tr><th>Reviewer</th><th>Present</th></tr></thead>
						<tbody>
							{foreach from=$reviewers item=name key=idx}
								<tr>
									<td>{$name|escape}</td>
									<td>
										<label class="grpTool__toggle">
											<input type="checkbox" name="present[]" value="{$idx|escape}">
											<span class="grpTool__toggleTrack"><span class="grpTool__toggleThumb"></span></span>
										</label>
									</td>
								</tr>
							{/foreach}
						</tbody>
					</table>
					<p class="grp__actions">
						<button type="submit" id="grpContinueBtn" class="pkp_button pkp_button_primary" disabled>Continue to contributions</button>
						<a class="pkp_button" href="{$backUrl|escape}">Back to sessions</a>
					</p>
				</form>
			</div>
		</div>
	</div>

<script>
{literal}
document.addEventListener('DOMContentLoaded', function () {
	var form = document.getElementById('grpAttendanceForm');
	var button = document.getElementById('grpContinueBtn');
	if (!form || !button) { return; }
	var checkboxes = form.querySelectorAll('input[type="checkbox"][name="present[]"]');

	function updateButtonState() {
		var anyChecked = false;
		for (var i = 0; i < checkboxes.length; i++) {
			if (checkboxes[i].checked) {
				anyChecked = true;
				break;
			}
		}
		button.disabled = !anyChecked;
	}

	for (var i = 0; i < checkboxes.length; i++) {
		checkboxes[i].addEventListener('change', updateButtonState);
		checkboxes[i].addEventListener('click', updateButtonState);
	}

	updateButtonState();
});
{/literal}
</script>
{/block}
