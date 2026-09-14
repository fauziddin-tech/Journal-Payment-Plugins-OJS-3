{include file="frontend/components/header.tpl" pageTitleTranslated=$pageTitleTranslated}

<style>:root {ldelim} --jp-accent: {$jpAccentColor|escape}; {rdelim}</style>
<main class="jp-page" data-theme-mode="{$jpThemeMode|escape}">
	<nav class="jp-tabs" aria-label="Navigasi pembayaran">
		<a class="is-active" href="{url page="journalPayment" op="submit"}">Kirim Pembayaran</a>
		<a href="{url page="journalPayment" op="status"}">Cek Status</a>
		<a href="{url page="journalPayment" op="documents"}">Cari LOA &amp; Sertifikat</a>
	</nav>

	{if $errors}
		<div class="jp-alert jp-alert-error" role="alert">
			<strong>Periksa kembali data berikut:</strong>
			<ul>{foreach from=$errors item=error}<li>{$error|escape}</li>{/foreach}</ul>
		</div>
	{/if}

	<div class="jp-layout">
		<aside class="jp-card jp-payment-guide">
			<div>
				<h2>Petunjuk Pembayaran</h2>
				<div class="jp-instructions">{$bankInstructions|escape|nl2br}</div>
			</div>
			<p class="jp-privacy"><strong>Privasi dan Keamanan</strong><br>Bukti pembayaran disimpan di direktori privat OJS dan hanya dapat dibuka oleh pengelola jurnal.</p>
		</aside>
		<section class="jp-card jp-payment-form-card">
			<h1 class="jp-section-title">Kirim Pembayaran</h1>
			<p class="jp-muted jp-section-intro">Kirim data dan bukti pembayaran publikasi melalui formulir resmi jurnal.</p>
			<form id="jp-payment-form" method="post" enctype="multipart/form-data" action="{url page="journalPayment" op="submit"}" data-lookup-url="{url page="journalPayment" op="lookup"}" data-status-url="{url page="journalPayment" op="status"}">
				{csrf}
				<div id="jp-duplicate-alert" class="jp-alert jp-alert-warning" role="alert" hidden>
					<strong>Pembayaran sudah pernah dibuat</strong>
					<p id="jp-duplicate-message"></p>
					<a id="jp-duplicate-status-link" class="jp-button jp-button-secondary" href="{url page="journalPayment" op="status"}">Lihat Status Pembayaran</a>
				</div>
				<div class="jp-grid">
					<label><span>ID Artikel *</span><span class="jp-id-control"><input id="jp-article-id" required inputmode="numeric" pattern="[0-9]+" maxlength="20" name="articleId" value="{$values.articleId|escape}" autocomplete="off"><button id="jp-lookup-button" class="jp-button jp-button-secondary" type="button">Cari</button></span><small id="jp-lookup-status" class="jp-field-help" aria-live="polite">Masukkan ID artikel, lalu klik tombol Cari.</small></label>
					<label><span>Nama Lengkap * <em class="jp-ojs-badge" data-badge="name"></em></span><input id="jp-name" required maxlength="255" name="name" value="{$values.name|escape}" autocomplete="name"></label>
					<label><span>Email * <em class="jp-ojs-badge" data-badge="email"></em></span><input id="jp-email" required type="email" maxlength="255" name="email" value="{$values.email|escape}" autocomplete="email"></label>
					<label><span>Nomor WhatsApp *</span><input id="jp-phone" required inputmode="tel" maxlength="24" name="phone" value="{$values.phone|escape}" autocomplete="tel" placeholder="08xxxxxxxxxx"><small id="jp-phone-help" class="jp-field-help">Gunakan format 08xxxxxxxxxx; sistem menyimpannya otomatis sebagai 628…</small></label>
				</div>
				<label><span>Judul Artikel * <em class="jp-ojs-badge" data-badge="title"></em></span><textarea id="jp-title" required name="title" rows="3">{$values.title|escape}</textarea></label>
				<div class="jp-grid">
					<label><span>Jenis Pembayaran *</span>
						<select required name="package">
							<option value="">Pilih jenis pembayaran</option>
							{foreach from=$packages item=package}<option value="{$package.name|escape}" {if $values.package == $package.name}selected{/if}>{$package.name|escape} — Rp {$package.amount|number_format:0:',':'.'} — estimasi {$package.duration_days|escape} hari</option>{/foreach}
						</select>
					</label>
					<label><span>Metode Pembayaran</span>
						<select name="paymentMethod">
							{foreach from=$paymentMethods item=method}<option value="{$method|escape}" {if $values.paymentMethod == $method}selected{/if}>{$method|escape}</option>{/foreach}
						</select>
					</label>
				</div>
				<label class="jp-upload"><span>Bukti Pembayaran *</span><input required type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"><small>Format PDF, JPG, PNG, atau WebP; maksimal {$maxUploadMb|escape} MB.</small></label>
				<label><span>Catatan</span><textarea name="notes" rows="3">{$values.notes|escape}</textarea></label>
				<button id="jp-submit-button" class="jp-button" type="submit">Kirim untuk Diverifikasi</button>
			</form>
		</section>
	</div>
</main>

{include file="frontend/components/footer.tpl"}
