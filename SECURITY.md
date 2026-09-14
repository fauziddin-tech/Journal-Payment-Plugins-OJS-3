# Security Policy

## Versi yang didukung

Perbaikan keamanan difokuskan pada versi stabil terbaru.

| Versi | Dukungan |
| --- | --- |
| 1.28.x | Didukung |
| < 1.28 | Tidak didukung |

## Melaporkan kerentanan

Jangan mempublikasikan kredensial, token, API key, bukti pembayaran, alamat email penulis, nomor WhatsApp, atau potongan basis data pada issue publik.

Laporan sebaiknya memuat:

- versi OJS, PHP, dan plugin;
- peran pengguna yang terdampak;
- langkah reproduksi;
- dampak keamanan;
- log yang sudah disamarkan;
- saran perbaikan jika tersedia.

Gunakan jalur komunikasi privat kepada pemilik repositori untuk kerentanan yang belum diperbaiki.

## Prinsip keamanan plugin

- Akses editor dibatasi berdasarkan assignment submission OJS.
- Dokumen menggunakan token acak dan QR verifikasi.
- PDF resmi memiliki fingerprint SHA-256 dan versi immutable.
- Bukti pembayaran dan bukti transfer disimpan sebagai file privat.
- Perubahan status, dokumen, keuangan, dan pengiriman email dicatat dalam audit.
- Nilai token dan kredensial tidak boleh ditampilkan dalam log.
- Production Editor tidak dapat mengubah pembayaran penulis atau honorariumnya sendiri.

## Sebelum membagikan log

Hapus atau samarkan:

- password dan session cookie;
- API key Google Drive atau layanan email;
- access token;
- alamat email;
- nomor telepon;
- data rekening;
- lokasi direktori privat jika dianggap sensitif.
