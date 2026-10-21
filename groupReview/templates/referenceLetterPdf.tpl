<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<style>
		@page { margin: 2.54cm 3.17cm; }
		body { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; line-height: 11.21pt; color: #000; }
		.letterhead { position: relative; height: 2.26cm; }
		.letterhead img { position: absolute; top: -1.634cm; left: -0.058cm; width: 14.7cm; }
		p { margin: 0 0 7.35pt; }
		ol, ul { margin: 8.5pt 0 12.6pt 2.9cm; padding: 0; line-height: 10.41pt; }
		ol { list-style-type: lower-alpha; }
		a { color: #0563c1; text-decoration: underline; }
	</style>
</head>
<body>
	<div class="letterhead">
		{if $letterhead}<img src="{$letterhead.src|escape}" alt="{$letterhead.alt|escape}">{/if}
	</div>
	{$letterHtml}
</body>
</html>
