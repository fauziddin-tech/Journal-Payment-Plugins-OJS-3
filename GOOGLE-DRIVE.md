# Panduan Menghubungkan Google Drive (Tab Folder GD)

Tab **Folder GD** pada dashboard Pembayaran menampilkan, mengunggah, mengganti nama, dan memindahkan ke Sampah file publikasi di Google Drive jurnal. Plugin tidak memerlukan Composer atau `exec`. Koneksi memakai OAuth Google dengan tiga nilai:

| Nilai | Didapat dari |
|---|---|
| **Client ID** | Google Cloud Console → Google Auth Platform → Clients (Langkah 4) |
| **Client Secret** | Halaman yang sama dengan Client ID (Langkah 4) |
| **Refresh Token** | Google OAuth 2.0 Playground (Langkah 5) |

Waktu yang dibutuhkan sekitar 15 menit dan tidak ada biaya. Gunakan **akun Google resmi jurnal** (akun pemilik atau editor folder Drive jurnal) di semua langkah, bukan akun pribadi editor.

> Tips: kalau Anda punya beberapa akun Google di browser, buka panduan ini di jendela Incognito/Private dan masuk hanya dengan akun jurnal agar tidak tertukar.

---

## Langkah 1 — Buat proyek Google Cloud

1. Buka **[Buat proyek baru](https://console.cloud.google.com/projectcreate)**.
2. Isi **Project name**, misalnya `OJS Journal Payment`, lalu klik **Create**.
3. Pastikan nama proyek tersebut terpilih di bagian atas halaman Google Cloud Console sebelum lanjut.

Jika jurnal sudah punya proyek Google Cloud, proyek itu boleh dipakai; lewati langkah ini.

## Langkah 2 — Aktifkan Google Drive API

1. Buka **[Aktifkan Google Drive API](https://console.cloud.google.com/flows/enableapi?apiid=drive.googleapis.com)**.
2. Pastikan proyek yang terpilih benar, lalu klik **Enable** / **Aktifkan**.

Status API dapat diperiksa kembali di **[halaman Google Drive API](https://console.cloud.google.com/apis/library/drive.googleapis.com)**.

## Langkah 3 — Siapkan Google Auth Platform (layar persetujuan OAuth)

1. Buka **[Google Auth Platform — Overview](https://console.cloud.google.com/auth/overview)**. Jika muncul tulisan *Google Auth platform not configured yet*, klik **Get started**.
2. **App Information**: isi *App name* (misalnya `Journal Payment OJS`) dan pilih email jurnal pada *User support email*. Klik **Next**.
3. **Audience**, pilih salah satu:
   - **Internal** — hanya tersedia jika email jurnal memakai **Google Workspace** (domain sendiri, misalnya `redaksi@namajurnal.or.id`). Ini pilihan terbaik: token tidak kedaluwarsa dan tidak perlu verifikasi.
   - **External** — untuk akun `@gmail.com`. Setelah selesai, **wajib** menjalankan Langkah 3b.
4. **Contact Information**: isi email jurnal. Klik **Next**, centang persetujuan kebijakan, lalu **Create**.

### Langkah 3b — Khusus pilihan External: publikasikan aplikasi

Aplikasi External yang masih berstatus **Testing** hanya memberi Refresh Token yang **kedaluwarsa setelah 7 hari**, sehingga tab Folder GD akan tiba-tiba berhenti bekerja.

1. Buka **[Audience](https://console.cloud.google.com/auth/audience)**.
2. Pada *Publishing status*, klik **Publish app**, lalu **Confirm**. Status berubah menjadi **In production**.

Google mungkin menampilkan keterangan bahwa aplikasi belum diverifikasi. Itu wajar untuk aplikasi internal jurnal; verifikasi resmi Google tidak diperlukan selama yang memakai hanya akun jurnal sendiri (batas aplikasi belum terverifikasi adalah 100 pengguna).

## Langkah 4 — Buat OAuth Client (Client ID dan Client Secret)

1. Buka **[Clients](https://console.cloud.google.com/auth/clients)**, lalu klik **Create client**.
2. **Application type**: pilih **Web application**.
3. **Name**: bebas, misalnya `Journal Payment OJS`.
4. Pada **Authorized redirect URIs**, klik **Add URI** dan isi persis:

   ```
   https://developers.google.com/oauthplayground
   ```

   Alamat ini dipakai pada Langkah 5 untuk mengambil Refresh Token. Tanpa baris ini, Langkah 5 akan gagal dengan pesan `redirect_uri_mismatch`.
5. Klik **Create**.
6. Salin **Client ID** dan **Client Secret** yang muncul, lalu simpan di tempat aman (misalnya pengelola kata sandi). Anda juga bisa mengunduh JSON-nya.

> Client Secret kini hanya ditampilkan penuh saat dibuat. Jika terlewat, buka client tersebut di halaman [Clients](https://console.cloud.google.com/auth/clients) dan tambahkan secret baru.

## Langkah 5 — Dapatkan Refresh Token melalui OAuth Playground

1. Buka **[Google OAuth 2.0 Playground](https://developers.google.com/oauthplayground)**.
2. Klik ikon **roda gigi (⚙ OAuth 2.0 configuration)** di pojok kanan atas, lalu:
   - centang **Use your own OAuth credentials**;
   - tempel **OAuth Client ID** dan **OAuth Client secret** dari Langkah 4;
   - biarkan *Access type* tetap **Offline**;
   - tutup panel pengaturan.

   Langkah ini **wajib**. Tanpa kredensial sendiri, Refresh Token dari Playground akan dicabut otomatis oleh Google dalam 24 jam.
3. Pada panel kiri **Step 1 — Select & authorize APIs**, ketik scope berikut di kotak *Input your own scopes*:

   ```
   https://www.googleapis.com/auth/drive
   ```

   Lalu klik **Authorize APIs**.
4. Pilih **akun Google jurnal**. Jika muncul peringatan *Google hasn't verified this app*, klik **Advanced / Lanjutan** → **Go to Journal Payment OJS (unsafe) / Buka ... (tidak aman)**. Peringatan ini muncul karena aplikasi milik jurnal sendiri belum diverifikasi Google, bukan karena berbahaya.
5. Centang izin akses Google Drive, lalu klik **Continue / Izinkan**.
6. Anda kembali ke Playground pada **Step 2**. Klik **Exchange authorization code for tokens**.
7. Salin nilai **Refresh token** (biasanya diawali `1//`). Abaikan *Access token*; plugin memperbaruinya sendiri secara otomatis.

## Langkah 6 — Siapkan folder induk di Google Drive

1. Buka **[Google Drive](https://drive.google.com/drive/my-drive)** dengan akun jurnal.
2. Buat folder induk untuk file publikasi, misalnya `Publikasi Jurnal`. Jika folder berada di Drive bersama (Shared Drive), pastikan akun jurnal memiliki peran minimal **Editor/Content manager**.
3. Buka folder tersebut, lalu salin alamatnya dari address bar browser. Bentuknya:

   ```
   https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOpQrStUvWxYz
   ```

## Langkah 7 — Masukkan ke OJS

Ganti `DOMAIN` dan `PATH-JURNAL` sesuai jurnal Anda (contoh: `gifted.or.id` dan `gifted`).

1. Masuk sebagai **Site Administrator** atau **Pengelola Pembayaran dengan Akses Penuh**.
2. Buka daftar plugin:

   ```
   https://DOMAIN/index.php/PATH-JURNAL/management/settings/website#plugins
   ```

   Pada tab **Installed Plugins**, cari **Journal Payment / Pembayaran Jurnal** di kelompok *Generic Plugins*, klik panah biru di kirinya, lalu klik **Pengaturan**.
3. Gulir ke bagian **Google Drive**, lalu isi:
   - **Folder induk Google Drive** — alamat folder dari Langkah 6;
   - **OAuth Client ID** dan **OAuth Client Secret** — dari Langkah 4;
   - **OAuth Refresh Token** — dari Langkah 5.

   Ketiga kolom OAuth harus diisi sekaligus.
4. Klik **Save**. *Status OAuth* berubah menjadi **Tersimpan**. Kolom kredensial sengaja tampil kosong kembali setelah disimpan agar rahasia tidak dikirim ulang ke browser.
5. Buka tab Folder GD:

   ```
   https://DOMAIN/index.php/PATH-JURNAL/management/settings/website?view=drive#journalPayment
   ```

   Isi folder induk akan tampil. Jika muncul pesan galat, lihat bagian **Pemecahan masalah** di bawah.

Kredensial disimpan di `files_dir/journalPayment/google-drive/{contextId}/oauth.json` dengan izin file privat, di luar direktori publik OJS.

---

## Penamaan file dan halaman terakhir

Agar halaman terakhir terdeteksi akurat, awali nama PDF dengan rentang halaman, misalnya `001-010 Nama Artikel.pdf`. Sistem membaca angka terakhir pada rentang tersebut sebagai halaman terakhir. File tanpa nomor halaman di awal tetap tampil dan diurutkan berdasarkan waktu perubahan.

Tombol hapus tidak menghapus permanen. File dipindahkan ke **[Sampah Google Drive](https://drive.google.com/drive/trash)** dan masih dapat dipulihkan dari akun jurnal.

## Pemecahan masalah

| Gejala | Penyebab dan solusi |
|---|---|
| `redirect_uri_mismatch` saat Langkah 5 | Alamat `https://developers.google.com/oauthplayground` belum ditambahkan atau salah ketik di [Clients](https://console.cloud.google.com/auth/clients). Tambahkan persis, tunggu 1–5 menit, ulangi Langkah 5. |
| `invalid_grant` di tab Folder GD | Refresh Token kedaluwarsa atau dicabut. Umumnya karena aplikasi masih berstatus Testing (lakukan [Langkah 3b](https://console.cloud.google.com/auth/audience)), Playground dipakai tanpa kredensial sendiri, atau akses dicabut dari akun Google. Ulangi Langkah 5 lalu simpan token baru di OJS. |
| `access_denied` / `org_internal` | Akun yang dipakai login di Playground tidak termasuk dalam Audience. Gunakan akun jurnal yang sama dengan pemilik proyek, atau ubah Audience. |
| Google Drive API belum aktif | Aktifkan melalui [tautan Langkah 2](https://console.cloud.google.com/flows/enableapi?apiid=drive.googleapis.com). |
| Folder tidak ditemukan atau akses ditolak | Akun pemilik Refresh Token tidak punya akses Editor ke folder induk. Bagikan folder ke akun tersebut di Google Drive. |
| `Ekstensi cURL PHP belum aktif` | Aktifkan ekstensi `curl` pada pengaturan PHP hosting (misalnya melalui cPanel → Select PHP Version). |

## Mencabut atau mengganti akses

- Untuk memutus koneksi dari OJS: buka Pengaturan plugin, centang **Hapus kredensial OAuth yang tersimpan**, lalu **Save**.
- Untuk mencabut izin dari sisi Google: buka **[Koneksi pihak ketiga akun Google](https://myaccount.google.com/connections)** dengan akun jurnal, pilih aplikasi Journal Payment OJS, lalu hapus aksesnya.
- Jika Client Secret bocor: buat secret baru di [Clients](https://console.cloud.google.com/auth/clients), hapus secret lama, ulangi Langkah 5, lalu simpan ketiga nilai baru di OJS.
