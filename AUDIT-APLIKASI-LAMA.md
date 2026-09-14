# Audit Ringkas `payment.zip`

Tanggal audit: 8 September 2026

## Kesimpulan

Aplikasi lama memiliki cakupan fitur yang baik, tetapi belum layak dibagikan sebagai plugin OJS atau dipasang ulang tanpa perbaikan. Arsitekturnya merupakan aplikasi PHP mandiri dengan autentikasi, tabel pengguna, database, dan integrasi API OJS tersendiri. Konversi langsung akan mempertahankan risiko dan membuat pengguna harus mengelola dua sistem akun.

Karena itu, versi plugin 1.0.0 dibuat ulang sebagai plugin OJS asli dan tidak menyalin komponen yang berisiko.

## Temuan utama

| Tingkat | Temuan pada aplikasi lama | Dampak | Penanganan pada plugin |
|---|---|---|---|
| Kritis | Endpoint PDF publik menerima ID numerik tanpa autentikasi dan tanpa pemeriksaan status transaksi | Data kuitansi dapat diambil sebelum alurnya sah | Dokumen plugin hanya tersedia setelah pembayaran terverifikasi dan, untuk sertifikat, setelah artikel dipublikasikan; akses publik berdasarkan ID artikel merupakan keputusan alur jurnal |
| Tinggi | Verifikasi sertifikat TLS dimatikan saat menghubungi OJS | Koneksi rentan intersepsi dan respons palsu | Plugin tidak memakai API eksternal untuk jurnal yang sama |
| Tinggi | Token sesi dikirim melalui query string pada beberapa endpoint | Token dapat masuk ke riwayat, log server, referer, atau tangkapan layar | Plugin memakai sesi dan otorisasi bawaan OJS; tidak ada token buatan di URL |
| Tinggi | CORS dibuka untuk semua origin | Memperluas permukaan akses API dari situs lain | Tidak ada API CORS publik |
| Tinggi | Bukti/file dapat diakses setiap pengguna yang memiliki token dan nama file, tanpa pemeriksaan kepemilikan/ruang lingkup data yang memadai | Pengguna internal dapat membuka file yang bukan kewenangannya | Akses bukti dibatasi Journal Manager/Site Administrator dan `context_id` jurnal |
| Tinggi | Tipe avatar dipercaya dari nilai `Content-Type` browser | Berkas dapat menyamar sebagai gambar | MIME diperiksa dari isi file menggunakan `fileinfo` |
| Sedang | API key OJS disimpan sebagai teks biasa dan dapat dimuat kembali ke formulir | Rahasia berisiko terekspos melalui database/UI | API key tidak dibutuhkan |
| Sedang | Token sesi disimpan di `localStorage` | Dampak XSS menjadi lebih besar | Menggunakan sesi OJS |
| Sedang | Password awal dikirim dalam email | Password dapat tersimpan di kotak masuk | Tidak ada akun/password kedua |
| Sedang | Pembuatan/perubahan tabel dijalankan pada request API | Lambat, rawan kondisi balapan, dan telah menghasilkan timeout pada log | Skema dibuat satu kali melalui migrasi instalasi plugin |
| Sedang | Path server, domain, dan lokasi unggahan ditulis langsung dalam kode | Sulit dipindahkan dan mudah salah konfigurasi | Menggunakan konfigurasi `files_dir` serta URL router OJS |
| Sedang | Paket distribusi berisi `api/error_log` dan avatar pengguna | Membocorkan struktur server serta data pengguna | Log dan data pengguna tidak disertakan |
| Fungsional | Tidak ditemukan berkas migrasi/skema database lengkap | Instalasi baru tidak dapat direproduksi dengan pasti | Plugin memiliki migrasi tabel resmi |

## Fitur lama yang belum dibawa ke plugin

Pengelolaan banyak situs OJS dari satu panel, akun Admin/Editor/Layouter terpisah, login sebagai pengguna lain, pembagian keuangan/gaji, sinkronisasi status dan galley lintas domain, pengiriman Resend, serta pencadangan Google Drive otomatis. Plugin kini menyediakan tab tautan ke folder bersama Google Drive, tetapi belum melakukan unggahan atau sinkronisasi berkas otomatis. LOA dan Sertifikat Publikasi sudah diimplementasikan secara native tanpa bridge eksternal. Fitur lain dapat dikembangkan sebagai modul lanjutan setelah aturan akses, kebutuhan operasional, dan migrasi data lama ditentukan.

## Catatan migrasi data

Paket lama tidak memuat ekspor struktur dan isi database sehingga migrasi otomatis yang dapat diverifikasi belum dapat dibuat. Jangan menghapus aplikasi/database lama sebelum ekspor SQL dan uji rekonsiliasi jumlah transaksi selesai dilakukan.
