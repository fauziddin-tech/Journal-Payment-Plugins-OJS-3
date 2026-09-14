{include file="frontend/components/header.tpl" pageTitleTranslated=$pageTitleTranslated}
<style>:root {ldelim} --jp-accent: {$jpAccentColor|escape}; {rdelim}</style>
<main class="jp-page" data-theme-mode="{$jpThemeMode|escape}">
	<section class="jp-card jp-verification-card {if $valid}is-valid{else}is-invalid{/if}">
		<div class="jp-verification-icon" aria-hidden="true">{if $valid}✓{else}!{/if}</div>
		{if $valid}
			<span class="jp-kicker">Dokumen Resmi Terverifikasi</span>
			<h1>{if $documentType == 'loa'}Letter of Acceptance (LOA){elseif $documentType == 'certificate'}Sertifikat Publikasi{else}Kuitansi Pembayaran{/if}</h1>
			<p>Data berikut harus sama dengan informasi pada PDF yang sedang diperiksa.</p>
			<dl><div><dt>Nomor Dokumen</dt><dd>{$documentRow->document_number|escape}</dd></div><div><dt>Versi</dt><dd>{$documentRow->version_number|escape} — Aktif</dd></div><div><dt>ID Artikel</dt><dd>{$record->article_id|escape}</dd></div><div><dt>Nama</dt><dd>{$record->payer_name|escape}</dd></div>{if $record->article_title}<div><dt>Judul</dt><dd>{$record->article_title|escape}</dd></div>{/if}{if $record->issue_volume && $record->issue_number && $record->issue_year}<div><dt>Terbitan</dt><dd>Volume {$record->issue_volume|escape}, Nomor {$record->issue_number|escape}, Tahun {$record->issue_year|escape}</dd></div>{/if}{if $record->publication_date}<div><dt>{if $documentType == 'loa'}Prediksi Terbit{else}Tanggal Terbit{/if}</dt><dd>{$record->publication_date|date_format:"%d-%m-%Y"}</dd></div>{/if}<div><dt>Proteksi PDF</dt><dd>{$documentRow->protection|escape}</dd></div><div><dt>Fingerprint SHA-256</dt><dd class="jp-fingerprint">{$documentRow->sha256|escape}</dd></div></dl>
		{elseif $verificationFound && $documentRow && $documentRow->status == 'revoked'}
			<span class="jp-kicker">Dokumen Dicabut</span><h1>Versi PDF tidak lagi berlaku</h1><p>Dokumen versi {$documentRow->version_number|escape} telah diganti atau dicabut oleh jurnal. Jangan gunakan PDF ini sebagai dokumen aktif.</p><dl><div><dt>Nomor Dokumen</dt><dd>{$documentRow->document_number|escape}</dd></div><div><dt>ID Artikel</dt><dd>{$record->article_id|escape}</dd></div><div><dt>Nama</dt><dd>{$record->payer_name|escape}</dd></div>{if $documentRow->revoked_at}<div><dt>Dicabut</dt><dd>{$documentRow->revoked_at|date_format:"%d-%m-%Y %H:%M"}</dd></div>{/if}<div><dt>Fingerprint Versi Lama</dt><dd class="jp-fingerprint">{$documentRow->sha256|escape}</dd></div></dl>
		{else}
			<span class="jp-kicker">Verifikasi Gagal</span><h1>Dokumen tidak valid</h1><p>Token tidak dikenali, dokumen belum diterbitkan, atau batas pemeriksaan sementara telah tercapai.</p>
		{/if}
	</section>
</main>
{include file="frontend/components/footer.tpl"}
