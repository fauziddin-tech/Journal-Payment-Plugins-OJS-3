<script>
	$(function() {ldelim}
		$('#journalPaymentSettings').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
		var packageStore = $('#packages');
		var packageRows = $('#jpPackageRows');

		function syncPackages() {ldelim}
			var lines = [];
			packageRows.find('.jp-package-setting-row').each(function() {ldelim}
				var row = $(this);
				var name = $.trim(row.find('[data-package-field="name"]').val() || '').replace(/[|\r\n]+/g, ' ');
				var amount = String(row.find('[data-package-field="amount"]').val() || '').replace(/[^0-9]/g, '');
				var days = String(row.find('[data-package-field="days"]').val() || '').replace(/[^0-9]/g, '');
				if (name && amount && days) lines.push(name + '|' + amount + '|' + days);
			{rdelim});
			packageStore.val(lines.join('\n'));
		{rdelim}

		function addPackageRow(name, amount, days) {ldelim}
			var row = $('<div class="jp-package-setting-row"></div>');
			row.append($('<input class="field text" type="text" maxlength="100" required data-package-field="name" aria-label="Nama paket" placeholder="Contoh: Prioritas">').val(name || ''));
			row.append($('<input class="field text" type="number" min="0" step="1000" required data-package-field="amount" aria-label="Tarif paket dalam rupiah" placeholder="1500000">').val(amount || ''));
			row.append($('<input class="field text" type="number" min="1" max="730" required data-package-field="days" aria-label="Estimasi publikasi dalam hari" placeholder="30">').val(days || ''));
			row.append('<button class="pkp_button jp-remove-package" type="button" aria-label="Hapus paket">Hapus</button>');
			packageRows.append(row);
		{rdelim}

		var storedPackages = packageStore.val() || '';
		$.each(storedPackages.split(/\r?\n/), function(index, line) {ldelim}
			if (!$.trim(line)) return;
			var parts = line.split('|');
			addPackageRow($.trim(parts[0] || ''), String(parts[1] || '').replace(/[^0-9]/g, ''), String(parts[2] || '60').replace(/[^0-9]/g, '') || '60');
		{rdelim});
		if (!packageRows.children().length) addPackageRow('', '', '60');
		packageRows.on('input change', 'input', syncPackages);
		packageRows.on('click', '.jp-remove-package', function() {ldelim}
			$(this).closest('.jp-package-setting-row').remove();
			if (!packageRows.children().length) addPackageRow('', '', '60');
			syncPackages();
		{rdelim});
		$('#jpAddPackage').on('click', function() {ldelim} addPackageRow('', '', '60'); {rdelim});
		syncPackages();
		function syncFinanceShare() {ldelim}
			var editorShare = Math.max(0, Math.min(100, parseInt($('#editorFeePercent').val(), 10) || 0));
			$('#jpAdminFeePreview').text(100 - editorShare);
		{rdelim}
		$('#editorFeePercent').on('input change', syncFinanceShare);
		syncFinanceShare();

		$('.jp-document-asset-input').on('change', function() {ldelim}
			var input = this;
			var target = document.getElementById(input.getAttribute('data-target'));
			var preview = document.getElementById(input.getAttribute('data-preview'));
			var file = input.files && input.files[0];
			if (!file) {ldelim} target.value = ''; return; {rdelim}
			if (['image/png', 'image/jpeg', 'image/webp'].indexOf(file.type) === -1 || file.size > 2097152) {ldelim}
				alert('Gunakan gambar PNG, JPG, atau WebP dengan ukuran maksimal 2 MB.');
				input.value = '';
				target.value = '';
				return;
			{rdelim}
			var reader = new FileReader();
			reader.onload = function(event) {ldelim}
				target.value = event.target.result;
				if (preview) {ldelim} preview.src = event.target.result; preview.hidden = false; {rdelim}
			{rdelim};
			reader.readAsDataURL(file);
		{rdelim});
	{rdelim});
</script>

<style>
	.jp-package-setting-head,.jp-package-setting-row {ldelim} display:grid;grid-template-columns:minmax(180px,1.4fr) minmax(140px,.8fr) minmax(130px,.7fr) auto;gap:10px;align-items:center {rdelim}
	.jp-package-setting-head {ldelim} margin-bottom:7px;color:#555;font-size:12px;font-weight:700 {rdelim}
	.jp-package-setting-row {ldelim} margin-bottom:9px {rdelim}
	.jp-package-setting-row input {ldelim} width:100%;margin:0 {rdelim}
	.jp-package-setting-row .jp-remove-package {ldelim} min-width:72px;color:#a7332a {rdelim}
	.jp-access-user-list {ldelim} display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:9px;margin:12px 0 {rdelim}
	.jp-access-user {ldelim} display:flex;align-items:flex-start;gap:9px;padding:10px;border:1px solid #d7dfda;border-radius:6px;background:#fff;cursor:pointer {rdelim}
	.jp-access-user input {ldelim} margin-top:3px {rdelim}
	.jp-access-user span,.jp-access-user small {ldelim} display:block {rdelim}
	.jp-access-user small {ldelim} margin-top:2px;color:#666;overflow-wrap:anywhere {rdelim}
	@media (max-width:700px) {ldelim}
		.jp-package-setting-head {ldelim} display:none {rdelim}
		.jp-package-setting-row {ldelim} grid-template-columns:1fr {rdelim}
		.jp-package-setting-row input {ldelim} min-height:38px {rdelim}
	{rdelim}
</style>

<form class="pkp_form" id="journalPaymentSettings" method="POST"
	action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	<p class="jp-version-note" style="margin:0 0 16px;color:#5f6b7a">Journal Payment <strong>versi {$journalPaymentVersion|escape}</strong> · <a href="https://github.com/fauziddin-tech/Journal-Payment-Plugins-OJS-3/releases" target="_blank" rel="noopener">Catatan rilis</a></p>
	{fbvFormArea id="journalPaymentSettingsArea"}
		<div class="section formButtons" style="text-align:left;border:0;padding:0;margin:0">
			<h3 style="margin:0 0 16px">Identitas dan Tampilan</h3>
			<p><label for="organizationName"><strong>Nama organisasi/penerbit</strong></label><br><input class="field text" type="text" id="organizationName" name="organizationName" value="{$organizationName|escape}" style="width:100%"></p>
			<p><label for="themeMode"><strong>Tampilan plugin</strong></label><br><select class="field select" id="themeMode" name="themeMode" style="width:100%"><option value="auto" {if $themeMode == 'auto'}selected{/if}>Ikuti tema OJS secara otomatis</option><option value="standalone" {if $themeMode == 'standalone'}selected{/if}>Tema Mandiri — Sage, Emas, dan Hijau</option><option value="manual" {if $themeMode == 'manual'}selected{/if}>Gunakan warna manual</option></select><small>Mode Tema Mandiri memakai palet tetap dan tidak berubah ketika tema OJS diganti.</small></p>
			<div id="jpStandalonePalette" style="display:flex;align-items:stretch;gap:6px;margin:-4px 0 18px" aria-label="Palet Tema Mandiri">
				<span title="#F4EFE0 — Krem" style="display:block;flex:1;height:42px;border:1px solid #ded8c8;border-radius:7px 0 0 7px;background:#F4EFE0"></span>
				<span title="#C1CFA9 — Sage" style="display:block;flex:1;height:42px;background:#C1CFA9"></span>
				<span title="#E8BC50 — Emas" style="display:block;flex:1;height:42px;background:#E8BC50"></span>
				<span title="#1F6C53 — Hijau utama" style="display:block;flex:1;height:42px;background:#1F6C53"></span>
				<span title="#17462E — Hijau gelap" style="display:block;flex:1;height:42px;background:#17462E"></span>
				<span title="#0D2E1C — Hijau terdalam" style="display:block;flex:1;height:42px;border-radius:0 7px 7px 0;background:#0D2E1C"></span>
			</div>
			<p><label for="accentColor"><strong>Warna manual/cadangan</strong></label><br><input class="field text" type="text" id="accentColor" name="accentColor" value="{$accentColor|escape}" style="width:100%"><small>Dipakai pada mode manual atau jika warna tema tidak dapat dideteksi. Gunakan format #RRGGBB, misalnya #1f6f5f.</small></p>
			<p><label for="footerText"><strong>Teks kaki kuitansi</strong></label><br><textarea class="field textArea" id="footerText" name="footerText" rows="4" style="width:100%">{$footerText|escape}</textarea></p>
			<h3 style="margin:28px 0 16px">Hak Akses Pembayaran</h3>
			<div style="padding:16px;border:1px solid #d7dfda;border-radius:7px;background:#fbfcfa;margin-bottom:20px">
				<p style="margin-top:0"><strong>Pengelola dengan akses penuh</strong><br><small>Akun terpilih dapat melihat seluruh pembayaran, statistik semua paket, dan Folder GD. Site Administrator selalu memiliki akses penuh meskipun tidak dipilih.</small></p>
				{if $paymentStaffOptions|@count}
					<div class="jp-access-user-list">
						{foreach from=$paymentStaffOptions item=staff}
							<label class="jp-access-user"><input type="checkbox" name="fullAccessUserIds[]" value="{$staff.id|escape}" {if $staff.selected}checked{/if}><span><strong>{$staff.name|escape}</strong><small>{$staff.username|escape} · {$staff.email|escape}</small></span></label>
						{/foreach}
					</div>
				{else}<p>Belum ada akun editor/pengelola yang dapat dipilih.</p>{/if}
				<p style="margin-bottom:0"><small><strong>Penting:</strong> editor yang tidak dipilih hanya dapat melihat pembayaran dari submission yang ditugaskan kepadanya pada workflow OJS.</small></p>
			</div>
			<h3 style="margin:28px 0 16px">Google Drive</h3>
			<p><label for="googleDriveFolderUrl"><strong>Folder induk Google Drive</strong></label><br><input class="field text" type="url" id="googleDriveFolderUrl" name="googleDriveFolderUrl" value="{$googleDriveFolderUrl|escape}" placeholder="https://drive.google.com/drive/folders/..." style="width:100%"><small>Subfolder volume/nomor dan file publikasi akan dikelola di dalam folder ini.</small></p>
			<div style="padding:14px;border:1px solid #d7dfda;border-radius:6px;background:#fbfcfa;margin:12px 0 18px">
				<p style="margin-top:0"><strong>Status OAuth:</strong> {if $googleDriveConfigured}<span style="color:#187455">Tersimpan</span>{else}<span style="color:#9a6510">Belum dikonfigurasi</span>{/if}</p>
				<p><label for="googleDriveClientId"><strong>OAuth Client ID</strong></label><br><input class="field text" type="password" autocomplete="new-password" id="googleDriveClientId" name="googleDriveClientId" value="" placeholder="Kosongkan jika tidak ingin mengganti" style="width:100%"></p>
				<p><label for="googleDriveClientSecret"><strong>OAuth Client Secret</strong></label><br><input class="field text" type="password" autocomplete="new-password" id="googleDriveClientSecret" name="googleDriveClientSecret" value="" placeholder="Kosongkan jika tidak ingin mengganti" style="width:100%"></p>
				<p><label for="googleDriveRefreshToken"><strong>OAuth Refresh Token</strong></label><br><input class="field text" type="password" autocomplete="new-password" id="googleDriveRefreshToken" name="googleDriveRefreshToken" value="" placeholder="Kosongkan jika tidak ingin mengganti" style="width:100%"></p>
				<p><small>Ketiga nilai harus diisi sekaligus. Rahasia disimpan di direktori privat OJS <code>files_dir</code>, bukan di database atau folder web.</small></p>
				{if $googleDriveConfigured}<label><input type="checkbox" name="removeGoogleDriveCredentials" value="1"> Hapus kredensial OAuth yang tersimpan</label>{/if}
			</div>
			<h3 style="margin:28px 0 16px">LOA dan Sertifikat</h3>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:18px;margin-bottom:22px">
				<div><label for="documentLogoUpload"><strong>Logo jurnal</strong></label><br>{if $documentLogoPreview}<img id="documentLogoPreview" src="{$documentLogoPreview|escape}" alt="Logo jurnal" style="display:block;max-width:150px;max-height:90px;margin:10px 0;object-fit:contain">{else}<img id="documentLogoPreview" alt="Pratinjau logo" hidden style="display:block;max-width:150px;max-height:90px;margin:10px 0;object-fit:contain">{/if}<input class="jp-document-asset-input" id="documentLogoUpload" type="file" accept="image/png,image/jpeg,image/webp" data-target="documentLogoData" data-preview="documentLogoPreview"><input type="hidden" id="documentLogoData" name="documentLogoData" value=""><br><label><input type="checkbox" name="removeDocumentLogo" value="1"> Hapus logo</label></div>
				<div><label for="signatureUpload"><strong>Tanda tangan</strong></label><br>{if $signaturePreview}<img id="signaturePreview" src="{$signaturePreview|escape}" alt="Tanda tangan" style="display:block;max-width:170px;max-height:90px;margin:10px 0;object-fit:contain">{else}<img id="signaturePreview" alt="Pratinjau tanda tangan" hidden style="display:block;max-width:170px;max-height:90px;margin:10px 0;object-fit:contain">{/if}<input class="jp-document-asset-input" id="signatureUpload" type="file" accept="image/png,image/jpeg,image/webp" data-target="signatureData" data-preview="signaturePreview"><input type="hidden" id="signatureData" name="signatureData" value=""><br><label><input type="checkbox" name="removeSignature" value="1"> Hapus tanda tangan</label></div>
				<div><label for="stampUpload"><strong>Stempel</strong></label><br>{if $stampPreview}<img id="stampPreview" src="{$stampPreview|escape}" alt="Stempel" style="display:block;max-width:120px;max-height:90px;margin:10px 0;object-fit:contain">{else}<img id="stampPreview" alt="Pratinjau stempel" hidden style="display:block;max-width:120px;max-height:90px;margin:10px 0;object-fit:contain">{/if}<input class="jp-document-asset-input" id="stampUpload" type="file" accept="image/png,image/jpeg,image/webp" data-target="stampData" data-preview="stampPreview"><input type="hidden" id="stampData" name="stampData" value=""><br><label><input type="checkbox" name="removeStamp" value="1"> Hapus stempel</label></div>
			</div>
			<p><small>Format PNG, JPG, atau WebP; maksimal 2 MB per gambar. Gunakan PNG transparan untuk tanda tangan dan stempel. Aset diterapkan pada LOA dan Sertifikat.</small></p>
			<p><label for="signerName"><strong>Nama penandatangan</strong></label><br><input class="field text" type="text" id="signerName" name="signerName" value="{$signerName|escape}" style="width:100%"></p>
			<p><label for="signerTitle"><strong>Jabatan penandatangan</strong></label><br><input class="field text" type="text" id="signerTitle" name="signerTitle" value="{$signerTitle|escape}" style="width:100%"></p>
			<p><label for="loaText"><strong>Isi pokok LOA</strong></label><br><textarea class="field textArea" id="loaText" name="loaText" rows="4" style="width:100%">{$loaText|escape}</textarea></p>
			<p><label for="certificateText"><strong>Isi pokok Sertifikat Publikasi</strong></label><br><textarea class="field textArea" id="certificateText" name="certificateText" rows="4" style="width:100%">{$certificateText|escape}</textarea><small>Sertifikat hanya dapat diterbitkan setelah status artikel di OJS menjadi published.</small></p>
			<h3 style="margin:28px 0 16px">Pengaturan Pembayaran</h3>
			<div style="display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:14px;padding:16px;border:1px solid #d7dfda;border-radius:7px;background:#fbfcfa;margin-bottom:18px">
				<label><strong>Fee editor (%)</strong><br><input class="field text" type="number" min="0" max="100" id="editorFeePercent" name="editorFeePercent" value="{$editorFeePercent|escape}" style="width:100%"><small>Dikunci per artikel ketika artikel Published pertama kali masuk laporan keuangan.</small></label>
				<div><strong>Hak administrator</strong><p style="margin:8px 0 4px"><span id="jpAdminFeePreview">40</span>% dari pembayaran artikel</p><small>Dihitung otomatis: 100% dikurangi fee editor.</small></div>
			</div>
			<div style="padding:16px;border:1px solid #d7dfda;border-radius:7px;background:#fbfcfa;margin-bottom:18px">
				<label for="productionEditorFee"><strong>Fee Production Editor per artikel (Rp)</strong></label><br>
				<input class="field text" type="number" min="0" max="100000000" step="1000" id="productionEditorFee" name="productionEditorFee" value="{$productionEditorFee|escape}" style="width:100%;max-width:320px">
				<small style="display:block;margin-top:6px">Default Rp 100.000. Hak fee dibuat otomatis ketika akun berperan Assistant ditugaskan pada tahap Production OJS; nominal setiap artikel masih dapat disesuaikan dari tab Production Editor.</small>
			</div>
			<p><label for="bankInstructions"><strong>Petunjuk dan rekening pembayaran</strong></label><br><textarea class="field textArea" id="bankInstructions" name="bankInstructions" rows="5" style="width:100%">{$bankInstructions|escape}</textarea><small>Tulis nama bank, nomor rekening, nama pemilik, atau petunjuk QRIS.</small></p>
			<p><label for="paymentMethods"><strong>Metode pembayaran</strong></label><br><textarea class="field textArea" id="paymentMethods" name="paymentMethods" rows="4" style="width:100%">{$paymentMethods|escape}</textarea><small>Satu metode per baris.</small></p>
			<div id="jpPackageEditor" style="margin:18px 0 22px;padding:16px;border:1px solid #d7dfda;border-radius:7px;background:#fbfcfa">
				<label><strong>Jenis, tarif, dan prediksi publikasi</strong></label>
				<p style="margin:5px 0 14px;color:#666">Tambahkan paket pembayaran, tarif, dan waktu publikasi yang dijanjikan kepada penulis.</p>
				<div class="jp-package-setting-head" aria-hidden="true"><span>Nama Paket</span><span>Tarif (Rp)</span><span>Estimasi (Hari)</span><span>Aksi</span></div>
				<div id="jpPackageRows"></div>
				<button class="pkp_button" id="jpAddPackage" type="button" style="margin-top:3px">+ Tambah Paket</button>
				<textarea id="packages" name="packages" hidden style="display:none">{$packages|escape}</textarea>
				<small style="display:block;margin-top:10px">Tanggal publikasi diprediksi otomatis dari tanggal pembayaran ditambah estimasi hari paket. Maksimal 730 hari.</small>
			</div>
			<h3 style="margin:28px 0 16px">Publication Journey dan Tenggat</h3>
			<div style="padding:16px;border:1px solid #d7dfda;border-radius:7px;background:#fbfcfa;margin-bottom:20px">
				<label style="display:flex;align-items:flex-start;gap:9px"><input type="checkbox" name="enableReminders" value="1" {if $enableReminders == '1'}checked{/if}><span><strong>Aktifkan pengingat revisi otomatis</strong><br><small>Email dikirim kepada penulis pada 7, 3, dan 1 hari sebelum tenggat. Pengiriman diperiksa secara ringan saat dashboard pembayaran dibuka.</small></span></label>
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;margin-top:16px">
					<label><strong>Batas waktu revisi penulis</strong><br><input class="field text" type="number" min="1" max="90" name="revisionDeadlineDays" value="{$revisionDeadlineDays|escape}" style="width:100%"><small>Jumlah hari sejak keputusan revisi OJS. Tenggat tiap artikel tetap dapat diedit pada detail pembayaran.</small></label>
					<label><strong>Peringatan target publikasi</strong><br><input class="field text" type="number" min="1" max="60" name="publicationWarningDays" value="{$publicationWarningDays|escape}" style="width:100%"><small>Artikel masuk kategori “Perlu perhatian” ketika target terbit berada dalam rentang ini.</small></label>
				</div>
			</div>
			<p><label for="maxUploadMb"><strong>Batas unggahan (MB)</strong></label><br><input class="field text" type="number" min="1" max="20" id="maxUploadMb" name="maxUploadMb" value="{$maxUploadMb|escape}" style="width:100%"><small>Gunakan nilai 1–20 MB. Batas server PHP tetap berlaku.</small></p>
		</div>
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save"}
</form>
