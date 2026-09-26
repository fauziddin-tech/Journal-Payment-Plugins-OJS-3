# Panduan Upgrade Journal Payment ke OJS 3.5

Rilis 2.0.0 hanya untuk OJS 3.5.0-x. Plugin versi 1.x (OJS 3.3) tidak dapat berjalan di OJS 3.5, dan rilis 2.0.0 tidak dapat berjalan di OJS 3.3.

## A. Urutan upgrade dari OJS 3.3.0-22

1. Uji seluruh proses ini di salinan staging terlebih dahulu.
2. Cadangkan basis data, folder `files_dir` (termasuk `files_dir/journalPayment`), dan folder `plugins/generic/journalPayment`.
3. Sebelum mengupgrade OJS, **hapus folder `plugins/generic/journalPayment` versi 1.x** dari server (melalui cPanel/FTP). Jangan menghapus tabel `journal_payment_*` atau folder `files_dir/journalPayment`.
4. Jalankan upgrade OJS ke 3.5.0-5 sesuai panduan resmi PKP. Migrasi resmi OJS akan mengonversi kode keputusan editorial lama ke penomoran baru, dan plugin 2.0.0 sudah menyesuaikan diri dengan penomoran itu.
5. Ekstrak `journalPayment-v2.0.0-ojs3.5.tar.gz` sehingga strukturnya menjadi `plugins/generic/journalPayment/JournalPaymentPlugin.php` (tanpa folder ganda). Alternatif: unggah melalui **Settings > Website > Plugins > Upload A New Plugin**.
6. Jalankan `php lib/pkp/tools/installPluginVersion.php plugins/generic/journalPayment/version.xml` jika plugin belum tercatat, atau cukup buka daftar plugin sebagai Site Administrator.
7. Aktifkan **Journal Payment / Pembayaran Jurnal**, lalu bersihkan Data Cache dan Template Cache.
8. Buka **Pengaturan** plugin dan pastikan akun **Pengelola Pembayaran dengan Akses Penuh** masih terpilih. Pengaturan lain tersimpan dengan identitas yang sama (`journalpaymentplugin`) dan tidak perlu diisi ulang.

Tabel plugin tidak berubah nama maupun struktur. Pemeriksaan skema otomatis dijalankan sekali saat halaman plugin pertama kali dibuka.

## B. Checklist uji setelah pemasangan

Jalankan pengujian di bawah dengan satu artikel uji pada staging.

| Area | Yang diuji | Hasil yang diharapkan |
|---|---|---|
| Halaman publik | `/index.php/<jurnal>/journalPayment/submit` | Form tampil dengan header/footer tema, data artikel terisi otomatis dari ID |
| Pengiriman | Kirim pembayaran dengan bukti PDF/JPG | Halaman sukses tampil; email konfirmasi diterima penulis |
| Audit email | Tab Audit > Riwayat Email | Status **sent** untuk email yang benar-benar terkirim, **failed** jika SMTP salah |
| Dashboard | Settings > Website > tab **Pembayaran** | Daftar, pencarian, filter, paginasi, dan pratinjau bukti berfungsi |
| Status editorial | Artikel dengan keputusan *Request Revisions* | Tampil **Revisions Required** di tab Aktif, bukan Declined/Arsip |
| Status editorial | Artikel *Accepted*, *Send to Production*, *Declined* | Label sesuai; Declined dan Published masuk tab Arsip |
| Verifikasi | Ubah status menjadi Terverifikasi | Nomor kuitansi terbit; kuitansi PDF terbuka |
| LOA/Sertifikat | Terbitkan LOA, lalu sertifikat untuk artikel Published | Volume/Nomor/Tahun terisi dari issue OJS; QR mengarah ke halaman verifikasi |
| Keuangan | Tab Keuangan | Artikel Published terkelompok per nomor terbitan dan editor |
| Production Editor | Tab Production Editor | Assignment Production Editor terdeteksi; fee dapat dicatat |
| Proofreading | Unggah galley, buka tautan penulis | Penulis dapat menyetujui atau mengajukan koreksi |
| Akses editor | Masuk sebagai Section Editor biasa | Hanya pembayaran submission yang ditugaskan kepadanya yang terlihat |

## C. Jika terjadi masalah

- **Plugin tidak muncul**: pastikan nama folder persis `journalPayment` dan PHP 8.2+. Periksa `error_log` server untuk pesan `Instantiation of the plugin generic/journalPayment has failed`.
- **Tab Pembayaran tampil tanpa gaya**: bersihkan Template Cache dan cache browser; aset dimuat dengan parameter versi `?v=2.0.0`.
- **Email tercatat failed**: periksa konfigurasi `[email]` di `config.inc.php` dan alamat kontak jurnal (Settings > Journal > Contact).
- **Status editorial tampak salah**: pastikan upgrade OJS selesai tanpa galat. Migrasi resmi `I7725_DecisionConstantsUpdate` wajib berhasil agar kode keputusan lama terkonversi.
