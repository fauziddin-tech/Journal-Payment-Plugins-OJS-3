# Konfigurasi Folder GD

Tab **Folder GD** mengakses Google Drive secara langsung tanpa Composer dan tanpa fungsi `exec`. Integrasi menggunakan OAuth akun Google jurnal melalui tiga nilai: Client ID, Client Secret, dan Refresh Token.

## Persiapan Google Cloud

1. Aktifkan **Google Drive API** pada proyek Google Cloud yang digunakan jurnal.
2. Siapkan OAuth Client dan berikan scope `https://www.googleapis.com/auth/drive` saat membuat Refresh Token.
3. Ubah status OAuth consent screen menjadi **In production/Published**. Pada status Testing, Refresh Token dapat kedaluwarsa setelah tujuh hari.
4. Pastikan akun Google pemilik Refresh Token memiliki hak Editor pada folder induk.

## Pengaturan di OJS

1. Buka Website → Plugins → Journal Payment → Settings.
2. Isi URL folder induk Google Drive.
3. Isi Client ID, Client Secret, dan Refresh Token sekaligus, lalu simpan.
4. Buka Website → Pembayaran → Folder GD.

Rahasia OAuth disimpan di `files_dir/journalPayment/google-drive/{contextId}/oauth.json` dengan izin file privat. Kolom kredensial selalu kembali kosong setelah disimpan agar nilainya tidak dikirim ulang ke browser.

## Penamaan file dan halaman terakhir

Agar halaman terakhir terdeteksi akurat, awali nama PDF dengan rentang halaman, misalnya `001-010 Nama Artikel.pdf`. Sistem akan membaca angka terakhir pada rentang tersebut sebagai halaman terakhir. Jika nama tidak mengandung nomor halaman di awal, file tetap tampil dan diurutkan berdasarkan waktu perubahan.

Tombol hapus tidak menghapus permanen. File dipindahkan ke Sampah Google Drive dan dapat dipulihkan dari akun Drive.
