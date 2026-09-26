# Changelog

## 2.0.2 — 2026-09-26

- Versi plugin yang terpasang kini tampil di deskripsi plugin (Installed Plugins), di bagian atas jendela Pengaturan, dan di header dashboard Pembayaran. Nomor versi dibaca langsung dari `version.xml`.
- Versi aset CSS/JS mengikuti versi plugin secara otomatis sehingga browser selalu memuat berkas terbaru setelah pembaruan.

## 2.0.1 — 2026-09-26

- Memperbaiki tampilan tanggal pada dashboard (Riwayat Perubahan, Riwayat Email, Keuangan, Production Editor, Folder GD, proofreading) dan halaman publik yang tampil sebagai `%26-%09-%2026 %14:%Sep:%th`. OJS 3.5 mengganti modifier `date_format` dengan versi berbasis Carbon; template kini memakai modifier `jp_date` milik plugin yang juga membiarkan tanggal kosong tetap kosong.
- Panduan Google Drive dilengkapi tautan langsung ke Google Cloud Console, langkah Refresh Token melalui OAuth Playground, dan tabel pemecahan masalah.

## 2.0.0 — 2026-09-26

Rilis porting untuk **OJS 3.5.0-x**. Tidak kompatibel dengan OJS 3.3; gunakan 1.28.4 untuk OJS 3.3.

- Seluruh class memakai namespace `APP\plugins\generic\journalPayment`, file `.inc.php` menjadi `.php`, dan `index.php` dihapus sesuai pemuatan plugin OJS 3.4+.
- Handler halaman dikirim melalui argumen hook `LoadHandler`; `HANDLER_CLASS` (ditolak OJS 3.5) tidak lagi dipakai.
- Mengganti fungsi global `fatalError()` dan `import()` yang telah dihapus.
- Email konfirmasi, pengingat revisi, LOA/sertifikat, dan proofreading dikirim melalui `Mailable` OJS 3.5; status terkirim/gagal pada Audit dideteksi dari event `MessageSent` karena mailer OJS menyembunyikan galat SMTP.
- Kode keputusan editorial disesuaikan dengan penomoran baru OJS 3.4+ (`PKP\decision\Decision`). Rekomendasi editor tidak lagi dianggap keputusan terbaru.
- Volume, nomor, dan tahun terbit dibaca dari kolom `publications.issue_id` (sebelumnya `publication_settings.issueId`).
- `UserDAO`, `StageAssignmentDAO`, dan `Services::get('submission')` diganti dengan `Repo::user()` dan `Repo::submission()`.
- Pemanggilan URL dan redirect memakai argumen path bertipe array sesuai signature ketat OJS 3.5.
- Pemeriksaan Site Administrator memakai `Application::SITE_CONTEXT_ID` (bernilai `null` di OJS 3.5).
- Daftar Pengelola Pembayaran Akses Penuh hanya menampilkan keanggotaan peran yang masih aktif (`user_user_groups.date_end`).
- `payment.css` dan `manage.js` pada tab Pengaturan Website didaftarkan melalui hook `TemplateManager::display` karena backend Vue 3 mengabaikan tag `<script>` di dalam tab.
- Template memakai `PKP\core\PKPApplication::ROUTE_*` sehingga tetap berjalan pada mode `strict = On`; modifier `number_format` dan `nl2br` didaftarkan eksplisit untuk Smarty 4.
- Folder bahasa `en_US`/`id_ID` menjadi `en`/`id`; lapisan cadangan terjemahan untuk cache locale OJS 3.3 dihapus.
- Migrasi memakai facade `Schema`/`DB`; nama tabel dan kolom tidak berubah sehingga data 1.x tetap terbaca.

## 1.28.4 — 2026-09-14

- Memperbaiki galat SQL 500 ketika akun editor membuka tab Pembayaran.
- Menambahkan relasi `user_group_settings` pada kueri pembatasan assignment editor dan Production Editor.
- Mempertahankan akses manajer penuh serta memastikan editor hanya menerima pembayaran dari submission yang ditugaskan kepadanya di OJS.

## 1.28.3 — 2026-09-14

- Menambahkan modul inti `tcpdf_barcodes_2d.php` yang wajib untuk menghasilkan QR pada kuitansi, LOA, dan sertifikat.
- Menambahkan `tcpdf_barcodes_1d.php` agar distribusi TCPDF tidak lagi memiliki dependensi inti yang hilang.
- Memperbaiki PHP fatal error 500 saat TCPDF memuat generator barcode dua dimensi.
- Menormalkan koleksi hasil basis data sebelum pemetaan ID proofreading agar peringatan `array_map` tidak mencemari respons.

## 1.28.2 — 2026-09-14

- Membersihkan seluruh output buffer dan menonaktifkan kompresi keluaran sebelum byte PDF dikirim ke browser.
- Memvalidasi signature `%PDF-`, penutup `%%EOF`, keterbacaan berkas, ukuran, dan fingerprint sebelum PDF ditayangkan.
- Memuat pratinjau melalui respons Blob yang telah divalidasi sehingga HTML/PHP error tidak lagi tampil sebagai iframe kosong.
- Menampilkan penyebab kegagalan pada area pratinjau ketika server tidak mengembalikan PDF valid.

## 1.28.1 — 2026-09-14

- Menambahkan seluruh font inti TCPDF (Helvetica, Courier, Times, Symbol, dan ZapfDingbats) ke paket penuh.
- Memperbaiki kegagalan pembuatan kuitansi, LOA, dan sertifikat dengan pesan `Could not include font definition file: helvetica`.
- Mempertahankan DejaVu Sans/Serif sebagai font dokumen Unicode setelah inisialisasi TCPDF berhasil.

## 1.28.0 — 2026-09-14

- Mengganti dokumen HTML/print browser dengan PDF server-side melalui TCPDF bawaan OJS/PKP.
- Melindungi kuitansi, LOA, dan sertifikat menggunakan AES-256 dengan izin cetak saja.
- Menambahkan tombol Unduh Dokumen pada pratinjau layar penuh.
- Menyimpan setiap PDF di direktori privat beserta fingerprint SHA-256 dan ukuran berkas.
- Mengikat QR ke jenis dan versi PDF serta melengkapi halaman verifikasi dengan nomor dokumen, status versi, metadata artikel, proteksi, dan fingerprint.
- Menyimpan versi PDF secara immutable; perubahan sumber mencabut versi lama dan menghasilkan versi baru saat dokumen dibuka kembali.
- Menolak pengiriman PDF jika fingerprint berkas privat tidak cocok dengan catatan basis data.
- Mencatat pembuatan, pencabutan, dan kegagalan integritas PDF pada Audit.

## 1.27.0 — 2026-09-13

- Menggunakan istilah **Production Editor** secara konsisten untuk petugas yang menangani tahap produksi.
- Membaca assignment otomatis dari kelompok Assistant bernama Production Editor yang aktif pada tahap Production OJS; tidak ada penugasan kedua di plugin.
- Menambahkan fee default Production Editor per artikel (Rp 100.000) yang dapat diatur per jurnal dan disesuaikan per artikel.
- Menambahkan tab Production Editor dengan rekap hak fee, pembayaran bertahap, saldo, riwayat, dan bukti transfer privat.
- Menambahkan konfirmasi “Sudah Diterima” oleh Production Editor beserta waktu, identitas, catatan, dan Audit.
- Memisahkan fee Production Editor dari laporan pembagian pendapatan 60% editor/40% administrator.
- Membatasi Production Editor hanya pada submission yang ditugaskan dan mencegah akses ke tindakan pembayaran penulis.

## 1.26.0 — 2026-09-13

- Menghapus ikon pada tab Audit agar tampil konsisten sebagai label teks.
- Menambahkan alur proofreading galley akhir PDF antara editor yang ditugaskan dan penulis.
- Menyimpan setiap unggahan sebagai versi galley terpisah; versi yang diganti otomatis dikunci.
- Menambahkan pratinjau PDF penuh, keputusan Setujui untuk Diterbitkan, dan Ajukan Koreksi dengan catatan wajib.
- Menyimpan identitas pemberi keputusan, waktu respons, status, versi, dan catatan pada Audit.
- Mengirim tautan proofreading privat melalui email serta menyediakan pengiriman ulang dan tautan WhatsApp dari dashboard.
- Menandai artikel siap masuk antrean publikasi hanya setelah galley versi terbaru disetujui penulis.
- Mencegah unggahan galley baru setelah submission berstatus Published.

## 1.25.0 — 2026-09-13

- Menambahkan tab Audit khusus pengelola berakses penuh dengan pengguna, waktu, nilai lama, nilai baru, dan catatan aktivitas.
- Mencatat pembuatan, perubahan, penghapusan, verifikasi pembayaran, penerbitan dokumen, setoran, dan perubahan Google Drive.
- Menambahkan riwayat pengiriman email beserta status terkirim/gagal.
- Melindungi kuitansi, LOA, dan sertifikat dengan token akses permanen acak 256-bit.
- Mengarahkan QR dokumen ke halaman verifikasi permanen dan menambahkan QR pada kuitansi.
- Menambahkan pembatasan pencarian persisten dengan identitas jaringan yang di-hash.

## 1.24.0 — 2026-09-13

- Menambahkan tab Keuangan dengan laporan pendapatan per nomor terbitan dan per editor.
- Hanya menghitung pembayaran terverifikasi dari submission yang telah berstatus Published di OJS.
- Membagi pendapatan otomatis menjadi fee editor 60% dan hak administrator 40%; persentase editor dapat diatur dan sisanya dihitung otomatis.
- Mengunci editor penanggung jawab, nomor terbitan, dan persentase saat artikel pertama kali masuk laporan agar riwayat keuangan tetap konsisten.
- Menambahkan setoran bertahap dari editor, unggah bukti privat, status menunggu/verifikasi/perlu perbaikan, serta saldo tersisa.
- Membatasi editor hanya pada laporan dan bukti setoran miliknya; pengelola berakses penuh dapat melihat serta memverifikasi seluruh setoran.
- Mencegah nominal setoran melebihi hak administrator yang masih terutang.

## 1.23.2 — 2026-09-12

- Menghapus tombol besar Pembayaran Ditugaskan dari halaman Submissions.
- Menggunakan tab native Pembayaran sebagai satu-satunya dashboard pembayaran editor.
- Mempertahankan sinkronisasi otomatis berdasarkan assignment workflow OJS tanpa penugasan kedua di plugin.
- Mempertahankan pembatasan v1.23.1 sehingga editor hanya melihat pembayaran artikel yang ditugaskan kepadanya.

## 1.23.1 — 2026-09-12

- Memisahkan akses editor secara ketat berdasarkan assignment submission aktual di OJS.
- Menghapus akses penuh otomatis berdasarkan level peran `ROLE_ID_MANAGER` yang dapat dipakai oleh akun Editor.
- Menambahkan daftar eksplisit Pengelola Pembayaran dengan Akses Penuh pada pengaturan plugin.
- Membatasi daftar, statistik paket, bukti pembayaran, verifikasi, edit, hapus, LOA, sertifikat, dan email dengan pemeriksaan akses yang sama di sisi server.
- Membatasi tab dan seluruh operasi Folder GD hanya untuk pengelola pembayaran berakses penuh.
- Membatasi pembukaan dan penyimpanan pengaturan plugin kepada Site Administrator atau pengelola pembayaran berakses penuh.

## 1.23.0 — 2026-09-12

- Menambahkan halaman Publication Journey bagi penulis berdasarkan status editorial aktual OJS.
- Menampilkan tindakan berikutnya, checklist publikasi, target terbit, tenggat revisi, volume/nomor, dan dokumen terpusat.
- Menjaga catatan pengelola sebagai informasi internal yang tidak tampil pada halaman publik.
- Membuat tenggat revisi otomatis dari tanggal keputusan OJS dengan durasi default yang dapat diatur dan tenggat per artikel yang dapat diedit.
- Menambahkan indikator operasional Terlambat, Menunggu Penulis, Perlu Perhatian, Menunggu Editor, dan Aman.
- Mengurutkan daftar aktif menurut tingkat risiko lalu tanggal tenggat/target terdekat.
- Menambahkan filter prioritas operasional pada dashboard pembayaran.
- Menambahkan email pengingat revisi 7, 3, dan 1 hari sebelum tenggat dengan pencatatan antiduplikasi.
- Menambahkan pengaturan batas waktu revisi, rentang peringatan publikasi, dan aktivasi pengingat email.

## 1.22.9 — 2026-09-11

- Lebar bingkai pratinjau mengikuti lebar aktual kontainer halaman OJS dan tetap dipusatkan pada viewport.
- Ukuran isi dokumen tetap memakai mode fit satu halaman di dalam bingkai yang lebih proporsional.

## 1.22.8 — 2026-09-11

- Kuitansi, LOA, dan sertifikat otomatis diperkecil secara proporsional agar satu halaman terlihat utuh dan tepat di tengah pratinjau.
- Bukti pembayaran gambar maupun PDF memakai pratinjau khusus yang menyesuaikan ukuran area layar.
- Tombol Buka di Tab Baru tetap membuka ukuran dokumen asli untuk kebutuhan cetak atau penyimpanan PDF.

## 1.22.7 — 2026-09-11

- Memperbaiki pratinjau Kuitansi, LOA, dan Sertifikat pada halaman Cek Status serta Cari Dokumen.
- Mengganti penanda aset publik lama yang menyebabkan browser menggunakan JavaScript sebelum fitur popup tersedia.
- Menyatukan nomor versi seluruh aset ke satu sumber agar cache browser selalu diperbarui bersama versi plugin.

## 1.22.6 — 2026-09-10

- Mengganti kolom paket berbasis format teknis dengan editor paket berbentuk tabel.
- Menambahkan kolom Nama Paket, Tarif, Estimasi Hari, serta tombol Tambah dan Hapus.
- Membaca pengaturan paket lama secara otomatis dan tetap menyimpan data dengan format yang kompatibel.
- Menyediakan tata letak responsif untuk pengaturan melalui layar kecil.

## 1.22.5 — 2026-09-10

- Memindahkan Petunjuk Pembayaran ke kartu penuh di atas formulir.
- Memperlebar formulir pembayaran hingga memenuhi area konten OJS.
- Menyederhanakan contoh nomor WhatsApp menjadi format `08xxxxxxxxxx` dengan normalisasi penyimpanan tetap ke `628…`.

## 1.22.4 — 2026-09-10

- Menyesuaikan skala tipografi halaman pembayaran publik dengan tampilan standar OJS.
- Mengecilkan judul bagian, tab, label formulir, input, tombol, petunjuk pembayaran, dan teks privasi secara proporsional.
- Membatasi perubahan pada halaman publik sehingga dashboard editor serta dokumen tetap menggunakan ukuran sebelumnya.

## 1.22.3 — 2026-09-10

- Menambahkan tombol Segarkan pada tab Folder GD untuk memaksa sinkronisasi terbaru kapan saja.
- Tombol Segarkan melewati cache browser 20 detik tanpa memuat ulang seluruh halaman OJS.
- Mempertahankan cache singkat ketika editor hanya berpindah tab agar navigasi tetap cepat.

## 1.22.2 — 2026-09-10

- Menyimpan hasil tab Aktif, Arsip, dan Folder GD di cache browser selama 20 detik.
- Menampilkan kembali Folder GD secara instan ketika editor baru saja berpindah tab.
- Menyinkronkan ulang isi Drive setelah cache singkat berakhir agar perubahan eksternal tetap terbaca.

## 1.22.1 — 2026-09-10

- Menyembunyikan formulir pencarian dan filter status sepenuhnya ketika tab Folder GD aktif.
- Mempertahankan pencarian hanya pada tab Aktif dan Arsip.

## 1.22.0 — 2026-09-10

- Mengubah tab Folder GD menjadi pengelola Google Drive native di dashboard OJS.
- Menambahkan pembuatan folder terbitan langsung di Drive, misalnya Volume 7 Nomor 3.
- Menambahkan daftar folder dan file, unggah file, ubah nama, serta pemindahan file ke Sampah Drive.
- Menampilkan halaman terakhir yang terdeteksi dari nama/rentang halaman file dan file publikasi terbaru.
- Menyimpan kredensial OAuth di `files_dir` privat serta menyimpan access token sementara untuk mempercepat pemuatan.
- Mempertahankan tautan folder lama sebagai folder induk sehingga konfigurasi v1.21.0 tetap digunakan.

## 1.21.0 — 2026-09-10
- Mengurangi lebar kolom Nama/Artikel dan menambah lebar kolom Tanggal Publikasi agar seluruh judul kolom tetap berada di dalam tabel.
- Menambahkan pengaturan URL folder Google Drive dengan validasi HTTPS dan domain drive.google.com.
- Menambahkan tab Folder GD sejajar dengan Aktif dan Arsip pada dashboard pembayaran.
- Membuka folder Google Drive di tab browser baru dengan perlindungan rel noopener dan noreferrer.

## 1.20.1 — 2026-09-10
- Menyederhanakan kolom terakhir dashboard menjadi Tanggal Publikasi.
- Menghapus tanggal pembayaran dari daftar agar header dan isi tidak keluar dari kotak.
- Mempertahankan tanggal pembayaran pada kuitansi dan detail transaksi.

## 1.20.0 — 2026-09-10
- Menambahkan tab Aktif dan Arsip pada dashboard pembayaran.
- Memindahkan otomatis artikel berstatus Published dan Declined ke Arsip tanpa menghapus transaksi atau dokumen.
- Mengurutkan daftar Aktif berdasarkan target tanggal terbit terdekat agar prioritas editorial langsung terlihat.
- Mengurutkan Arsip berdasarkan tanggal terbit terbaru.
- Menampilkan target terbit sebelum tanggal pembayaran pada kolom tanggal.
- Mempertahankan pencarian, filter status, pagination, hak akses editor, dan pembaruan hasil secara parsial pada setiap tab.

## 1.19.1 — 2026-09-10
- Mengubah seluruh toolbar ikon menjadi gaya outline dengan latar putih atau krem-keputihan.
- Mempertahankan warna semantik hanya pada ikon dan garis tepi: hijau, biru, merah, dan emas.
- Menambahkan warna hover yang sangat lembut tanpa bidang warna solid.
- Mengubah panel detail menjadi gradasi putih dan krem sangat muda dengan garis pemisah tipis.
- Melembutkan latar tab, header tabel, kartu statistik, bayangan, batas sage, dan aksen emas Tema Mandiri.

## 1.19.0 — 2026-09-10
- Menyatukan pratinjau bukti pembayaran, kuitansi, LOA, dan sertifikat dalam satu modal.
- Membuka seluruh dokumen di dalam pop-up tanpa berpindah halaman atau tab.
- Membuat pop-up hampir memenuhi viewport dan memindahkannya ke body agar tetap lurus di tengah meskipun dashboard OJS memakai kontainer bertingkat.
- Menambahkan pratinjau yang sama pada dashboard, halaman Cek Status, dan halaman Cari LOA & Sertifikat.
- Mempertahankan tombol Buka di Tab Baru sebagai jalur alternatif.
- Memusatkan lembar kuitansi dan membatasi lebarnya agar tetap proporsional pada layar desktop maupun perangkat seluler.

## 1.18.0 — 2026-09-10
- Mengganti ikon status editorial dengan label teks status artikel.
- Menghapus kolom dan tombol Detail agar kolom judul memperoleh ruang lebih luas.
- Membuka atau menutup panel detail ketika area baris submission diklik.
- Mendukung pembukaan detail melalui tombol Enter atau spasi untuk aksesibilitas keyboard.
- Menampilkan judul artikel secara utuh dan membungkusnya ke baris berikutnya tanpa elipsis.

## 1.17.0 — 2026-09-10
- Menambahkan pilihan Tema Mandiri yang tidak bergantung pada warna tema OJS.
- Menerapkan palet #F4EFE0, #C1CFA9, #E8BC50, #1F6C53, #17462E, dan #0D2E1C pada tab, kartu, tabel, tombol, serta elemen pendukung plugin.
- Menggunakan hijau utama #1F6C53 secara konsisten pada kuitansi, LOA, dan sertifikat ketika Tema Mandiri aktif.
- Menampilkan pratinjau swatch palet pada pengaturan plugin.

## 1.16.0 — 2026-09-10
- Memindahkan pagination transaksi ke query database sehingga hanya 25 baris halaman aktif yang dimuat dan diperkaya.
- Membatasi pemetaan status editorial pada submission yang memiliki transaksi dan sesuai pencarian.
- Mengambil penugasan editor serta metadata volume/nomor/tahun secara berkelompok untuk satu halaman.
- Mengurangi riwayat keputusan editorial yang dibaca menjadi keputusan terbaru setiap submission.
- Menghilangkan pemeriksaan struktur tabel berulang pada setiap permintaan setelah skema versi ini dinyatakan siap.
- Mengirim respons pencarian parsial tanpa header, kartu statistik, filter, modal, dan ikon global yang tidak berubah.

## 1.15.1 — 2026-09-10
- Mengubah pencarian pada tab Pembayaran menjadi permintaan AJAX parsial.
- Membatasi indikator loading hanya pada tabel hasil dan pagination di bawah pencarian.
- Menjaga judul, kartu statistik, tab Website Settings, dan sidebar OJS tetap stabil saat pencarian.
- Menerapkan perilaku yang sama pada perpindahan halaman hasil.

## 1.15.0 — 2026-09-10
- Mengganti status pembayaran pada daftar utama dengan status editorial artikel yang dibaca langsung dari workflow OJS.
- Mendukung Submitted, Internal Review, In Review, Revisions Required, Resubmit for Review, Accepted, Copyediting, Production, Scheduled, Published, dan Declined.
- Menambahkan filter pencarian berdasarkan status editorial artikel.
- Mempertahankan verifikasi pembayaran sebagai kontrol internal untuk kuitansi, LOA, sertifikat, dan statistik pendapatan.
- Mengubah keterangan kartu paket menjadi jumlah transaksi dan pembayaran yang diterima.

## 1.14.1 — 2026-09-10
- Menghapus nama jurnal yang berulang di atas judul dashboard pembayaran.
- Menyesuaikan judul Manajemen Pembayaran menjadi 24 piksel sesuai skala antarmuka OJS.
- Mengganti teks status pada daftar dengan ikon terverifikasi, menunggu, atau perlu perbaikan beserta tooltip.
- Menghapus judul Tindakan Submission agar toolbar ikon langsung tampil pada panel detail.
- Mengganti tautan Cari LOA dan Sertifikat serta Halaman Publik dengan ikon ber-tooltip.

## 1.14.0 — 2026-09-10
- Menggabungkan seluruh tindakan submission dan dokumen ke dalam satu baris toolbar ikon.
- Menambahkan ikon kontekstual untuk bukti, WhatsApp, verifikasi, edit, hapus, kuitansi, LOA, sertifikat, dan email.
- Menambahkan tooltip saat ikon disorot serta label aksesibilitas untuk navigasi keyboard dan pembaca layar.
- Mempertahankan konfirmasi penghapusan dan pengiriman email tanpa mengubah alur server.

## 1.13.1 — 2026-09-10
- Memperbesar tampilan tanda tangan sebesar 35 persen pada LOA dan Sertifikat.
- Menggeser stempel sekitar empat spasi ke kanan agar berhimpitan dengan tanda tangan.
- Menambah ruang vertikal area pengesahan agar gambar tidak menutupi nama penandatangan.

## 1.13.0 — 2026-09-10
- Menambahkan unggahan logo jurnal, tanda tangan, dan stempel melalui Pengaturan plugin.
- Menampilkan logo pada header LOA dan Sertifikat Publikasi.
- Menampilkan tanda tangan dan stempel pada area penandatangan kedua dokumen.
- Menyimpan aset dokumen pada files_dir privat dengan validasi format, ukuran, MIME, dan dimensi gambar.
- Menambahkan pratinjau serta pilihan menghapus atau mengganti masing-masing aset.

## 1.12.2 — 2026-09-10

- Memperbaiki HTTP 500 berdasarkan log server pada kompilasi `manage.tpl` baris 31.
- Mengganti kuantifier regex HTML yang memakai kurung kurawal agar tidak ditafsirkan sebagai tag Smarty OJS 3.3.
- Mempertahankan validasi Volume satu sampai empat digit dan Tahun empat digit tanpa sintaks yang bertabrakan dengan Smarty.

## 1.12.1 — 2026-09-09

- Memperbaiki HTTP 500 saat tab Pembayaran memuat dashboard pada hosting yang membatasi perubahan struktur tabel.
- Memindahkan penyimpanan Volume, Nomor, dan Tahun ke metadata plugin per transaksi.
- Mempertahankan pengambilan data terbitan otomatis dari issue OJS tanpa mewajibkan ALTER TABLE.
- Menambahkan penanganan kegagalan pembacaan metadata agar dashboard tetap dapat ditampilkan.

## 1.12.0 — 2026-09-09

- Menambahkan Volume, Nomor Terbitan, dan Tahun Terbit pada LOA serta Sertifikat Publikasi.
- Mengambil data terbitan otomatis dari issue pada current publication submission OJS.
- Menambahkan pengisian dan koreksi data terbitan melalui formulir Edit transaksi.
- Menyimpan snapshot data terbitan ketika LOA atau sertifikat diterbitkan agar dokumen tetap konsisten.
- Menampilkan informasi terbitan pada halaman status, pencarian dokumen, dan email LOA/sertifikat.

## 1.11.0 — 2026-09-09

- Mengirim email konfirmasi otomatis setelah bukti pembayaran berhasil disimpan.
- Menjelaskan bahwa bukti masih menunggu verifikasi agar email tidak disalahartikan sebagai kuitansi lunas.
- Menambahkan ringkasan transaksi, prediksi terbit, tahapan proses, tautan status, dan tautan kuitansi pada email.
- Menjaga transaksi tetap tersimpan bila pengiriman email gagal serta menampilkan status email pada halaman sukses.
- Mengganti kartu status dashboard dengan kartu dinamis per paket pembayaran.
- Menampilkan jumlah pembayaran, jumlah terverifikasi, dan total dana terverifikasi untuk setiap paket.
- Membatasi perhitungan kartu sesuai submission yang ditugaskan ketika dashboard dibuka oleh editor.

## 1.10.0 — 2026-09-09

- Menghapus scrollbar horizontal di bawah daftar dan membuat kolom menyesuaikan lebar panel.
- Menampilkan nama editor OJS yang ditugaskan pada setiap submission pembayaran.
- Menghubungkan label editor langsung ke workflow submission OJS untuk pengelolaan penugasan.
- Menambahkan tombol Pembayaran Ditugaskan pada dashboard editor.
- Membatasi daftar, statistik, detail, bukti, verifikasi, edit, hapus, serta dokumen berdasarkan penugasan editor OJS.
- Mempertahankan akses penuh Journal Manager dan Site Administrator terhadap seluruh pembayaran jurnal.
- Menjadikan tabel stage_assignments OJS sebagai sumber tunggal sehingga tidak ada penugasan ganda di plugin.

## 1.9.0 — 2026-09-09

- Menempatkan Lihat Bukti, WhatsApp, Verifikasi, Edit, dan Hapus dalam satu baris hemat ruang.
- Menambahkan durasi publikasi pada konfigurasi paket dengan format Nama|Nominal|JumlahHari.
- Menghitung prediksi terbit otomatis sejak tanggal pembayaran berdasarkan paket yang dipilih.
- Mempertahankan kompatibilitas paket lama dan membaca rentang bulan dari nama paket jika jumlah hari belum ditulis.
- Menambahkan Prediksi/Tanggal Terbit pada daftar, halaman status, pencarian dokumen, LOA, sertifikat, dan email dokumen.
- Menambahkan pengubahan tanggal terbit melalui formulir Edit dengan validasi tanggal di server.
- Menambahkan kolom database otomatis untuk instalasi dan pembaruan manual melalui cPanel.

## 1.8.0 — 2026-09-09

- Menghapus pengulangan nama, kontak, ID artikel, paket, nominal, tanggal, dan judul dari panel detail.
- Menyederhanakan tindakan utama menjadi Verifikasi, Edit, dan Hapus.
- Menambahkan formulir edit ringkas untuk nama, email, WhatsApp, judul artikel, dan catatan pengelola.
- Memvalidasi seluruh perubahan kembali di server dan menormalisasi nomor WhatsApp.
- Menambahkan konfirmasi penghapusan serta menghapus file bukti terkait agar tidak menjadi berkas yatim.
- Mempertahankan bukti pembayaran, WhatsApp, kuitansi, LOA, sertifikat, dan tombol pengiriman dokumen.

## 1.7.0 — 2026-09-09

- Menambahkan tombol pengiriman email terpisah untuk LOA dan Sertifikat Publikasi.
- Mengirim email langsung melalui konfigurasi mail/SMTP OJS.
- Menyertakan nama penulis, ID artikel, nomor dokumen, dan tautan resmi dalam email.
- Melindungi pengiriman dengan autentikasi Journal Manager dan token CSRF.
- Menampilkan pemberitahuan berhasil, email tidak valid, dokumen belum tersedia, atau kegagalan SMTP.
- Memisahkan label tombol pengiriman WhatsApp dan Email agar tindakan tidak tertukar.

## 1.6.0 — 2026-09-09

- Menambahkan QR code verifikasi pada LOA dan Sertifikat Publikasi.
- Membuat QR secara lokal tanpa layanan atau API pihak ketiga.
- Mengarahkan QR ke URL resmi dokumen pada jurnal aktif.
- Menambahkan tombol Kirim LOA dan Kirim Sertifikat pada setiap submission yang dokumennya sudah diterbitkan.
- Mengisi pesan WhatsApp otomatis dengan nama penulis, ID artikel, jenis dokumen, dan tautan resmi.
- Menyembunyikan tombol kirim apabila nomor WhatsApp tidak tersedia atau dokumen belum diterbitkan.

## 1.5.1 — 2026-09-09

- Membuat frame navigasi tab memenuhi seluruh lebar area konten.
- Membagi tiga tab dalam kolom yang sama lebar tanpa ruang kosong di sisi kanan.
- Menghapus scrollbar tab yang muncul pada beberapa tema dan browser.
- Menyamakan border, sudut membulat, dan bayangan tab dengan kartu konten di bawahnya.
- Merapikan pembungkusan label tab pada layar telepon genggam.

## 1.5.0 — 2026-09-09

- Menghapus banner besar Pembayaran Publikasi dari halaman publik plugin.
- Mengubah Kirim Pembayaran, Cek Status, dan Cari LOA & Sertifikat menjadi tab bergaya antarmuka OJS.
- Menampilkan penanda tab aktif tanpa mengubah rute halaman publik yang sudah digunakan.
- Menghapus aturan CSS yang menyembunyikan sidebar sehingga sidebar bawaan tema OJS tetap tampil.
- Mempertahankan dashboard daftar ringkas dan seluruh fitur versi 1.4.0.

## 1.4.0 — 2026-09-09

- Mengubah dashboard transaksi dari kartu besar menjadi tabel daftar ringkas seperti aplikasi awal.
- Menampilkan satu submission dalam satu baris dengan nama, ID artikel, paket, jumlah, status, tanggal, dan aksi.
- Memindahkan bukti, WhatsApp, catatan, verifikasi, kuitansi, LOA, dan sertifikat ke panel detail yang dapat dibuka per baris.
- Membatasi panel agar hanya satu detail transaksi terbuka pada satu waktu.
- Mempertahankan pencarian, filter status, dan paginasi 25 transaksi per halaman.

## 1.3.0 — 2026-09-09

- Menyesuaikan ukuran huruf dashboard ke skala antarmuka backend OJS.
- Mengecilkan judul, kartu, data transaksi, textarea, input, dan tombol pada tab Pembayaran.
- Menambahkan tombol Lihat Kuitansi pada setiap transaksi terverifikasi.
- Menambahkan halaman publik pencarian LOA dan Sertifikat berdasarkan ID Artikel.
- Menampilkan status ketersediaan Kuitansi, LOA, dan Sertifikat dalam kartu terpisah.
- Menambahkan navigasi Cari LOA & Sertifikat pada halaman pembayaran dan tombol pintas pada dashboard.

## 1.2.2 — 2026-09-09

- Memperbaiki HTTP 500 pada LOA akibat CSS `document.tpl` dibaca sebagai sintaks Smarty.
- Membungkus CSS LOA dan kuitansi dalam blok literal Smarty.
- Memindahkan warna dinamis dokumen ke variabel CSS `--jp-accent`.
- Menyesuaikan signature `JournalPaymentSettingsForm::validate()` dengan OJS 3.3.0-22.

## 1.2.1 — 2026-09-09

- Memperbaiki URL LOA yang keliru mengikuti component router (`$$$call$$$`) dari dashboard tertanam.
- Memaksa seluruh URL dashboard pengelola memakai page router OJS.
- Memperbaiki tautan sertifikat, preview bukti, halaman publik, formulir keputusan, dan penerbitan dokumen pada tab Pembayaran.

## 1.2.0 — 2026-09-09

- Menambahkan tab Pembayaran native sejajar dengan Static Pages pada Website Settings.
- Memuat dashboard melalui komponen AJAX OJS tanpa berpindah ke halaman pengelolaan terpisah.
- Mengarahkan tombol Kelola Pembayaran langsung ke tab baru.
- Mengembalikan aksi verifikasi, perbaikan, LOA, dan sertifikat ke tab Pembayaran.
- Mempertahankan filter, pencarian, paginasi, serta preview bukti pada tampilan tertanam.
- Mengubah JavaScript pengelola menjadi event delegation agar kompatibel dengan konten AJAX.

## 1.1.2 — 2026-09-09

- Fixed persistent 404 errors on all `journalPayment` routes after an OJS upgrade.
- Changed the plugin to non-lazy loading so the `LoadHandler` hook is always registered.
- Added aliases for historical version-specific plugin class names (1.0.3–1.0.13).
- Kept the stable `journalpaymentplugin` settings identity and all existing payment data.

## 1.1.1 Full Install — 2026-09-09

- Memperbaiki 404 rute Kelola Pembayaran setelah upgrade OJS 3.3.0-22.
- Mendaftarkan hook halaman ketika kelas plugin berhasil dimuat, tanpa bergantung pada `mainContextId` saat proses bootstrap.
- Memeriksa status aktif plugin menggunakan konteks jurnal aktual ketika rute diakses.
- Menyesuaikan pemeriksaan tombol Kelola dengan context ID jurnal aktif.

## 1.1.0 Full Install — 2026-09-09

- Menggabungkan seluruh fungsi plugin ke satu kelas utama `JournalPaymentPlugin`.
- Menghapus ketergantungan paket distribusi terhadap loader versi 1.0.5–1.0.13.
- Menyiapkan paket instalasi bersih untuk OJS 3.3.0-22 setelah folder plugin lama dihapus.
- Mempertahankan identitas pengaturan, tabel transaksi, lokasi bukti pembayaran, dan URL plugin lama.
- Menyertakan seluruh koreksi pembayaran, dashboard, preview, LOA, sertifikat, dan cache-busting aset.

## 1.0.13 — 2026-09-09

- Memperbaiki HTTP 500 saat membuka LOA pada OJS 3.3.0.20.
- Memindahkan seluruh nilai fallback dokumen dari modifier Smarty ke PHP agar kompatibel dengan Smarty lama.
- Menormalkan nama organisasi, penandatangan, jabatan, teks, nomor, dan tanggal sebelum template dirender.
- Menambahkan nilai bawaan aman bila pengaturan LOA belum pernah disimpan setelah upgrade.

## 1.0.12 — 2026-09-09

- Menambahkan cache-busting berbasis versi pada CSS dan JavaScript plugin.
- Memastikan pratinjau bukti selalu tampil sebagai modal dan tidak tersangkut sebagai konten di bawah dashboard.
- Menambahkan pengaman inline agar modal tetap tersembunyi sebelum dibuka.
- Memperlebar jarak tombol Verifikasi dan Perlu Perbaikan, termasuk saat stylesheet tema terlambat dimuat.

## 1.0.11 — 2026-09-09

- Menampilkan bukti pembayaran dalam pop-up pratinjau tanpa meninggalkan dashboard.
- Mendukung pratinjau gambar dan PDF, buka di tab baru, klik area luar, dan tombol Escape.
- Mengganti dropdown status dengan tombol Verifikasi dan Perlu Perbaikan.
- Mempertahankan kotak pesan pengelola dan mewajibkan pesan ketika memilih Perlu Perbaikan.
- Menambahkan validasi pesan perbaikan pada browser dan server.

## 1.0.10 — 2026-09-09

- Mengubah pencarian ID artikel menjadi tindakan manual melalui tombol Cari.
- Menghapus pencarian otomatis ketika pengguna mengetik, berhenti mengetik, atau berpindah dari kolom ID.
- Mencegah fokus berpindah otomatis ke kolom Nomor WhatsApp setelah artikel ditemukan.
- Mengosongkan data hasil OJS ketika ID artikel diubah agar nama, email, dan judul lama tidak tertukar dengan ID baru.

## 1.0.9 — 2026-09-09

- Menambahkan mode tampilan otomatis yang mengambil warna utama dari tema OJS aktif, dengan opsi warna manual sebagai fallback.
- Menambahkan penerbitan LOA dan Sertifikat Publikasi secara eksplisit oleh Journal Manager setelah pembayaran terverifikasi.
- Membatasi penerbitan Sertifikat Publikasi sampai status submission di OJS menjadi published.
- Membuat nomor dokumen otomatis dan mengisi nama penulis, judul, serta ID artikel dari data pembayaran yang telah diverifikasi.
- Menambahkan pengaturan nama/jabatan penandatangan serta isi pokok LOA dan sertifikat.
- Menampilkan tombol unduh dokumen pada halaman status hanya setelah dokumen diterbitkan pengelola.
- Mencatat ID pengguna pengelola dan waktu penerbitan setiap dokumen tanpa bridge atau secret eksternal.

## 1.0.8 — 2026-09-09

- Menambahkan deteksi pembayaran ganda berdasarkan jurnal aktif dan ID artikel.
- Menampilkan peringatan beserta status pembayaran yang sudah ada saat ID artikel diperiksa.
- Menonaktifkan tombol pengiriman dan menyediakan tautan langsung ke halaman Cek Status jika pembayaran sudah pernah dibuat.
- Memvalidasi ulang duplikat di server agar perlindungan tidak dapat dilewati dengan menonaktifkan JavaScript.
- Mengunci proses penyimpanan per submission untuk mencegah dua permintaan bersamaan membuat data ganda.

## 1.0.7 — 2026-09-09

- Menyamakan posisi banner, tab navigasi, dan lebar halaman Cek Status dengan halaman Kirim Pembayaran.
- Menyederhanakan pencarian status agar cukup menggunakan ID artikel.
- Menampilkan pembayaran terbaru bila satu ID artikel memiliki lebih dari satu riwayat.
- Menggunakan ID artikel untuk membuka kuitansi sambil mempertahankan kompatibilitas tautan kuitansi versi lama.

## 1.0.6 — 2026-09-09

- Menambahkan pencarian submission berdasarkan ID artikel langsung dari data internal OJS tanpa API key.
- Mengisi otomatis nama penulis korespondensi, email, dan judul, serta memvalidasinya kembali ketika formulir dikirim.
- Menormalisasi nomor WhatsApp `08…`, `628…`, `+628…`, atau `8…` menjadi format `628…` yang cocok untuk `wa.me`.
- Menambahkan tombol WhatsApp pada dashboard pengelola.
- Membatasi pencarian artikel pada jurnal aktif, melindunginya dengan CSRF, dan menerapkan pembatasan 30 pencarian per 10 menit per sesi.

## 1.0.5 — 2026-09-08

- Menghapus ketergantungan teks antarmuka utama terhadap cache locale OJS; label Indonesia ditulis langsung pada template dan pesan server.
- Menghilangkan semua tampilan `##plugins.generic.journalPayment...##` pada pengaturan, formulir, status, dashboard, dan kuitansi.
- Menyembunyikan sidebar tema pada halaman pembayaran dan membuat dashboard lebar penuh agar tidak terpotong atau menimbulkan gulir horizontal.

## 1.0.4 — 2026-09-08

- Memperbaiki tombol "Kelola Pembayaran" agar menggunakan page router OJS, bukan component router `$$$call$$$` yang menghasilkan 404.
- Memastikan tombol mengarah ke `/journalPayment/manage` pada konteks jurnal aktif.

## 1.0.3 — 2026-09-08

- Memaksa pemuatan kode baru pada server LiteSpeed/PHP dengan berkas kelas utama berversi, sambil mempertahankan identitas pengaturan plugin lama.
- Mengganti label pengaturan dan tombol dashboard dengan teks langsung agar tidak bergantung pada cache locale server.
- Menambahkan pemeriksaan skema saat runtime agar tabel pembayaran otomatis dibuat bila migrasi instalasi tidak dijalankan.

## 1.0.2 — 2026-09-08

- Menambahkan fallback seluruh terjemahan plugin ketika cache locale berbasis file OJS tetap menggunakan katalog lama.
- Memperbaiki label aksi "Kelola Pembayaran" dan mencegah kunci `##...##` pada formulir, status, dashboard, pengaturan, serta kuitansi.

## 1.0.1 — 2026-09-08

- Memperkuat registrasi katalog bahasa pada instalasi OJS 3.3 yang memakai kode locale nonstandar atau fallback locale.
- Menambahkan teks cadangan untuk nama dan deskripsi agar tidak tampil sebagai `##translation.key##`.

## 1.0.0 — 2026-09-08

- Rilis awal untuk OJS 3.3.0-x.
- Form pembayaran publik, pelacakan privat, dashboard pengelola, verifikasi, bukti privat, dan kuitansi.
- Pengaturan per jurnal untuk identitas, rekening, metode, tarif, batas unggahan, dan warna.
- Lokalisasi Indonesia dan Inggris.
