{include file="frontend/components/header.tpl" pageTitleTranslated=$pageTitleTranslated}
<style>:root {ldelim} --jp-accent: {$jpAccentColor|escape}; {rdelim}</style>
<main class="jp-page" data-theme-mode="{$jpThemeMode|escape}">
	<nav class="jp-tabs" aria-label="Navigasi pembayaran"><a href="{url page="journalPayment" op="submit"}">Kirim Pembayaran</a><a href="{url page="journalPayment" op="status"}">Cek Status</a><a class="is-active" href="{url page="journalPayment" op="documents"}">Cari LOA &amp; Sertifikat</a></nav>
	<section class="jp-card">
		<h1 class="jp-section-title">Cari LOA &amp; Sertifikat</h1>
		<p class="jp-muted">Masukkan ID artikel yang terdaftar pada jurnal ini.</p>
		<form method="get" class="jp-status-form" action="{url page="journalPayment" op="documents"}">
			{if $accessToken}<input type="hidden" name="access" value="{$accessToken|escape}">{/if}
			<label><span>ID Artikel</span><input required inputmode="numeric" pattern="[0-9]+" maxlength="20" name="articleId" value="{$articleId|escape}" placeholder="Contoh: 1765"></label>
			<button class="jp-button" type="submit">Cari Dokumen</button>
		</form>
	</section>
	{if $record}
		<section class="jp-card jp-result jp-document-result">
			<div class="jp-result-head"><div><span class="jp-kicker">ID Artikel {$record->article_id|escape}</span><h2>{$record->payer_name|escape}</h2></div><span class="jp-badge jp-verified">Pembayaran Terverifikasi</span></div>
			{if $record->article_title}<p><strong>Judul Artikel</strong><br>{$record->article_title|escape}</p>{/if}
			{if $record->issue_volume && $record->issue_number && $record->issue_year}<p><strong>Terbitan:</strong> Volume {$record->issue_volume|escape}, Nomor {$record->issue_number|escape}, Tahun {$record->issue_year|escape}</p>{/if}
			{if $record->publication_date}<p><strong>Prediksi/Tanggal Terbit:</strong> {$record->publication_date|date_format:"%d-%m-%Y"}</p>{/if}
			{if !$record->document_access_granted}<p class="jp-muted">Dokumen dilindungi. Buka tautan aman yang dikirim melalui email jurnal untuk melihat atau mengunduhnya.</p>{/if}
			<div class="jp-document-list">
				<div><span>Kuitansi Pembayaran</span><strong>Tersedia</strong>{if $record->document_access_granted}<a class="jp-button jp-document-preview" href="{url page="journalPayment" op="receipt" articleId=$record->article_id access=$record->document_access_token}" data-preview-name="Kuitansi Pembayaran">Lihat Kuitansi</a>{/if}</div>
				<div><span>Letter of Acceptance</span>{if $record->loa_issued_at}<strong class="is-available">Tersedia</strong>{if $record->document_access_granted}<a class="jp-button jp-document-preview" href="{url page="journalPayment" op="loa" articleId=$record->article_id access=$record->document_access_token}" data-preview-name="Letter of Acceptance (LOA)">Lihat LOA</a>{/if}{else}<strong class="is-unavailable">Belum diterbitkan</strong>{/if}</div>
				<div><span>Sertifikat Publikasi</span>{if $record->certificate_issued_at}<strong class="is-available">Tersedia</strong>{if $record->document_access_granted}<a class="jp-button jp-document-preview" href="{url page="journalPayment" op="certificate" articleId=$record->article_id access=$record->document_access_token}" data-preview-name="Sertifikat Publikasi">Lihat Sertifikat</a>{/if}{else}<strong class="is-unavailable">Belum diterbitkan</strong>{/if}</div>
			</div>
		</section>
	{elseif $rateLimited}
		<div class="jp-alert jp-alert-error">Terlalu banyak pencarian. Tunggu beberapa menit lalu coba kembali.</div>
	{elseif $searched}
		<div class="jp-alert jp-alert-error">Dokumen belum ditemukan. Pastikan ID artikel benar dan pembayaran sudah terverifikasi.</div>
	{/if}
</main>
{include file=$journalPaymentPreviewModalTemplate}
{include file="frontend/components/footer.tpl"}
