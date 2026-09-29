# App Smart Absensi

Aplikasi Smart Absensi yang dikembangkan menggunakan **Laravel 13**. Aplikasi ini dirancang dengan prinsip arsitektur tingkat enterprise untuk memastikan *maintainability*, *scalability*, dan *clean code*.

---

## 🏗️ Arsitektur Sistem

Aplikasi ini menggunakan pola arsitektur **Modular Monolith + Pragmatic Layered Architecture**.
Kami memisahkan batas domain berdasarkan modul bisnis utama, memisahkan *business logic* dari HTTP Layer (Controllers), dan mempertahankan kontrol ketat pada *database operations*.

### 🔄 Alur Permintaan (Request Flow)
Secara default, alur permintaan HTTP mematuhi lapisan berikut:

```text
HTTP Request
    ↓
Route
    ↓
FormRequest (Validasi Otorisasi & Input)
    ↓
Controller (Layer Tipis)
    ↓
Service / Action (Business Logic & Transaksi Database)
    ↓
Query / Eloquent Model (Pengambilan Data)
    ↓
Database
    ↓
Resource / Response (Format API)
```

Untuk pekerjaan yang berjalan asinkron (background jobs):
```text
Controller / Service  →  Job  →  Queue  →  Worker / Horizon
```

Untuk alur *side-effects* (efek samping):
```text
Business Operation  →  Event  →  Listener
```

---

## 📂 Struktur Direktori

Kode aplikasi diatur berdasarkan batas Modul Bisnis (*Business Module*) di dalam folder `app/Domain/`. 

```text
app/
├── Domain/
│   ├── User/             # Modul Bisnis User
│   │   ├── Actions/      # Eksekusi operasi bisnis tunggal (misal: ApproveUser)
│   │   ├── DTOs/         # Data Transfer Objects untuk input antar layer yang type-safe
│   │   ├── Models/       # Eloquent Models, Relations, & Casts 
│   │   ├── Queries/      # Kueri kompleks atau reusable (Builder Pattern)
│   │   └── Services/     # Alur bisnis yang melibatkan beberapa langkah/transaksi
│   │
│   └── [Modul Lainnya]/  # Modul absensi
│
├── Http/
│   ├── Controllers/      # Hanya untuk memproses Request dan melempar ke Service
│   ├── Requests/         # Form Requests (Validasi API)
│   └── Resources/        # API Resources (Format JSON Response)
│
└── Jobs/, Events/, Listeners/ ... dsb.
```

---

## 📋 Prinsip Pengembangan

1. **Thin Controllers:** Controller dilarang memuat *business logic* (tidak boleh lebih dari sekadar menerima request, memanggil service/action, dan me-return response).
2. **DTO (Data Transfer Object):** Data request dari luar akan diparsing menjadi DTO yang *strongly-typed* sebelum masuk ke layer Service.
3. **Pemisahan Kueri:** Kueri kompleks harus diletakkan pada kelas `Queries/`, bukan di dalam model atau controller.
4. **Validasi Sentralisasi:** Seluruh validasi input dan pengecekan otorisasi sederhana diletakkan di dalam `FormRequest`.
5. **No N+1 Problem:** Selalu evaluasi kueri Eloquent untuk menghindari N+1 (*Eager Load* relasi sesuai kebutuhan).

---

## 🚀 Setup & Instalasi

1. Clone repositori ini.
2. Jalankan `composer install`.
3. Salin `.env.example` ke `.env` dan konfigurasikan akses *database*.
4. Jalankan `php artisan key:generate`.
5. Jalankan `php artisan migrate:fresh --seed` (jika menggunakan instalasi awal).
6. Jalankan `php artisan serve` untuk memulai *development server*.
