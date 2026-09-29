# Kebijakan Privasi & Perlindungan Biometrik — Smart Absen

Dokumen ini menjelaskan prinsip privasi, penanganan data biometrik wajah, dan standar keamanan data pada sistem **Smart Absen**.

---

## 1. Perlindungan Data Biometrik (Biometric Data Protection)

### Extraction & Embedding Vector
- Smart Absen **tidak pernah menyimpan foto wajah mentah (raw photo)** di dalam database produksi.
- Kamera hanya memproses frame wajah secara real-time untuk mengekstraksi **128-dimensional floating point vector** (SFace Embedding).
- Vektor embedding ini tidak dapat direkonstruksi ulang menjadi foto gambar asli (One-Way Extraction).

### Encrypted Template Sync
- Vektor biometrik yang dikirimkan antara Central Admin Server (`app-smart-absensi`) dan Edge Node (`Orange Pi Lite 2`) disinkronkan melalui koneksi HTTP/REST API yang terenkripsi TLS (HTTPS) atau token terautentikasi Laravel Sanctum.
- Pada database `members`, field `face_embedding` disembunyikan secara default (`$hidden = ['face_embedding']`) dari serialisasi JSON API umum untuk mencegah kebocoran data tak sengaja.

---

## 2. Consent-Aware Biometrics (Persetujuan Pengguna)

- Pendaftaran vektor biometrik dilakukan secara sadar oleh administrator/pengguna.
- Penonaktifan akun anggota (`is_active = false`) secara otomatis mengecualikan vektor biometrik dari proses pencocokan lokal di seluruh Edge Node terhubung.

---

## 3. Jejak Audit (Audit Trail & Logging)

- Seluruh tindakan sensitif (pendaftaran anggota baru, pembatalan akses, sinkronisasi template) dicatat dalam **Audit Logs**.
- Log presensi menyimpan `device_id`, timestamp presensi, dan `confidence score` tanpa menyimpan cuplikan gambar wajah.
