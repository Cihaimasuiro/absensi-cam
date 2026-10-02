# Rencana Alur Pendaftaran & Pairing Perangkat (Device Provisioning Flow)

Karena kita menggunakan arsitektur *Distributed Edge Computing*, server Laravel harus memiliki sistem keamanan otentikasi agar tidak menerima data absensi sembarangan dari perangkat yang tidak dikenal. 

Berikut adalah rancangan alur (plan) langkah demi langkah untuk melakukan *setup* dan *pairing* (pemasangan) antara **Orange Pi (Edge AI)** dan **Laravel 13 (Server)**.

---

## 1. Persiapan di Dashboard Laravel (Sisi Admin)

**Tujuan:** Mendaftarkan identitas alat baru dan membuat kunci akses (API Token).

1. Admin sekolah login ke *Dashboard Web Smart Absensi*.
2. Masuk ke menu **Manajemen Perangkat (Devices)**.
3. Klik tombol **Tambah Perangkat Baru**.
4. Admin mengisi form sederhana:
   - **Nama Perangkat:** (Misal: "Kamera Gerbang Depan SMP 1")
   - **Lokasi:** (Misal: "Gerbang Utara")
   - **Sekolah:** (Pilih sekolah tempat alat ini dipasang)
5. **Proses di Backend:** 
   - Laravel secara otomatis menghasilkan `device_uuid` unik dan sebuah kunci rahasia **API Bearer Token** (menggunakan Laravel Sanctum).
6. **Output Layar:** 
   - Layar Laravel akan menampilkan *Pairing Token* panjang (hanya ditampilkan sekali untuk alasan keamanan) dan instruksi singkat cara memasukkannya ke Orange Pi.

---

## 2. Proses Pairing di Orange Pi (Sisi Edge/Hardware)

**Tujuan:** Memberitahu mesin Orange Pi ke mana dia harus mengirim data (URL Server) dan menggunakan kunci apa (API Token).

1. Teknisi merakit Orange Pi dan menghubungkannya ke jaringan WiFi/LAN sekolah (atau via SSH).
2. Teknisi menjalankan skrip instalasi (`run_m0_on_board.sh` yang sebelumnya kita buat).
3. Di akhir instalasi, skrip akan memunculkan menu interaktif (*Command Line Interface*):
   ```text
   > Masukkan URL Server Laravel Anda: 
   [Input]: https://absensi.sekolah.com
   
   > Masukkan Pairing Token dari Dashboard:
   [Input]: 1|vLqP8...xyz
   ```
4. **Proses di Edge:**
   - Skrip menyimpan URL dan Token tersebut ke dalam sebuah file rahasia lokal di Orange Pi, misalnya `config.json` atau `.env`.
   - *Service* Edge Engine (`engine.py` dan `sync_worker.py`) membaca konfigurasi tersebut.
5. **Uji Coba Handshake (Ping):**
   - *Sync Worker* Orange Pi akan segera menembakkan HTTP POST ke `https://absensi.sekolah.com/api/v1/devices/ping` dengan menyertakan Token tersebut di *Header*.

---

## 3. Verifikasi & Operasional Berkelanjutan

**Tujuan:** Memastikan kedua sistem terhubung dan mulai menyinkronkan data.

1. **Ping Berhasil:** Laravel memvalidasi Token. Jika valid, Laravel mengubah status perangkat tersebut dari `Menunggu Konfigurasi` menjadi `Online` di database.
2. **Download Face Template (Sinkronisasi Awal):**
   - Setelah sukses ping, Orange Pi langsung meminta data wajah (*Face Embeddings*) milik seluruh anggota dari sekolah tersebut.
   - Orange Pi mengunduhnya ke dalam *database* SQLite lokalnya.
3. **Mulai Memindai:**
   - Kamera Orange Pi aktif dan siap memindai wajah siswa.
   - Setiap absen yang terdeteksi akan dikirim ke Laravel menyertakan `API Token` yang sama, membuktikan bahwa data tersebut sah berasal dari "Kamera Gerbang Depan SMP 1".

---

## Apa yang harus kita bangun/koding selanjutnya?

Untuk merealisasikan rencana di atas, ini adalah urutan kode yang perlu kita tambahkan:

1. **Di Laravel:** 
   - Membuat `DeviceController` dengan metode `store` yang menghasilkan Token (Laravel Sanctum).
   - Membuat *endpoint* API `/api/v1/devices/ping` untuk *handshake* awal.
   - Menambahkan halaman UI (pada `resources/views/devices/create.blade.php`) agar admin bisa men-generate Token.
2. **Di Orange Pi (Edge Engine):**
   - Membuat *script* python sederhana (`pairing.py`) atau menambahkan prompt di `run_m0_on_board.sh` untuk menyimpan kredensial ke `config.json`.
   - Memodifikasi `sync_worker.py` agar secara dinamis membaca *URL* dan *Token* dari file konfigurasi, bukan dari teks *hardcoded*.

*Plan ini memisahkan tanggung jawab dengan sangat rapi dan memastikan keamanan jaringan skala tingkat enterprise.*
