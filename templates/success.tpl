{include file="frontend/components/header.tpl" pageTitleTranslated=$pageTitleTranslated}
<style>:root {ldelim} --jp-accent: {$jpAccentColor|escape}; {rdelim}</style>
<main class="jp-page jp-narrow" data-theme-mode="{$jpThemeMode|escape}">
	<section class="jp-card jp-success">
		<div class="jp-success-icon">✓</div>
		<h1>Pembayaran Berhasil Dikirim</h1>
		<p>Bukti pembayaran telah diterima dan menunggu verifikasi pengelola jurnal.</p>
		{if $emailStatus == 'sent'}<div class="jp-alert jp-alert-success" role="status">Email ucapan terima kasih dan petunjuk proses publikasi telah dikirim.</div>{elseif $emailStatus == 'failed'}<div class="jp-alert jp-alert-warning" role="alert">Data pembayaran sudah tersimpan, tetapi email konfirmasi belum berhasil dikirim. Simpan kode pelacakan berikut.</div>{/if}
		<div class="jp-code"><span>Kode Pelacakan</span><strong>{$trackingCode|escape}</strong></div>
		<p class="jp-muted">Simpan kode ini. Status pembayaran dapat diperiksa menggunakan ID artikel.</p>
		<div class="jp-actions"><a class="jp-button" href="{url page="journalPayment" op="status" articleId=$articleId}">Periksa Status</a><a class="jp-button jp-button-secondary" href="{url page="journalPayment" op="submit"}">Pembayaran Baru</a></div>
	</section>
</main>
{include file="frontend/components/footer.tpl"}
