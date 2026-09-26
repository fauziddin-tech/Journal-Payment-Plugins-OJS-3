{include file="frontend/components/header.tpl" pageTitleTranslated=$pageTitleTranslated}
<style>:root {ldelim} --jp-accent: {$jpAccentColor|escape}; {rdelim}</style>
<main class="jp-page jp-proofreading-page" data-theme-mode="{$jpThemeMode|escape}">
	<section class="jp-card jp-proofreading-head">
		<div><span class="jp-kicker">ID Artikel {$record->article_id|escape} · Galley Versi {$proofreading->version_number|escape}</span><h1>Proofreading Galley Akhir</h1><p>{$record->article_title|escape}</p></div>
		<span class="jp-proof-state jp-proof-{$proofreading->status|escape}">{$proofreadingStatusLabel|escape}</span>
	</section>

	{if $proofreadingResult == 'approved'}<div class="jp-alert jp-alert-success"><strong>Persetujuan tersimpan.</strong> Galley telah ditandai siap masuk antrean publikasi.</div>{elseif $proofreadingResult == 'corrections_requested'}<div class="jp-alert jp-alert-success"><strong>Catatan koreksi tersimpan.</strong> Editor akan menyiapkan galley versi berikutnya.</div>{elseif $proofreadingResult == 'invalid'}<div class="jp-alert jp-alert-error"><strong>Keputusan belum dapat disimpan.</strong> Catatan wajib diisi apabila mengajukan koreksi.</div>{elseif $proofreadingResult == 'closed'}<div class="jp-alert jp-alert-warning"><strong>Versi ini sudah ditutup.</strong> Keputusan hanya dapat diberikan satu kali pada versi galley terbaru.</div>{elseif $proofreadingResult == 'failed'}<div class="jp-alert jp-alert-error"><strong>Keputusan gagal disimpan.</strong> Silakan coba kembali.</div>{/if}

	<section class="jp-card jp-proofreading-instructions">
		<h2>Petunjuk Pemeriksaan</h2>
		<p>Periksa nama dan urutan penulis, afiliasi, judul, abstrak, tabel, gambar, isi artikel, serta daftar pustaka. Persetujuan berarti versi ini dapat diterbitkan. Koreksi substansial yang mengubah hasil penelitian tetap mengikuti kebijakan editor.</p>
		{if $proofreading->editor_notes}<div class="jp-proof-note"><strong>Catatan editor</strong><p>{$proofreading->editor_notes|escape|nl2br}</p></div>{/if}
	</section>

	<section class="jp-proofreading-pdf" aria-label="Pratinjau PDF galley akhir">
		<iframe src="{$proofreadingFileUrl|escape}" title="Galley akhir artikel ID {$record->article_id|escape}"></iframe>
	</section>

	<section class="jp-card jp-proofreading-decision">
		{if $proofreading->status == 'awaiting_author'}
		<h2>Keputusan Penulis</h2>
		<form method="post" action="{url page="journalPayment" op="proofreading" access=$proofreading->access_token}">
			{csrf}
			<label><span>Catatan pemeriksaan</span><textarea name="authorNotes" rows="4" maxlength="10000" placeholder="Wajib diisi jika mengajukan koreksi. Untuk persetujuan, catatan boleh dikosongkan."></textarea></label>
			<div class="jp-proof-decision-actions"><button class="jp-button" name="decision" value="approved" type="submit" data-proof-decision="approved">Setujui untuk Diterbitkan</button><button class="jp-button jp-button-secondary" name="decision" value="corrections_requested" type="submit" data-proof-decision="corrections_requested">Ajukan Koreksi</button></div>
		</form>
		{else}
		<h2>Keputusan Telah Dicatat</h2>
		<dl><div><dt>Status</dt><dd>{$proofreadingStatusLabel|escape}</dd></div>{if $proofreading->responded_name}<div><dt>Identitas</dt><dd>{$proofreading->responded_name|escape}</dd></div>{/if}{if $proofreading->responded_at}<div><dt>Waktu</dt><dd>{$proofreading->responded_at|jp_date:"d-m-Y H:i"}</dd></div>{/if}</dl>
		{if $proofreading->author_notes}<div class="jp-proof-note"><strong>Catatan penulis</strong><p>{$proofreading->author_notes|escape|nl2br}</p></div>{/if}
		{/if}
	</section>
</main>
{include file="frontend/components/footer.tpl"}
