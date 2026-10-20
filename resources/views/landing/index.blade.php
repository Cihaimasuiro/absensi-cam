<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Absensi — Sistem Presensi Biometrik & Edge Vision AI</title>
    <meta name="description" content="Sistem absensi wajah cerdas berbasis Edge AI Vision. Bekerja offline-first di gate/turnstile dengan inferensi sub-detik dan sinkronisasi cloud terpusat.">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-canvas text-ink font-sans antialiased selection:bg-primary/20 selection:text-primary">

{{-- ─────────────────────────────── Header ──────────────────────────────── --}}
<header class="sticky top-0 z-40 bg-canvas/85 backdrop-blur-md border-b border-hairline">
    <div class="max-w-6xl mx-auto px-md md:px-lg h-14 flex items-center justify-between gap-md">
        <a href="{{ route('landing') }}" class="flex items-center gap-xs shrink-0">
            <img src="{{ asset('images/logo.png') }}" class="w-7 h-7 rounded-md object-contain" alt="Logo Smart Absensi">
            <span class="text-[15px] font-bold tracking-tight text-ink">Smart Absensi</span>
        </a>

        <nav class="hidden md:flex items-center gap-lg text-[12.5px] text-ink-secondary" aria-label="Navigasi halaman">
            <a href="#arsitektur" class="hover:text-primary transition-colors">Arsitektur</a>
            <a href="#fitur" class="hover:text-primary transition-colors">Fitur</a>
            <a href="#spesifikasi" class="hover:text-primary transition-colors">Spesifikasi</a>
            <a href="#alur" class="hover:text-primary transition-colors">Alur Kerja</a>
        </nav>

        @auth
            <a href="{{ route('dashboard') }}" class="btn btn-primary btn-sm">
                <i data-lucide="layout-dashboard" class="w-3.5 h-3.5"></i>
                <span>Buka Monitor</span>
            </a>
        @else
            <a href="{{ route('login') }}" class="btn btn-secondary btn-sm">
                <i data-lucide="log-in" class="w-3.5 h-3.5"></i>
                <span>Masuk</span>
            </a>
        @endauth
    </div>
</header>

{{-- ─────────────────────────────── Hero ────────────────────────────────── --}}
<section class="border-b border-hairline">
    <div class="max-w-6xl mx-auto px-md md:px-lg py-14 md:py-20">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-12 items-center">

            {{-- Left: copy (asymmetric 7/12) --}}
            <div class="lg:col-span-7">
                <p class="text-[11px] font-mono uppercase tracking-[0.14em] text-primary font-semibold mb-3">
                    Edge Vision AI · Offline-First
                </p>

                <h1 class="text-[34px] sm:text-[42px] md:text-[52px] font-extrabold tracking-[-0.03em] leading-[1.05] text-ink m-0">
                    Presensi wajah<br>
                    <span class="text-ink-faint">dalam milidetik.</span>
                </h1>

                <p class="text-[15px] sm:text-[16px] text-ink-secondary max-w-[52ch] mt-5 mb-7 leading-relaxed">
                    Pengenalan wajah diproses langsung di gate — bukan di cloud. Identitas tervalidasi
                    secara lokal, data tersimpan di ring buffer terenkripsi, dan tersinkronisasi ke server
                    pusat begitu jaringan tersedia.
                </p>

                <div class="flex flex-wrap items-center gap-sm">
                    @auth
                        <a href="{{ route('dashboard') }}" class="btn btn-primary btn-lg">
                            <i data-lucide="video" class="w-4 h-4"></i>
                            <span>Buka Live Monitor</span>
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="btn btn-primary btn-lg">
                            <i data-lucide="log-in" class="w-4 h-4"></i>
                            <span>Masuk ke Konsol</span>
                        </a>
                    @endauth
                    <a href="#arsitektur" class="btn btn-secondary btn-lg">
                        <i data-lucide="cpu" class="w-4 h-4"></i>
                        <span>Lihat Arsitektur</span>
                    </a>
                </div>

                {{-- Live counters from LandingController --}}
                <dl class="grid grid-cols-3 gap-px mt-10 bg-hairline border border-hairline rounded-lg overflow-hidden">
                    <div class="bg-canvas px-4 py-3">
                        <dt class="text-[10.5px] font-semibold uppercase tracking-wide text-ink-muted">Anggota Aktif</dt>
                        <dd class="text-[20px] font-bold text-ink tabular-nums m-0 leading-tight mt-0.5">
                            {{ number_format($stats['total_members'] ?? 0, 0, ',', '.') }}
                        </dd>
                    </div>
                    <div class="bg-canvas px-4 py-3">
                        <dt class="text-[10.5px] font-semibold uppercase tracking-wide text-ink-muted">Terminal</dt>
                        <dd class="text-[20px] font-bold text-ink tabular-nums m-0 leading-tight mt-0.5">
                            {{ number_format($stats['total_devices'] ?? 0, 0, ',', '.') }}
                        </dd>
                    </div>
                    <div class="bg-canvas px-4 py-3">
                        <dt class="text-[10.5px] font-semibold uppercase tracking-wide text-ink-muted">Online</dt>
                        <dd class="text-[20px] font-bold text-success tabular-nums m-0 leading-tight mt-0.5">
                            {{ number_format($stats['online_devices'] ?? 0, 0, ',', '.') }}
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- Right: terminal transcript (5/12) --}}
            <div class="lg:col-span-5">
                <div class="rounded-lg border border-[#23272f] bg-[#0d1117] overflow-hidden shadow-[0_16px_40px_-12px_rgba(16,24,40,0.35)]">
                    <div class="flex items-center gap-1.5 px-3 py-2 border-b border-[#23272f] bg-[#161b22]">
                        <span class="w-2.5 h-2.5 rounded-full bg-[#ff5f57]"></span>
                        <span class="w-2.5 h-2.5 rounded-full bg-[#febc2e]"></span>
                        <span class="w-2.5 h-2.5 rounded-full bg-[#28c840]"></span>
                        <span class="ml-2 text-[10.5px] font-mono text-[#8b949e]">edge-engine — gate-01</span>
                    </div>
                    <pre class="m-0 p-4 text-[11px] leading-[1.75] font-mono text-[#c9d1d9] overflow-x-auto whitespace-pre"><code><span class="text-[#8b949e]">$</span> smart-absensi --gate gate-01

<span class="text-[#3fb950]">[INFO ]</span> <span class="text-[#58a6ff]">engine::init</span>    ONNX MobileFaceNet (FP16)
<span class="text-[#3fb950]">[INFO ]</span> <span class="text-[#58a6ff]">camera::open</span>    /dev/video0 · 1280×720 MJPEG
<span class="text-[#3fb950]">[INFO ]</span> <span class="text-[#58a6ff]">db::sqlite</span>      ring buffer ready · 0 pending
<span class="text-[#3fb950]">[INFO ]</span> <span class="text-[#58a6ff]">sync::worker</span>    draining every 30s

<span class="text-[#3fb950]">[MATCH]</span> <span class="text-[#e6edf3]">Ahmad Rizky</span> · NISN 00829102
        score <span class="text-[#d2a8ff]">0.978</span> · liveness <span class="text-[#d2a8ff]">0.941</span> · <span class="text-[#3fb950]">IN</span> · 07:05:12
<span class="text-[#3fb950]">[WRITE]</span> buffered <span class="text-[#79c0ff]">8f21e5b1-7a2e-4b71-b0e2</span>
<span class="text-[#3fb950]">[SYNC ]</span> POST /api/v1/attendance/batch → <span class="text-[#3fb950]">201</span>
        <span class="text-[#8b949e]">1 created · 0 duplicate · 0 rejected</span></code></pre>
                </div>
                <p class="text-[11px] text-ink-faint mt-2 mb-0 font-mono">
                    Latensi inferensi end-to-end: &lt; 800 ms pada CPU quad-core.
                </p>
            </div>
        </div>
    </div>
</section>

{{-- ─────────────────────────────── Arsitektur ──────────────────────────── --}}
<section id="arsitektur" class="border-b border-hairline bg-canvas-soft">
    <div class="max-w-6xl mx-auto px-md md:px-lg py-14 md:py-20">
        <div class="max-w-[60ch] mb-10">
            <p class="text-[11px] font-mono uppercase tracking-[0.14em] text-primary font-semibold mb-2">Arsitektur Dua Lapis</p>
            <h2 class="text-[26px] sm:text-[32px] font-bold tracking-[-0.025em] text-ink m-0">
                Edge terminal yang mandiri, ledger cloud yang otoritatif.
            </h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-md">

            <article class="card bg-canvas">
                <div class="flex items-center gap-xs mb-sm">
                    <span class="flex items-center justify-center w-7 h-7 rounded-md bg-primary-soft text-primary">
                        <i data-lucide="cpu" class="w-4 h-4"></i>
                    </span>
                    <h3 class="text-[14px] font-semibold text-ink m-0">Edge Engine — Rust</h3>
                </div>
                <p class="text-[12.5px] text-ink-muted leading-relaxed m-0">
                    Berjalan di perangkat gate. Menangkap frame, mendeteksi wajah, menghitung embedding
                    512-dimensi, mencocokkan dengan template lokal, dan menulis hasilnya ke SQLite.
                    Tidak ada panggilan jaringan pada jalur kritis.
                </p>
                <dl class="mt-4 pt-3 border-t border-hairline grid grid-cols-2 gap-x-4 gap-y-2 text-[11.5px]">
                    <div><dt class="text-ink-faint">Runtime</dt><dd class="m-0 text-ink font-medium">Rust + ONNX Runtime</dd></div>
                    <div><dt class="text-ink-faint">Model</dt><dd class="m-0 text-ink font-medium">MobileFaceNet FP16</dd></div>
                    <div><dt class="text-ink-faint">Penyimpanan</dt><dd class="m-0 text-ink font-medium">SQLite ring buffer</dd></div>
                    <div><dt class="text-ink-faint">Mode gagal</dt><dd class="m-0 text-ink font-medium">Tetap merekam offline</dd></div>
                </dl>
            </article>

            <article class="card bg-canvas">
                <div class="flex items-center gap-xs mb-sm">
                    <span class="flex items-center justify-center w-7 h-7 rounded-md bg-primary-soft text-primary">
                        <i data-lucide="globe" class="w-4 h-4"></i>
                    </span>
                    <h3 class="text-[14px] font-semibold text-ink m-0">Cloud Ledger — Laravel</h3>
                </div>
                <p class="text-[12.5px] text-ink-muted leading-relaxed m-0">
                    Menerima batch presensi secara idempoten, menyimpan template wajah terenkripsi
                    AES-256-GCM, menghitung DTR harian, dan menyajikan laporan PDF/XLSX/CSV.
                </p>
                <dl class="mt-4 pt-3 border-t border-hairline grid grid-cols-2 gap-x-4 gap-y-2 text-[11.5px]">
                    <div><dt class="text-ink-faint">Framework</dt><dd class="m-0 text-ink font-medium">Laravel + Sanctum</dd></div>
                    <div><dt class="text-ink-faint">Idempotensi</dt><dd class="m-0 text-ink font-medium">UUID v4 sebagai PK</dd></div>
                    <div><dt class="text-ink-faint">Biometrik</dt><dd class="m-0 text-ink font-medium">AES-256-GCM + AAD</dd></div>
                    <div><dt class="text-ink-faint">Audit</dt><dd class="m-0 text-ink font-medium">Activity log</dd></div>
                </dl>
            </article>
        </div>

        {{-- Sync contract --}}
        <div class="card bg-canvas mt-md">
            <div class="flex flex-wrap items-baseline justify-between gap-xs mb-sm">
                <h3 class="text-[13px] font-semibold text-ink m-0">Kontrak Sinkronisasi</h3>
                <span class="text-[11px] font-mono text-ink-faint">POST /api/v1/attendance/batch</span>
            </div>
            <pre class="m-0 p-3 rounded-md bg-canvas-soft border border-hairline text-[11px] leading-relaxed font-mono text-ink-secondary overflow-x-auto whitespace-pre"><code>{
  "device_code": "GATE-01",
  "records": [
    { "id": "8f21e5b1-…", "student_id": 142, "captured_at": "2026-10-16T07:05:12Z",
      "direction": "in", "score": 0.978, "liveness_score": 0.941, "time_source": "edge" }
  ]
}

<span class="text-ink-faint">→ 200</span> { "results": [ { "id": "8f21e5b1-…", "status": "created" } ] }
<span class="text-ink-faint">status ∈</span> created | duplicate | rejected</code></pre>
        </div>
    </div>
</section>

{{-- ─────────────────────────────── Fitur ───────────────────────────────── --}}
<section id="fitur" class="border-b border-hairline">
    <div class="max-w-6xl mx-auto px-md md:px-lg py-14 md:py-20">
        <div class="max-w-[60ch] mb-10">
            <p class="text-[11px] font-mono uppercase tracking-[0.14em] text-primary font-semibold mb-2">Kemampuan</p>
            <h2 class="text-[26px] sm:text-[32px] font-bold tracking-[-0.025em] text-ink m-0">
                Yang benar-benar berjalan hari ini.
            </h2>
        </div>

        @php
            $features = [
                ['icon' => 'scan-face',  'title' => 'Pengenalan Wajah On-Device',
                 'body'  => 'Embedding 512-d dihitung di perangkat gate. Template tidak pernah meninggalkan terminal dalam bentuk mentah.',
                 'meta'  => 'MobileFaceNet · 2048 byte'],
                ['icon' => 'wifi-off',   'title' => 'Operasional Tanpa Jaringan',
                 'body'  => 'Saat jaringan atau server padam, gerbang tetap terbuka dan presensi tetap terekam di database lokal terenkripsi.',
                 'meta'  => 'SQLite ring buffer · auto-drain'],
                ['icon' => 'shield-check','title' => 'Pairing Perangkat Kriptografis',
                 'body'  => 'Setiap terminal diverifikasi lewat token pairing bertanda tangan. Administrator menyetujui atau mencabut akses dalam satu klik.',
                 'meta'  => 'Sanctum token · handshake otentik'],
                ['icon' => 'calendar',   'title' => 'Kalkulasi DTR Otomatis',
                 'body'  => 'Sistem menghitung jam kedatangan (MIN) dan kepulangan (MAX) per anggota per hari meski melintas berkali-kali.',
                 'meta'  => 'Daily Time Record standard'],
                ['icon' => 'file-spreadsheet', 'title' => 'Ekspor Multi-Format',
                 'body'  => 'Unduh rekapitulasi kehadiran sebagai PDF siap cetak, XLSX berbasis template instansi, atau CSV untuk pemrosesan lanjut.',
                 'meta'  => 'PDF · XLSX · CSV'],
                ['icon' => 'history',    'title' => 'Audit Trail & Koreksi Berizin',
                 'body'  => 'Setiap koreksi manual dicatat dengan alasan terdokumentasi dan identitas operator untuk integritas audit.',
                 'meta'  => 'Activity log · alasan wajib'],
            ];
        @endphp

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-md">
            @foreach($features as $f)
                <article class="card flex flex-col justify-between">
                    <div>
                        <span class="flex items-center justify-center w-7 h-7 rounded-md bg-primary-soft text-primary mb-3">
                            <i data-lucide="{{ $f['icon'] }}" class="w-4 h-4"></i>
                        </span>
                        <h3 class="text-[13.5px] font-semibold text-ink m-0 mb-1.5">{{ $f['title'] }}</h3>
                        <p class="text-[12px] text-ink-muted leading-relaxed m-0">{{ $f['body'] }}</p>
                    </div>
                    <p class="mt-4 pt-3 border-t border-hairline text-[10.5px] font-mono text-ink-faint m-0">
                        {{ $f['meta'] }}
                    </p>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ─────────────────────────────── Spesifikasi ─────────────────────────── --}}
<section id="spesifikasi" class="border-b border-hairline bg-canvas-soft">
    <div class="max-w-6xl mx-auto px-md md:px-lg py-14 md:py-20">
        <div class="max-w-[60ch] mb-10">
            <p class="text-[11px] font-mono uppercase tracking-[0.14em] text-primary font-semibold mb-2">Spesifikasi Teknis</p>
            <h2 class="text-[26px] sm:text-[32px] font-bold tracking-[-0.025em] text-ink m-0">
                Angka yang bisa diverifikasi.
            </h2>
        </div>

        <div class="bg-canvas border border-hairline rounded-lg overflow-hidden">
            <table class="data-table">
                <tbody>
                    <tr>
                        <td class="w-[38%] font-medium text-ink">Latensi inferensi</td>
                        <td class="font-mono">&lt; 800 ms end-to-end (CPU quad-core)</td>
                    </tr>
                    <tr>
                        <td class="font-medium text-ink">Dimensi embedding</td>
                        <td class="font-mono">512 float32 = 2048 byte</td>
                    </tr>
                    <tr>
                        <td class="font-medium text-ink">Ambang kemiripan</td>
                        <td class="font-mono">cosine ≥ 0.75 (dapat dikonfigurasi)</td>
                    </tr>
                    <tr>
                        <td class="font-medium text-ink">Enkripsi template</td>
                        <td class="font-mono">AES-256-GCM · nonce 12 byte · AAD student+model</td>
                    </tr>
                    <tr>
                        <td class="font-medium text-ink">Ukuran batch API</td>
                        <td class="font-mono">maks. 200 record per panggilan</td>
                    </tr>
                    <tr>
                        <td class="font-medium text-ink">Idempotensi</td>
                        <td class="font-mono">UUID v4 sebagai primary key · insertOrIgnore</td>
                    </tr>
                    <tr>
                        <td class="font-medium text-ink">Format waktu</td>
                        <td class="font-mono">RFC-3339 · disimpan UTC</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</section>

{{-- ─────────────────────────────── Alur ────────────────────────────────── --}}
<section id="alur" class="border-b border-hairline">
    <div class="max-w-6xl mx-auto px-md md:px-lg py-14 md:py-20">
        <div class="max-w-[60ch] mb-10">
            <p class="text-[11px] font-mono uppercase tracking-[0.14em] text-primary font-semibold mb-2">Alur Kerja</p>
            <h2 class="text-[26px] sm:text-[32px] font-bold tracking-[-0.025em] text-ink m-0">
                Dari pendaftaran sampai laporan.
            </h2>
        </div>

        @php
            $steps = [
                ['n' => '01', 'title' => 'Daftarkan anggota', 'body' => 'Tambah manual, impor Excel, atau sinkron dari sistem akademik.'],
                ['n' => '02', 'title' => 'Enroll wajah', 'body' => 'Ambil foto lewat kamera web atau unggah berkas. Embedding dihitung di server lalu dienkripsi.'],
                ['n' => '03', 'title' => 'Pairing terminal', 'body' => 'Terminal gate meminta pairing; administrator menyetujui dan menetapkan lokasi.'],
                ['n' => '04', 'title' => 'Presensi harian', 'body' => 'Gate mengenali wajah secara lokal dan menulis ke ring buffer.'],
                ['n' => '05', 'title' => 'Sinkronisasi', 'body' => 'Batch dikirim idempoten ke cloud; duplikat diabaikan, kegagalan diulang.'],
                ['n' => '06', 'title' => 'Rekap & ekspor', 'body' => 'DTR dihitung otomatis dan dapat diunduh sebagai PDF, XLSX, atau CSV.'],
            ];
        @endphp

        <ol class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-md list-none m-0 p-0">
            @foreach($steps as $s)
                <li class="card">
                    <span class="text-[11px] font-mono font-semibold text-primary">{{ $s['n'] }}</span>
                    <h3 class="text-[13px] font-semibold text-ink m-0 mt-1 mb-1">{{ $s['title'] }}</h3>
                    <p class="text-[12px] text-ink-muted leading-relaxed m-0">{{ $s['body'] }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>

{{-- ─────────────────────────────── CTA ─────────────────────────────────── --}}
<section class="bg-canvas-soft border-b border-hairline">
    <div class="max-w-6xl mx-auto px-md md:px-lg py-12 flex flex-wrap items-center justify-between gap-md">
        <div>
            <h2 class="text-[20px] sm:text-[24px] font-bold tracking-tight text-ink m-0">Siap mencoba di gerbang Anda?</h2>
            <p class="text-[12.5px] text-ink-muted m-0 mt-1">Masuk ke konsol untuk mengelola anggota, terminal, dan laporan.</p>
        </div>
        @auth
            <a href="{{ route('dashboard') }}" class="btn btn-primary btn-lg">
                <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                <span>Buka Dashboard</span>
            </a>
        @else
            <a href="{{ route('login') }}" class="btn btn-primary btn-lg">
                <i data-lucide="log-in" class="w-4 h-4"></i>
                <span>Masuk Sekarang</span>
            </a>
        @endauth
    </div>
</section>

{{-- ─────────────────────────────── Footer ──────────────────────────────── --}}
<footer class="bg-canvas">
    <div class="max-w-6xl mx-auto px-md md:px-lg py-8 flex flex-wrap items-center justify-between gap-sm text-[11.5px] text-ink-muted">
        <div class="flex items-center gap-2">
            <img src="{{ asset('images/logo.png') }}" class="w-5 h-5 object-contain" alt="">
            <span class="font-semibold text-ink">Smart Absensi</span>
            <span class="text-ink-faint">— Edge AI Vision Attendance</span>
        </div>
        <nav class="flex items-center gap-md" aria-label="Navigasi footer">
            <a href="#arsitektur" class="hover:text-ink transition-colors">Arsitektur</a>
            <a href="#fitur" class="hover:text-ink transition-colors">Fitur</a>
            <a href="{{ route('login') }}" class="hover:text-ink transition-colors">Masuk</a>
            <span class="font-mono text-[10.5px] text-ink-faint">v2.4.0</span>
        </nav>
    </div>
</footer>

</body>
</html>
