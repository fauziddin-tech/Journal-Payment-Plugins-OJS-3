# Changelog

Semua perubahan penting pada Journal Payment Plugin dicatat di dokumen ini.

## 1.28.4 — 2026-09-14

- Memperbaiki galat SQL HTTP 500 ketika akun editor membuka tab Pembayaran.
- Menambahkan relasi `user_group_settings` pada kueri pembatasan assignment.
- Mempertahankan akses manajer penuh dan pembatasan editor berdasarkan assignment OJS.

## 1.28.3 — 2026-09-14

- Menambahkan modul TCPDF barcode 1D dan 2D.
- Memperbaiki fatal error generator QR pada kuitansi, LOA, dan sertifikat.
- Menormalkan koleksi hasil basis data untuk pemetaan proofreading.

## 1.28.2 — 2026-09-14

- Memvalidasi signature, penutup, ukuran, dan fingerprint PDF.
- Memuat pratinjau melalui Blob tervalidasi.
- Menampilkan pesan yang jelas ketika server tidak mengembalikan PDF valid.

## 1.28.1 — 2026-09-14

- Menambahkan font inti TCPDF.
- Memperbaiki kesalahan definisi font Helvetica.
- Mempertahankan dukungan font Unicode DejaVu.

## 1.28.0 — 2026-09-14

- Menghasilkan PDF server-side menggunakan TCPDF.
- Menambahkan proteksi cetak, token akses, QR permanen, dan fingerprint SHA-256.
- Menambahkan versi dokumen immutable serta pencabutan versi lama.
- Mencatat aktivitas integritas dokumen dalam audit.

## 1.27.0 — 2026-09-13

- Menambahkan peran dan honorarium Production Editor.
- Membaca assignment tahap Production secara otomatis.
- Menambahkan rekap honorarium, pembayaran bertahap, bukti transfer, dan konfirmasi penerimaan.

## 1.26.0 — 2026-09-13

- Menambahkan proofreading galley akhir.
- Penulis dapat menyetujui penerbitan atau mengajukan koreksi.
- Persetujuan disimpan bersama waktu dan identitas.

## 1.25.0 — 2026-09-13

- Menambahkan audit aktivitas, riwayat email, token dokumen, QR permanen, dan pembatasan pencarian.

## 1.24.0 — 2026-09-13

- Menambahkan laporan keuangan per nomor terbitan.
- Menghitung pembagian editor dan administrator setelah artikel dipublikasikan.
- Menambahkan setoran editor dan bukti transfer.

## 1.23.2 — 2026-09-12

- Menghapus penugasan pembayaran manual.
- Pembatasan pembayaran otomatis mengikuti assignment submission OJS.
