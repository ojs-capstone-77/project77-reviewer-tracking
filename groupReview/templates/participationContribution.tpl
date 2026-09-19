{extends file="layouts/backend.tpl"}

{block name="page"}
	<div class="grp grpTool">
		<h1 class="app__pageHeading">Reviewer Participation Recording</h1>

		<div class="grpTool__card">
			<div class="grpTool__cardHeader">
				<span>{$reviewerName|escape} contribution</span>
				<span class="grpTool__bell" aria-hidden="true">&#128276;</span>
			</div>
			<div class="grpTool__cardBody">
				<form method="get" action="{$formAction|escape}" id="grpContributionForm" class="grp__form">
					<input type="hidden" name="sessionId" value="{$sessionId|escape}">
					<input type="hidden" name="step" value="{$nextStep|escape}">
					{foreach from=$presentIndices item=idx}
						<input type="hidden" name="present[]" value="{$idx|escape}">
					{/foreach}
					{if $isLast}
						<input type="hidden" name="presentCount" value="{$presentCount|escape}">
					{/if}

					<fieldset class="grp__fieldset">
						<legend>Contribution type <span class="grp__muted">select all that apply</span></legend>
						<div class="grpTool__checks">
							<label class="grpTool__check"><input type="checkbox" name="contributionType[]" value="Discussion"> Discussion</label>
							<label class="grpTool__check"><input type="checkbox" name="contributionType[]" value="Writing"> Writing</label>
							<label class="grpTool__check"><input type="checkbox" name="contributionType[]" value="Analysis"> Analysis</label>
							<label class="grpTool__check"><input type="checkbox" name="contributionType[]" value="Editing"> Editing</label>
							<label class="grpTool__check"><input type="checkbox" name="contributionType[]" value="Other"> Other</label>
						</div>
						<div id="grpContributionTypeError" class="grpTool__error" hidden>This field is required</div>
					</fieldset>

					<label class="grp__field">
						<span>Strengths demonstrated</span>
						<input type="text" name="strengths" maxlength="250" placeholder="Clear written feedback">
					</label>

					<label class="grp__field">
						<span>Development opportunities</span>
						<input type="text" name="development" maxlength="250" placeholder="Improve turnaround time">
					</label>

					<p class="grp__actions">
						<button type="submit" id="grpSaveNextBtn" class="pkp_button pkp_button_primary" disabled>{if $isLast}Save &amp; finish{else}Save &amp; next reviewer{/if}</button>
					</p>
				</form>
			</div>
		</div>
	</div>

<script>
{literal}
document.addEventListener('DOMContentLoaded', function () {
	var form = document.getElementById('grpContributionForm');
	var button = document.getElementById('grpSaveNextBtn');
	var errorEl = document.getElementById('grpContributionTypeError');
	if (!form || !button) { return; }
	var checkboxes = form.querySelectorAll('input[type="checkbox"][name="contributionType[]"]');

	function anyTypeChecked() {
		for (var i = 0; i < checkboxes.length; i++) {
			if (checkboxes[i].checked) { return true; }
		}
		return false;
	}

	function updateButtonState() {
		var checked = anyTypeChecked();
		if (checked && errorEl) { errorEl.hidden = true; }
		button.disabled = !checked;
	}

	for (var i = 0; i < checkboxes.length; i++) {
		checkboxes[i].addEventListener('change', updateButtonState);
		checkboxes[i].addEventListener('click', updateButtonState);
	}

	form.addEventListener('submit', function (e) {
		if (!anyTypeChecked()) {
			e.preventDefault();
			if (errorEl) { errorEl.hidden = false; }
		}
	});

	updateButtonState();
});
{/literal}
</script>
{/block}
