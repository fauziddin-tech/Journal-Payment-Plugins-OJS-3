# Journal Payment for OJS 3.5

Plugin pembayaran publikasi manual versi **2.0.0** untuk **OJS 3.5.0-x**, dengan target utama **OJS 3.5.0-5**. Untuk OJS 3.3 gunakan rilis 1.28.4 pada branch [`ojs-3.3`](../../tree/ojs-3.3). Editor membuka tab native **Pembayaran** dan hanya dapat melihat pembayaran submission yang ditugaskan kepadanya pada workflow OJS; tidak ada penugasan kedua di plugin. Site Administrator dan akun yang dipilih sebagai **Pengelola Pembayaran dengan Akses Penuh** dapat melihat seluruh transaksi, statistik jurnal, laporan keuangan, fee Production Editor, Folder GD, dan Audit. Dashboard juga menyediakan prioritas operasional, tenggat revisi, pengingat email, Publication Journey, PDF terlindungi, pengelolaan file publikasi Google Drive, serta persetujuan proofreading galley akhir oleh penulis.

## Fitur

- Formulir pembayaran publik yang mengikuti header, footer, font, lebar, dan warna utama tema OJS aktif; tersedia pula pilihan warna manual.
- Paket/jenis pembayaran dan tarif berbeda untuk setiap jurnal.
- Metode pembayaran serta petunjuk rekening dapat diatur Journal Manager.
- Unggah bukti PDF/JPG/PNG/WebP ke `files_dir` privat OJS.
- Kode pelacakan acak dan pemeriksaan perjalanan publikasi berdasarkan ID artikel.
- Dashboard Journal Manager dengan pencarian, filter, ringkasan, dan paginasi.
- Toolbar ikon satu baris dengan tooltip untuk seluruh tindakan submission dan dokumen.
- Ringkasan dashboard per paket menampilkan jumlah pembayaran, jumlah terverifikasi, dan total dana terverifikasi.
- Email otomatis setelah unggah bukti berisi ucapan terima kasih, ringkasan transaksi, prediksi terbit, alur singkat, tautan status, dan tautan kuitansi.
- Status: Menunggu Verifikasi, Terverifikasi, dan Perlu Perbaikan.
- Nomor kuitansi otomatis dan PDF kuitansi dibuat langsung oleh server.
- Halaman pencarian publik untuk Kuitansi, LOA, dan Sertifikat berdasarkan ID Artikel.
- Pemisahan data berdasarkan `context_id`, aman untuk instalasi OJS multijurnal.
- Antarmuka utama berbahasa Indonesia dan responsif untuk desktop maupun ponsel.
- ID artikel mengambil nama penulis korespondensi, email, dan judul langsung dari submission OJS pada jurnal aktif.
- Nomor WhatsApp menerima format `08…`, `628…`, atau `+628…` dan disimpan seragam sebagai `628…`.
- Tombol WhatsApp tersedia pada dashboard pengelola untuk membuka percakapan dengan pembayar.
- Pemeriksaan status cukup menggunakan ID artikel dan menampilkan pembayaran terbaru untuk ID tersebut.
- Peringatan duplikat berdasarkan ID artikel mencegah pembayaran yang sama dibuat lebih dari sekali pada jurnal yang sama.
- LOA native OJS yang diterbitkan Journal Manager setelah pembayaran terverifikasi.
- Sertifikat Publikasi native OJS yang baru dapat diterbitkan setelah pembayaran terverifikasi dan artikel berstatus published.
- Nomor LOA/sertifikat otomatis, data artikel otomatis, identitas penandatangan yang dapat diatur, dan PDF A4 dibuat langsung oleh server.
- Logo jurnal, tanda tangan, dan stempel dapat diunggah melalui Pengaturan plugin serta digunakan bersama pada LOA dan Sertifikat.
- Volume, nomor, dan tahun terbit dibaca dari issue OJS atau dapat ditentukan melalui Edit sebelum LOA/sertifikat diterbitkan.
- Laporan keuangan per nomor terbitan yang hanya mengakui pendapatan setelah artikel Published.
- Pembagian otomatis 60% fee editor dan 40% hak administrator, dengan persentase yang dapat dikonfigurasi.
- Unggah setoran editor secara bertahap, bukti privat, verifikasi administrator, riwayat transaksi, dan saldo tersisa.
- Snapshot editor, terbitan, dan persentase menjaga laporan lama tetap konsisten ketika assignment OJS atau pengaturan berubah.
- Tab Audit untuk riwayat perubahan data, jadwal, status, penerbitan dokumen, aktivitas setoran/Drive, dan pengiriman email.
- Token dokumen permanen 256-bit serta QR verifikasi untuk kuitansi, LOA, dan sertifikat.
- Kuitansi, LOA, dan sertifikat dienkripsi AES-256 dengan izin cetak saja; copy, edit, anotasi, dan assembly tidak diberikan.
- Setiap versi PDF disimpan privat dan memiliki fingerprint SHA-256; perubahan data mencabut versi lama tanpa menimpanya.
- QR mengikat jenis serta versi dokumen dan halaman verifikasi menampilkan nomor, data artikel, terbitan, tanggal, status versi, proteksi, dan fingerprint.
- Editor yang ditugaskan dapat mengunggah galley akhir PDF berversi dan mengirim tautan proofreading privat kepada penulis.
- Penulis dapat membaca PDF penuh, menyetujui penerbitan, atau mengajukan koreksi beserta catatan.
- Setiap keputusan proofreading menyimpan identitas, waktu, versi galley, dan catatan pada riwayat Audit.
- Galley berstatus siap publikasi hanya setelah penulis menyetujui versi terbaru; versi lama otomatis dikunci ketika versi baru diunggah.
- Assignment akun dengan kelompok **Production Editor** pada tahap Production OJS otomatis membuat hak fee per artikel tanpa penugasan kedua di plugin.
- Nominal default fee Production Editor dapat diatur per jurnal (default Rp 100.000) dan masih dapat disesuaikan per artikel.
- Tab Production Editor merangkum artikel, total fee, pembayaran bertahap, sisa fee, bukti transfer privat, dan konfirmasi penerimaan oleh Production Editor.
- Fee Production Editor dicatat terpisah dari pembagian pendapatan 60% editor/40% administrator.

## Keamanan

- Menggunakan akun dan otorisasi OJS; tidak ada login atau tabel pengguna kedua.
- Semua perubahan oleh pengelola memakai perlindungan CSRF OJS.
- Pencarian data artikel publik memakai CSRF dan pembatas persisten; identitas jaringan disimpan sebagai hash, bukan IP mentah.
- Tautan dokumen publik wajib memakai token acak permanen; akun staf tetap mengikuti pembatasan assignment OJS.
- Nominal pembayaran ditentukan ulang di server dari konfigurasi paket.
- MIME bukti pembayaran diperiksa dari isi file, bukan nama/tipe dari browser.
- Nama file diacak dan file disimpan di direktori privat OJS.
- Bukti pembayaran hanya dapat dibuka editor yang ditugaskan, pengelola pembayaran berakses penuh, atau Site Administrator.
- Editor biasa dibatasi berdasarkan `stage_assignments` pada jurnal aktif; statistik dan semua tindakan pembayaran memakai batas akses yang sama.
- Folder GD dan pengaturan plugin hanya tersedia bagi pengelola pembayaran berakses penuh atau Site Administrator.
- Editor hanya dapat membuka laporan serta bukti setoran miliknya; keputusan verifikasi setoran hanya tersedia bagi pengelola pembayaran berakses penuh.
- Status dan kuitansi publik menggunakan ID artikel sesuai alur jurnal; dokumen LOA/sertifikat hanya tampil setelah diterbitkan pengelola.
- Penerbit dokumen dan waktu penerbitannya dicatat pada basis data plugin.
- Integrasi LOA/sertifikat tidak mengirim secret melalui URL dan tidak bergantung pada layanan eksternal.
- Generator menggunakan TCPDF yang dibundel secara lokal di dalam plugin; tidak memerlukan `exec`, Composer, atau layanan PDF eksternal.
- Tidak membutuhkan API key OJS, kredensial basis data tambahan, CORS terbuka, atau SSL bypass.

Pembatasan izin PDF mencegah perubahan melalui pembaca/editor PDF yang mematuhi standar, tetapi bukan pengganti verifikasi QR. Salinan hasil tangkapan layar, cetak ulang, atau aplikasi yang sengaja mengabaikan permission tetap mungkin dibuat; karena itu status versi dan data pada halaman verifikasi merupakan sumber kebenaran jurnal.

## Persyaratan PDF terlindungi

- Gunakan paket plugin **full** karena sudah menyertakan mesin TCPDF, modul barcode 1D/2D untuk QR, font inti TCPDF, dan font Unicode yang diperlukan.
- Ekstensi PHP `openssl` dan `mbstring` harus aktif (umumnya sudah menjadi bagian instalasi OJS).
- `files_dir/journalPayment` harus dapat ditulis oleh PHP dan tetap berada di luar direktori publik.
- Bila TCPDF tidak ditemukan, plugin tidak membuat dokumen HTML yang tampak terlindungi; pengguna menerima pesan konfigurasi dan paket plugin lengkap perlu diunggah ulang.

## Panduan

- [Menghubungkan Google Drive (tab Folder GD)](GOOGLE-DRIVE.md) — langkah lengkap membuat Client ID, Client Secret, dan Refresh Token beserta tautan langsung ke halaman Google Cloud.
- [Upgrade dari OJS 3.3 ke OJS 3.5](PANDUAN-UPGRADE-OJS35.md) — urutan upgrade dan checklist uji setelah pemasangan.

## Instalasi

1. Buat cadangan basis data dan folder OJS.
2. Masuk sebagai Site Administrator.
3. Buka **Settings > Website > Plugins > Upload A New Plugin**.
4. Unggah `journalPayment-v2.0.0-ojs3.5.zip` (atau `.tar.gz`) dari halaman Releases GitHub. Jangan mengunggah file `.sha256`.
5. Aktifkan **Pembayaran Jurnal** pada kelompok Generic Plugins.
6. Klik **Pengaturan**, tentukan akun **Pengelola Pembayaran dengan Akses Penuh**, lalu isi penerbit, rekening/petunjuk, metode, tarif, mode tema, penandatangan dokumen, dan batas unggahan.
7. Klik **Kelola Pembayaran** untuk membuka dashboard pengelola.

Jika unggah plugin dari dashboard tidak tersedia, ekstrak paket ke:

```text
plugins/generic/journalPayment/
```

Pastikan hasil akhirnya langsung berisi `index.php` dan `version.xml`, bukan folder ganda seperti `journalPayment/journalPayment/`.

Setelah pemasangan manual, buka **Administration > Hosted Journals**, atau jalankan proses upgrade OJS agar migrasi tabel plugin diterapkan. Bersihkan cache OJS jika plugin belum muncul.

## Pemasangan bersih setelah versi sebelumnya

1. Cadangkan basis data, `files_dir`, dan folder `plugins/generic/journalPayment` lama.
2. Nonaktifkan plugin dari daftar plugin OJS jika halaman masih dapat dibuka.
3. Melalui cPanel, hapus hanya folder `plugins/generic/journalPayment` lama. Jangan menghapus tabel database atau folder bukti di `files_dir/journalPayment`.
4. Bersihkan Data Cache dan Template Cache OJS.
5. Unggah `journalPayment-v2.0.0-ojs3.5.tar.gz` melalui **Upload A New Plugin**.
6. Aktifkan kembali **Journal Payment / Pembayaran Jurnal**.
7. Buka Pengaturan, pilih akun berakses penuh, lalu periksa kembali rekening, tarif, tampilan, dan penandatangan dokumen.
8. Buka Kelola Pembayaran. Plugin akan menggunakan kembali tabel dan bukti pembayaran lama bila masih tersedia.

Rilis lengkap menggunakan identitas pengaturan dan tabel yang sama. Data pembayaran lama tetap dapat terbaca selama tabel `journal_payment_records` dan folder `files_dir/journalPayment` tidak dihapus. Jika plugin lama dihapus memakai tombol **Delete** OJS, sebagian pengaturan dapat hilang dan perlu diisi kembali.

## URL

Ganti `journal-path` dengan path jurnal Anda:

- Form pembayaran: `/index.php/journal-path/journalPayment/submit`
- Cek status: `/index.php/journal-path/journalPayment/status`
- Dashboard pengelola: `/index.php/journal-path/journalPayment/manage`
- LOA yang sudah diterbitkan: `/index.php/journal-path/journalPayment/loa?articleId=12345`
- Sertifikat Publikasi yang sudah diterbitkan: `/index.php/journal-path/journalPayment/certificate?articleId=12345`

URL formulir dapat ditambahkan melalui **Settings > Website > Navigation Menus** sebagai Custom Page.

## Format konfigurasi tarif

Satu item per baris:

```text
Article Processing Charge|750000
Fast Track Review|1000000
Layout dan Proofreading|350000
```

Gunakan angka tanpa `Rp`, titik, atau koma agar mudah dipelihara.

## Persyaratan

- OJS 3.5.0-x (diuji terhadap kode sumber OJS 3.5.0-5)
- PHP 8.2 atau lebih baru dengan ekstensi `fileinfo`, `mbstring`, `openssl`, dan `curl` (untuk Folder GD)
- Direktori `files_dir` OJS dapat ditulis oleh PHP
- Batas `upload_max_filesize` dan `post_max_size` PHP tidak lebih kecil daripada batas plugin
- Upgrade dari OJS 3.3: lihat `PANDUAN-UPGRADE-OJS35.md`

## Perbedaan dari aplikasi `payment.zip`

Plugin ini adalah implementasi baru yang lebih aman untuk satu instalasi OJS. Data aplikasi lama **tidak otomatis dipindahkan**. LOA dan Sertifikat Publikasi dibuat native di OJS tanpa bridge eksternal. Sertifikat reviewer tidak digabungkan karena sumber datanya adalah penugasan review, bukan pembayaran; fitur tersebut tetap lebih tepat berada pada plugin Reviewer Certificate tersendiri. Plugin mengelola Folder GD jurnal melalui OAuth yang dikonfigurasi pengelola, sedangkan fee Production Editor mengikuti assignment Production OJS. Fitur organisasi lintas banyak domain OJS, impersonasi akun, pengambilan galley lintas situs, dan Resend tidak disertakan karena memerlukan model izin serta migrasi data tersendiri.

## Lisensi

GNU General Public License v3.0 or later.
