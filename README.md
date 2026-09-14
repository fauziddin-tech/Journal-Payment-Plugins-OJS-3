# Journal Payment Plugin for OJS 3

Plugin manajemen pembayaran publikasi untuk **OJS 3.3.0-x**, dikembangkan dan diuji terutama pada **OJS 3.3.0-22**.

## Versi stabil

**v1.28.4** — 14 September 2026

Versi ini memperbaiki galat HTTP 500 pada dashboard akun editor, melengkapi dependensi TCPDF untuk QR, dan mempertahankan pembatasan akses berdasarkan assignment submission OJS.

## Fitur utama

- Form pembayaran publik untuk penulis berdasarkan ID artikel.
- Normalisasi nomor WhatsApp Indonesia.
- Paket pembayaran dan estimasi tanggal publikasi.
- Verifikasi bukti pembayaran, kuitansi, LOA, dan sertifikat publikasi.
- Pratinjau layar penuh untuk dokumen dan bukti transfer.
- PDF terlindungi dengan izin cetak serta QR verifikasi permanen.
- Dashboard Aktif dan Arsip berdasarkan status editorial OJS.
- Filter status artikel dan prioritas tindakan.
- Editor hanya melihat pembayaran submission yang ditugaskan kepadanya.
- Penugasan otomatis mengikuti `stage_assignments` OJS.
- Laporan keuangan per nomor terbitan.
- Pembagian pendapatan editor dan administrator.
- Honorarium Production Editor per artikel.
- Proofreading galley akhir dan persetujuan penulis.
- Sinkronisasi folder dan file publikasi Google Drive.
- Audit perubahan, pengiriman email, dan integritas dokumen.

## Hak akses

| Peran | Akses |
| --- | --- |
| Site Administrator | Seluruh jurnal dan konfigurasi |
| Pengelola Pembayaran Penuh | Seluruh transaksi, keuangan, dokumen, Drive, dan audit pada jurnal |
| Editor | Hanya pembayaran submission yang ditugaskan kepadanya di OJS |
| Production Editor | Hanya pekerjaan produksi dan honorarium miliknya |
| Penulis | Form pembayaran, status, dokumen, dan proofreading melalui akses aman |

Editor dan Production Editor tidak memperoleh akses ke submission editor lain.

## Instalasi

1. Unduh paket rilis `journalPayment-v1.28.4-full.tar.gz`.
2. Masuk sebagai Administrator atau Journal Manager.
3. Buka **Settings → Website → Plugins**.
4. Pilih **Upload A New Plugin**.
5. Unggah paket `.tar.gz`.
6. Aktifkan **Journal Payment**.
7. Bersihkan cache data dan template OJS.
8. Buka tab **Pembayaran** pada Website Settings.

Upgrade mempertahankan data pembayaran dan pengaturan versi sebelumnya.

## Konfigurasi penting

Setelah aktivasi, periksa:

- pengelola pembayaran dengan akses penuh;
- rekening dan petunjuk pembayaran;
- paket, tarif, dan estimasi publikasi;
- persentase pembagian keuangan;
- honorarium Production Editor;
- logo, tanda tangan, dan stempel;
- pengaturan email;
- kredensial Google Drive bila digunakan.

## Production Editor

Production Editor menggunakan kelompok pengguna **Assistant** yang diaktifkan pada tahap **Production**. Ketika petugas ditugaskan pada submission di OJS, plugin membaca assignment tersebut secara otomatis. Tidak diperlukan penugasan kedua pada plugin.

Honorarium standar dapat ditentukan per jurnal dan disesuaikan per artikel oleh pengelola berakses penuh. Riwayat pembayaran serta konfirmasi penerimaan disimpan dalam audit.

## Keamanan dokumen

Kuitansi, LOA, dan sertifikat dibuat sebagai PDF server-side. Dokumen memiliki token akses, QR verifikasi, fingerprint SHA-256, versi immutable, serta pembatasan izin PDF. Versi yang dicabut tidak lagi dianggap valid pada halaman verifikasi.

## Persyaratan

- OJS 3.3.0-x
- PHP 7.4 atau versi yang kompatibel dengan instalasi OJS
- Ekstensi PHP: `mbstring`, `fileinfo`, dan `openssl`
- Hak tulis pada direktori file privat OJS
- TCPDF lengkap sudah disertakan dalam paket distribusi

## Pelaporan masalah

Saat melaporkan masalah, sertakan:

- versi OJS dan PHP;
- versi plugin;
- peran akun yang mengalami masalah;
- langkah untuk mereproduksi;
- tangkapan layar;
- bagian terbaru dari `error_log` tanpa kredensial atau data sensitif.

## Status proyek

Repositori ini sedang dilengkapi dengan source versi stabil, dokumentasi, checksum rilis, dan catatan perubahan.

## Pengembang

Moh Fauziddin

Dikembangkan untuk mendukung pengelolaan pembayaran dan alur publikasi jurnal berbasis OJS.
