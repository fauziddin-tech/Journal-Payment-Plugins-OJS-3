{include file="frontend/components/header.tpl" pageTitleTranslated=$pageTitleTranslated}
<style>:root {ldelim} --jp-accent: {$jpAccentColor|escape}; {rdelim}</style>
<main class="jp-page" data-theme-mode="{$jpThemeMode|escape}">
	<nav class="jp-tabs" aria-label="Navigasi pembayaran"><a href="{url page="journalPayment" op="submit"}">Kirim Pembayaran</a><a class="is-active" href="{url page="journalPayment" op="status"}">Cek Status</a><a href="{url page="journalPayment" op="documents"}">Cari LOA &amp; Sertifikat</a></nav>
	<section class="jp-card">
		<h1 class="jp-section-title">Perjalanan Publikasi Artikel</h1>
		<p class="jp-muted">Masukkan ID artikel untuk melihat status aktual, tindakan berikutnya, tenggat, dan dokumen publikasi.</p>
		<form method="get" class="jp-status-form" action="{url page="journalPayment" op="status"}">
			{if $accessToken}<input type="hidden" name="access" value="{$accessToken|escape}">{/if}
			<label><span>ID Artikel</span><input required inputmode="numeric" pattern="[0-9]+" maxlength="20" name="articleId" value="{$articleId|escape}" placeholder="Contoh: 12345"></label>
			<button class="jp-button" type="submit">Periksa Status</button>
		</form>
	</section>

	{if $record}
		<section class="jp-card jp-result jp-journey-card">
			<div class="jp-result-head"><div><span class="jp-kicker">{$record->tracking_code|escape} · ID {$record->article_id|escape}</span><h2>{$record->payer_name|escape}</h2>{if $record->article_title}<p>{$record->article_title|escape}</p>{/if}</div><div class="jp-status-stack"><span class="jp-editorial-label jp-editorial-{$record->editorial_status_key|escape}">{$record->editorial_status_label|escape}</span><span class="jp-urgency jp-urgency-{$record->urgency_key|escape}">{$record->urgency_label|escape}</span></div></div>

			<ol class="jp-journey" aria-label="Tahapan perjalanan publikasi">
				{foreach from=$record->journey item=step}<li class="is-{$step.state|escape}"><span aria-hidden="true">{if $step.state == 'complete'}✓{elseif $step.state == 'stopped'}!{else}{$step@iteration}{/if}</span><strong>{$step.label|escape}</strong></li>{/foreach}
			</ol>
			{if $record->urgency_key == 'late'}<div class="jp-alert jp-alert-error jp-risk-alert"><strong>Perlu tindakan segera.</strong> {$record->urgency_note|escape}. Silakan selesaikan tindakan berikutnya atau hubungi pengelola jurnal.</div>{elseif $record->urgency_key == 'attention'}<div class="jp-alert jp-alert-warning jp-risk-alert"><strong>Target publikasi sudah dekat.</strong> {$record->urgency_note|escape}. Pastikan semua tahapan berikutnya segera diselesaikan.</div>{/if}

			<div class="jp-journey-overview">
				<section class="jp-next-action"><span class="jp-kicker">Tindakan Berikutnya · {$record->next_action_actor|escape}</span><h3>{$record->next_action_title|escape}</h3><p>{$record->next_action_description|escape}</p>{if $record->action_due_date}<strong class="jp-deadline">Tenggat: {$record->action_due_date|jp_date:"d-m-Y"}</strong>{/if}</section>
				<dl><div><dt>Status Pembayaran</dt><dd>{if $record->status == 'verified'}Terverifikasi{elseif $record->status == 'rejected'}Perlu Perbaikan{else}Menunggu Verifikasi{/if}</dd></div><div><dt>Paket</dt><dd>{$record->package_name|escape}</dd></div>{if $record->publication_date}<div><dt>Target Publikasi</dt><dd>{$record->publication_date|jp_date:"d-m-Y"}</dd></div>{/if}{if $record->issue_volume && $record->issue_number && $record->issue_year}<div><dt>Rencana Terbitan</dt><dd>Vol. {$record->issue_volume|escape} No. {$record->issue_number|escape} ({$record->issue_year|escape})</dd></div>{/if}</dl>
			</div>

			<section class="jp-publication-checklist"><h3>Checklist Publikasi</h3><ul>{foreach from=$record->checklist item=item}<li class="is-{$item.state|escape}"><span aria-hidden="true">{if $item.state == 'complete'}✓{elseif $item.state == 'current'}•{else}○{/if}</span>{$item.label|escape}</li>{/foreach}</ul></section>

			{if $record->proofreading}<section class="jp-public-proofreading"><div><span class="jp-kicker">Proofreading Galley Akhir</span><h3>{$record->proofreading_status_label|escape}</h3><p>Versi {$record->proofreading->version_number|escape} · diunggah {$record->proofreading->uploaded_at|jp_date:"d-m-Y H:i"}{if $record->proofreading->responded_at} · direspons {$record->proofreading->responded_at|jp_date:"d-m-Y H:i"}{/if}</p></div>{if $record->proofreading_url}<a class="jp-button" href="{$record->proofreading_url|escape}">{if $record->proofreading->status == 'awaiting_author'}Periksa dan Beri Keputusan{else}Lihat Hasil Proofreading{/if}</a>{/if}</section>{/if}

			<section class="jp-public-documents"><h3>Dokumen Artikel</h3>{if !$record->document_access_granted}<p class="jp-muted">Tautan dokumen dilindungi. Gunakan tautan aman yang dikirim melalui email jurnal.</p>{/if}<div class="jp-document-list"><div><strong>Kuitansi Pembayaran</strong><span>{if $record->status == 'verified'}Tersedia{else}Tersedia setelah pembayaran diverifikasi{/if}</span>{if $record->status == 'verified' && $record->document_access_granted}<a class="jp-button jp-document-preview" href="{url page="journalPayment" op="receipt" articleId=$record->article_id access=$record->document_access_token}" data-preview-name="Kuitansi Pembayaran">Pratinjau Kuitansi</a>{/if}</div><div><strong>Letter of Acceptance</strong><span>{if $record->loa_issued_at}Tersedia{else}Belum diterbitkan pengelola{/if}</span>{if $record->loa_issued_at && $record->document_access_granted}<a class="jp-button jp-button-secondary jp-document-preview" href="{url page="journalPayment" op="loa" articleId=$record->article_id access=$record->document_access_token}" data-preview-name="Letter of Acceptance (LOA)">Pratinjau LOA</a>{/if}</div><div><strong>Sertifikat Publikasi</strong><span>{if $record->certificate_issued_at}Tersedia{else}Tersedia setelah artikel dipublikasikan{/if}</span>{if $record->certificate_issued_at && $record->document_access_granted}<a class="jp-button jp-button-secondary jp-document-preview" href="{url page="journalPayment" op="certificate" articleId=$record->article_id access=$record->document_access_token}" data-preview-name="Sertifikat Publikasi">Pratinjau Sertifikat</a>{/if}</div></div></section>
		</section>
	{elseif $rateLimited}
		<div class="jp-alert jp-alert-error">Terlalu banyak pencarian. Tunggu beberapa menit lalu coba kembali.</div>
	{elseif $searched}
		<div class="jp-alert jp-alert-error">Data pembayaran tidak ditemukan. Pastikan ID artikel sudah benar.</div>
	{/if}
</main>
{include file=$journalPaymentPreviewModalTemplate}
{include file="frontend/components/footer.tpl"}
