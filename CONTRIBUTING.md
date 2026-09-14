# Contributing

Terima kasih atas minat untuk membantu pengembangan Journal Payment Plugin.

## Ruang lingkup

Kontribusi dapat berupa:

- perbaikan kompatibilitas OJS 3.3;
- peningkatan keamanan dan kontrol akses;
- perbaikan antarmuka dan aksesibilitas;
- optimasi kueri dan performa;
- dokumentasi;
- pengujian instalasi dan upgrade.

## Alur kontribusi

1. Buat fork repositori.
2. Buat branch dengan nama yang jelas.
3. Lakukan perubahan sekecil dan sefokus mungkin.
4. Jangan menyertakan kredensial atau data jurnal nyata.
5. Uji instalasi baru dan upgrade dari versi sebelumnya.
6. Ajukan pull request dengan ringkasan, langkah pengujian, dan dampak kompatibilitas.

## Standar perubahan

- Pertahankan kompatibilitas OJS 3.3.0-x dan PHP 7.4.
- Gunakan API OJS/PKP bila tersedia.
- Semua tindakan mutasi harus memeriksa CSRF dan hak akses.
- Semua kueri wajib dibatasi oleh `context_id`.
- Akses editor harus mengikuti `stage_assignments`.
- File pembayaran dan dokumen tidak boleh ditempatkan di direktori publik.
- Perubahan skema harus aman untuk upgrade dan tidak menghapus data.
- Perbarui `CHANGELOG.md` untuk perubahan yang berdampak pada pengguna.

## Pengujian minimum

- Instalasi baru.
- Upgrade tanpa kehilangan data.
- Login Site Administrator.
- Login Journal Manager.
- Login Editor dengan dan tanpa assignment.
- Login Production Editor.
- Preview serta unduh kuitansi, LOA, dan sertifikat.
- Verifikasi QR.
- Unggah bukti pembayaran dan galley.
- Laporan keuangan dan honorarium.
