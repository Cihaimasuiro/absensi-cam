# Panduan Rotasi Kunci Enkripsi & Prosedur Pairing

Dokumen ini mendokumentasikan prosedur keamanan rotasi kunci enkripsi embedding wajah (`ENROLLMENT_EMBED_KEY`) serta mekanisme penanganan status `consumed` pada alur *Device-Initiated Pairing*.

---

## 1. Latar Belakang & Status Kunci Lama

Kunci bawaan contoh (`t7rXp59ZkE+L9Y6qP3R8FmNcK5WxYJ2HhDbjPvC4RnE=`) sempat tercatat pada riwayat commit Git publik (`4b96a97`, `08214d7`, `ec35962`, dan `92b05dc`). Kunci tersebut dianggap **bocor/compromised**.

### Pemeriksaan Data di Server Aktual (PENTING)
> [!WARNING]
> Pengecekan `FaceTemplate::count()` pada database mesin pengembangan lokal **tidak mencerminkan** kondisi server operasional/sekolah (misal di `192.168.10.66`). Jika Orange Pi sudah pernah beroperasi dan mengenali wajah, template wajah terenkripsi **sudah ada di database server tersebut**.

Sebelum mengubah kunci di server operasional, jalankan perintah ini langsung di terminal server yang bersangkutan:
```bash
php artisan tinker --execute="echo 'FaceTemplate count: ' . \App\Domain\Enrollment\Models\FaceTemplate::count();"
```
- Jika hasilnya `> 0`: **JANGAN** langsung mengubah `ENROLLMENT_EMBED_KEY` di server karena template wajah lama akan gagal didekripsi (autentikasi wajah gagal total). Jalankan prosedur enkripsi ulang pada Bagian 4 terlebih dahulu.
- Jika hasilnya `= 0`: Kunci baru dapat langsung dipasang di file `.env` server.

---

## 2. Cara Membuat Kunci Baru

Kunci yang dibutuhkan oleh `FaceEncryptionService` (AES-256-GCM) adalah string 32-byte acak berkode Base64.

Jalankan perintah berikut di terminal server:
```bash
openssl rand -base64 32
```
*Output akan menghasilkan string 32-byte acak berkode Base64.*

---

## 3. Penyimpanan & Transmisi Kunci (Aturan Keamanan)

1. **Hanya di File `.env` Server**:
   Simpan kunci di file `.env` pada server Laravel:
   ```ini
   ENROLLMENT_EMBED_KEY="<hasil dari: openssl rand -base64 32>"
   ```
2. **Dilarang Commit ke Git**:
   File `.env` sudah masuk dalam `.gitignore`. Jangan pernah menuliskan kunci literal di dalam file kode sumber, dokumen, migrasi, atau template seeder.
3. **Pengambilan di Kode**:
   Aplikasi membaca kunci secara eksklusif lewat `config('app.enrollment_embed_key')`. Pemanggilan `env()` di luar file konfigurasi dilarang karena akan menghasilkan `null` saat `config:cache` aktif.
4. **Keamanan Transmisi ke Perangkat Edge (Wajib HTTPS di Produksi)**:
   > [!CAUTION]
   > Kunci enkripsi dan API Token Sanctum **TIDAK dienkripsi lapis kedua** saat proses pairing; keduanya dikirim sebagai teks biasa dalam payload JSON respons `POST /api/v1/devices/pairing/poll`.
   >
   > Satu-satunya pelindung payload tersebut saat transit adalah **lapisan TLS (HTTPS)**.
   > - Pada lingkungan **PRODUKSI**: Server **WAJIB** menggunakan HTTPS (`PAIRING_ALLOW_HTTP=false`).
   > - Flag `PAIRING_ALLOW_HTTP=true` **HANYA** boleh diaktifkan sementara untuk uji coba di jaringan tertutup LAN lokal tanpa TLS. Siapa pun yang melakukan packet sniffing di LAN tanpa TLS berpotensi membaca token dan kunci ini.

---

## 4. Langkah Enkripsi Ulang Data Wajah (Jika Ada Data Eksisting di Server)

Jika server sudah memiliki data wajah terdaftar:

1. **Buat Kunci Baru** dan simpan sementara sebagai `ENROLLMENT_EMBED_KEY_NEW` di file `.env` server.
2. **Jalankan Skrip Rotasi**:
   Iterasi setiap baris di tabel `face_templates`:
   - Dekripsi `embedding_enc` menggunakan kunci lama (`config('app.enrollment_embed_key')`) dengan AAD `${student_id}_${model_version}`.
   - Enkripsi ulang hasil raw vector (2048 bytes) menggunakan kunci baru dengan nonce acak 12-byte baru.
   - Simpan ciphertext + tag baru ke kolom `embedding_enc`.
3. **Ganti Kunci Aktif**:
   Ubah `ENROLLMENT_EMBED_KEY` di `.env` menjadi kunci baru, lalu hapus `ENROLLMENT_EMBED_KEY_NEW`.
4. **Re-Pairing Perangkat Edge**:
   Lakukan pairing ulang atau update file `/opt/smart-absensi/edge-engine/.env` di setiap Orange Pi agar kunci lokal perangkat sinkron dengan kunci baru di server.

---

## 5. Dokumentasi Perilaku Status `consumed` pada Polling Pairing

### Alur Normal
1. Perangkat mengirim `POST /api/v1/devices/pairing/request` dan menerima `pairing_code` (misal `ABC-XYZ`) dan `poll_token`.
2. Administrator meninjau hostname, MAC address fisik, dan IP perangkat di Admin Dashboard, lalu mengklik **Setujui**.
3. Perangkat melakukan polling ke `POST /api/v1/devices/pairing/poll`:
   - Server membuka transaksi database dengan `lockForUpdate()`.
   - Mengenerate token Sanctum baru (`DeviceToken`).
   - Mengambil kunci enkripsi dari `config('app.enrollment_embed_key')`.
   - Mengubah status request menjadi `consumed` dan mengisi `claimed_at = now()`.
   - Mengembalikan payload kredensial (HTTP 200).
4. Perangkat menyimpan kredensial secara atomik ke file `.env` lokal (`0600`) dan langsung memulai operasional.

### Kasus Khusus: Respons Poll Hilang di Jaringan (Packet Loss)
Jika paket respons HTTP 200 dari server hilang di tengah jalan (misal koneksi WiFi sekolah terputus sesaat):
- Status di database server **sudah** berubah menjadi `consumed`.
- Saat perangkat mencoba polling ulang dengan `poll_token` yang sama, server mengembalikan status **HTTP 410 Gone** (`status: "consumed"`, pesan: *"Pairing request has already been consumed and cannot be retrieved again"*).
- **Penanganan pada Klien Rust (`engine-sync`)**:
  - Klien Rust mendeteksi status HTTP 410 (`consumed` atau `expired`).
  - Klien **tidak panik** dan **tidak crash** (mencegah *systemd restart loop*).
  - Klien mencatat log peringatan: `Kode pairing XYZ kedaluwarsa atau sudah diklaim (HTTP 410). Meminta kode pairing baru...`
  - Klien memberi jeda backoff **10 detik** agar tidak membanjiri server.
  - Klien otomatis meminta kode pairing baru ke server dan menampilkan kode baru tersebut di log console / journalctl.
  - Administrator cukup mengklik persetujuan sekali lagi di Admin Dashboard untuk kode yang baru.
