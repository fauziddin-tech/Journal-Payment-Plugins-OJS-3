<!doctype html>
<html lang="id">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title>{$proofName|escape}</title>
	<style>{literal}html,body{box-sizing:border-box;width:100%;height:100%;margin:0;overflow:hidden;background:#e8eeec}body{display:grid;place-items:center;padding:12px}.proof-image{display:block;max-width:100%;max-height:100%;object-fit:contain;background:#fff;box-shadow:0 8px 30px rgba(0,0,0,.14)}.proof-pdf{display:block;width:100%;height:100%;border:0;background:#fff}{/literal}</style>
</head>
<body>{if $proofIsPdf}<embed class="proof-pdf" src="{$proofUrl|escape}#view=Fit&toolbar=1" type="application/pdf">{else}<img class="proof-image" src="{$proofUrl|escape}" alt="Bukti pembayaran — {$proofName|escape}">{/if}</body>
</html>
