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

    {{-- Header / Navigation --}}
    <header class="sticky top-0 z-40 bg-canvas/90 backdrop-blur-md border-b border-hairline">
        <div class="max-w-6xl mx-auto px-md md:px-lg h-14 flex items-center justify-between">
            <a href="{{ route('landing') }}" class="flex items-center gap-xs focus:outline-none">
                <img src="{{ asset('images/logo.png') }}" class="w-7 h-7 rounded-md object-contain shrink-0" alt="Logo">
                <span class="text-[15px] font-bold tracking-tight text-ink">Smart Absensi</span>
            </a>

            <nav class="hidden md:flex items-center gap-lg text-[13px] text-ink-secondary">
                <a href="#arsitektur" class="hover:text-primary transition-colors">Arsitektur</a>
                <a href="#edge-engine" class="hover:text-primary transition-colors">Edge Engine</a>
                <a href="#fitur" class="hover:text-primary transition-colors">Fitur</a>
                <a href="#spesifikasi" class="hover:text-primary transition-colors">Spesifikasi</a>
            </nav>

            <div class="flex items-center gap-xs">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn btn-primary btn-sm flex items-center gap-1.5">
                        <i data-lucide="video" class="w-3.5 h-3.5"></i>
                        <span>Live Monitor</span>
                    </a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-secondary btn-sm flex items-center gap-1.5">
                        <i data-lucide="log-in" class="w-3.5 h-3.5"></i>
                        <span>Masuk</span>
                    </a>
                @endauth
            </div>
        </div>
    </header>

    {{-- Hero Section --}}
    <section class="border-b border-hairline py-16 md:py-24 bg-gradient-to-b from-canvas to-canvas-soft/60">
        <div class="max-w-4xl mx-auto px-md md:px-lg text-center">
            
            <h1 class="text-3xl sm:text-4xl md:text-5xl font-extrabold tracking-tight text-ink leading-[1.15] mb-6">
                Presensi Wajah Berkecepatan Tinggi.<br class="hidden sm:inline">
                <span class="text-ink-muted font-bold">Tangguh di Edge, Terpusat di Cloud.</span>
            </h1>

            <p class="text-base sm:text-lg text-ink-secondary max-w-2xl mx-auto mb-8 leading-relaxed">
                Platform absensi cerdas berbasis Vision AI yang memproses pengenalan wajah langsung di gate secara offline-first. Memvalidasi identitas dalam milidetik dan menyinkronkan data secara aman ke server pusat.
            </p>

            <div class="flex flex-wrap items-center justify-center gap-sm mb-12">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn btn-primary px-5 py-2.5 text-sm flex items-center gap-2">
                        <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                        <span>Buka Live Monitor & Dashboard</span>
                    </a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-primary px-5 py-2.5 text-sm flex items-center gap-2">
                        <i data-lucide="log-in" class="w-4 h-4"></i>
                        <span>Masuk ke Konsol Manajemen</span>
                    </a>
                @endauth
                <a href="#arsitektur" class="btn btn-secondary px-5 py-2.5 text-sm flex items-center gap-2">
                    <i data-lucide="cpu" class="w-4 h-4"></i>
                    <span>Eksplorasi Arsitektur</span>
                </a>
            </div>

            {{-- High Density Telemetry Bar --}}
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 max-w-3xl mx-auto text-left">
                <div class="card p-3 bg-surface border border-hairline">
                    <div class="text-[11px] font-mono text-ink-faint uppercase">Inferensi Edge</div>
                    <div class="text-lg font-bold font-mono text-ink mt-0.5">&lt; 800 ms</div>
                    <div class="text-[11px] text-ink-muted">ONNX MobileFaceNet</div>
                </div>
                <div class="card p-3 bg-surface border border-hairline">
                    <div class="text-[11px] font-mono text-ink-faint uppercase">Ketahanan Jaringan</div>
                    <div class="text-lg font-bold font-mono text-ink mt-0.5">100% Offline</div>
                    <div class="text-[11px] text-ink-muted">Antrean SQLite Lokal</div>
                </div>
                <div class="card p-3 bg-surface border border-hairline">
                    <div class="text-[11px] font-mono text-ink-faint uppercase">Protokol Sinkronisasi</div>
                    <div class="text-lg font-bold font-mono text-ink mt-0.5">Idempoten</div>
                    <div class="text-[11px] text-ink-muted">UUID v4 & RFC-3339</div>
                </div>
                <div class="card p-3 bg-surface border border-hairline">
                    <div class="text-[11px] font-mono text-ink-faint uppercase">Rekapitulasi DTR</div>
                    <div class="text-lg font-bold font-mono text-ink mt-0.5">Otomatis</div>
                    <div class="text-[11px] text-ink-muted">Agregasi Masuk & Pulang</div>
                </div>
            </div>

        </div>
    </section>

    {{-- Interactive Telemetry & Architecture Showcase --}}
    <section id="arsitektur" class="py-16 md:py-20 border-b border-hairline bg-canvas" x-data="{ tab: 'engine' }">
        <div class="max-w-6xl mx-auto px-md md:px-lg">
            
            <div class="flex flex-col md:flex-row md:items-end justify-between mb-8 gap-4">
                <div>
                    <span class="text-[11px] font-mono uppercase tracking-wider text-primary font-semibold">ARSITEKTUR DUA LAPIS</span>
                    <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-ink mt-1">
                        Edge Terminal Rust & Laravel Cloud Ledger
                    </h2>
                </div>
                {{-- Tabs --}}
                <div class="inline-flex rounded-md border border-hairline bg-canvas-soft p-1 text-[12px] font-medium">
                    <button type="button" @click="tab = 'engine'" :class="tab === 'engine' ? 'bg-surface text-ink shadow-sm' : 'text-ink-muted hover:text-ink'" class="px-3 py-1.5 rounded transition-all">
                        Log Runtime Edge (Rust)
                    </button>
                    <button type="button" @click="tab = 'api'" :class="tab === 'api' ? 'bg-surface text-ink shadow-sm' : 'text-ink-muted hover:text-ink'" class="px-3 py-1.5 rounded transition-all">
                        Kontrak API Batch Sync
                    </button>
                    <button type="button" @click="tab = 'pipeline'" :class="tab === 'pipeline' ? 'bg-surface text-ink shadow-sm' : 'text-ink-muted hover:text-ink'" class="px-3 py-1.5 rounded transition-all">
                        Alur Inferensi Biometrik
                    </button>
                </div>
            </div>

            {{-- Terminal Window --}}
            <div class="rounded-lg bg-[#0e1117] text-white border border-[#23272f] overflow-hidden shadow-lg">
                <div class="flex items-center justify-between px-4 py-2.5 border-b border-[#23272f] bg-[#161b22]">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full bg-[#ff5f56]"></span>
                        <span class="w-3 h-3 rounded-full bg-[#ffbd2e]"></span>
                        <span class="w-3 h-3 rounded-full bg-[#27c93f]"></span>
                        <span class="text-xs font-mono text-gray-400 ml-2" x-text="tab === 'engine' ? 'smart-absensi-sync (native edge runtime)' : (tab === 'api' ? 'POST /api/v1/attendance/batch' : 'pipeline::onnx_runtime')"></span>
                    </div>
                    <div class="text-[11px] font-mono text-gray-500 uppercase tracking-wider">
                        v2.4.0-rs
                    </div>
                </div>

                {{-- Tab 1: Edge Runtime Log --}}
                <div x-show="tab === 'engine'" class="p-5 font-mono text-[12.5px] leading-relaxed text-gray-300 overflow-x-auto space-y-1">
                    <p class="text-gray-500">// Terminal gate aktif — memantau stream kamera 30 FPS</p>
                    <p><span class="text-emerald-400">[INFO]</span> <span class="text-blue-400">engine::init:</span> Loading ONNX MobileFaceNet weights (FP16 optimized)</p>
                    <p><span class="text-emerald-400">[INFO]</span> <span class="text-blue-400">camera::stream:</span> /dev/video0 initialized at 1280x720, MJPEG sink active</p>
                    <p><span class="text-emerald-400">[INFO]</span> <span class="text-blue-400">db::sqlite:</span> Local ring buffer ready (0 pending sync records)</p>
                    <p class="text-yellow-400/90"><span class="text-yellow-400">[DETECT]</span> Face detected in 18.4ms | bbox: [240, 112, 380, 420]</p>
                    <p><span class="text-emerald-400">[MATCH]</span> Embedding matched: <span class="text-white font-bold">Ahmad Rizky (NISN: 00829102)</span> | Score: <span class="text-emerald-300 font-bold">0.978</span> | Dir: <span class="text-cyan-300">IN</span></p>
                    <p><span class="text-emerald-400">[PERSIST]</span> Written to local buffer (UUID: <span class="text-purple-300">8f21e5b1-7a2e-4b71-b0e2-632e8f810aa4</span>)</p>
                    <p><span class="text-blue-400">[SYNC]</span> Dispatching batch to cloud: 1 records</p>
                    <p><span class="text-emerald-400">[HTTP]</span> POST https://absensi.sekolah.id/api/v1/attendance/batch -> <span class="text-emerald-300 font-bold">200 OK</span> (took 82ms)</p>
                    <p class="text-gray-500">[COOLDOWN] Duplicate suppression active for Ahmad Rizky (300s remaining)</p>
                </div>

                {{-- Tab 2: API Contract --}}
                <div x-show="tab === 'api'" class="p-5 font-mono text-[12.5px] leading-relaxed text-gray-300 overflow-x-auto">
                    <pre class="text-emerald-300">{
  <span class="text-blue-300">"device_code"</span>: <span class="text-amber-200">"GATE-01-TERMINAL"</span>,
  <span class="text-blue-300">"records"</span>: [
    {
      <span class="text-blue-300">"id"</span>: <span class="text-amber-200">"8f21e5b1-7a2e-4b71-b0e2-632e8f810aa4"</span>,
      <span class="text-blue-300">"student_id"</span>: <span class="text-purple-300">142</span>,
      <span class="text-blue-300">"captured_at"</span>: <span class="text-amber-200">"2026-10-16T07:05:12Z"</span>,
      <span class="text-blue-300">"direction"</span>: <span class="text-amber-200">"in"</span>,
      <span class="text-blue-300">"score"</span>: <span class="text-purple-300">0.978</span>
    }
  ]
}</pre>
                    <div class="mt-4 pt-3 border-t border-[#23272f] text-gray-400 text-[11px]">
                        Isolasi transaksi per-record dengan semantik <code class="text-yellow-300">insertOrIgnore</code> untuk menjamin idempotensi total.
                    </div>
                </div>

                {{-- Tab 3: Pipeline Flow --}}
                <div x-show="tab === 'pipeline'" class="p-5 font-mono text-[12.5px] leading-relaxed text-gray-300 space-y-2">
                    <div class="flex items-center gap-3">
                        <span class="text-xs px-2 py-0.5 rounded bg-blue-900/60 text-blue-300 border border-blue-700">Tahap 1</span>
                        <span>Akuisisi Frame Video (Kamera USB / RTSP Stream)</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs px-2 py-0.5 rounded bg-blue-900/60 text-blue-300 border border-blue-700">Tahap 2</span>
                        <span>Deteksi Wajah Ultralight & Ekstraksi Landmark 5-Titik</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs px-2 py-0.5 rounded bg-blue-900/60 text-blue-300 border border-blue-700">Tahap 3</span>
                        <span>Normalisasi Affine & Transformasi 112x112 Pixel</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs px-2 py-0.5 rounded bg-blue-900/60 text-blue-300 border border-blue-700">Tahap 4</span>
                        <span>Komputasi Vektor Embedding 512-Dimensi via ONNX Runtime</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs px-2 py-0.5 rounded bg-emerald-900/60 text-emerald-300 border border-emerald-700">Tahap 5</span>
                        <span>Cosine Distance Matching vs Database Template Anggota</span>
                    </div>
                </div>
            </div>

        </div>
    </section>

    {{-- Core Capabilities Bento Grid --}}
    <section id="fitur" class="py-16 md:py-24 border-b border-hairline bg-canvas-soft/40">
        <div class="max-w-6xl mx-auto px-md md:px-lg">
            
            <div class="text-center max-w-2xl mx-auto mb-12">
                <span class="text-[11px] font-mono uppercase tracking-wider text-primary font-semibold">KEUNGGULAN UTAMA</span>
                <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-ink mt-1">
                    Dirancang untuk Keandalan Operasional Nyata
                </h2>
                <p class="text-sm text-ink-secondary mt-2">
                    Bukan sekadar aplikasi web biasa. Smart Absensi menggabungkan performa biner native dengan tata kelola cloud enterprise.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                
                {{-- Feature 1 --}}
                <div class="card p-5 bg-surface border border-hairline flex flex-col justify-between">
                    <div>
                        <div class="w-8 h-8 rounded-md bg-primary/10 text-primary flex items-center justify-center mb-3">
                            <i data-lucide="zap" class="w-4 h-4"></i>
                        </div>
                        <h3 class="text-base font-semibold text-ink mb-1.5">Inferensi Edge Sub-Detik</h3>
                        <p class="text-[12.5px] text-ink-muted leading-relaxed">
                            Pencocokan biometrik berlangsung instan di mesin gate tanpa antrean jaringan. Anggota cukup melintas di depan kamera untuk tercatat.
                        </p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-hairline/60 text-[11px] font-mono text-ink-faint">
                        MobileFaceNet • ONNX Runtime
                    </div>
                </div>

                {{-- Feature 2 --}}
                <div class="card p-5 bg-surface border border-hairline flex flex-col justify-between">
                    <div>
                        <div class="w-8 h-8 rounded-md bg-accent-green/10 text-accent-green flex items-center justify-center mb-3">
                            <i data-lucide="shield-check" class="w-4 h-4"></i>
                        </div>
                        <h3 class="text-base font-semibold text-ink mb-1.5">Penyangga Offline & Anti-Macet</h3>
                        <p class="text-[12.5px] text-ink-muted leading-relaxed">
                            Ketika jaringan internet sekolah atau kantor padam, gerbang tetap terbuka dan presensi tetap terekam dalam database lokal terenkripsi.
                        </p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-hairline/60 text-[11px] font-mono text-ink-faint">
                        SQLite Ring Buffer • Auto-Drain
                    </div>
                </div>

                {{-- Feature 3 --}}
                <div class="card p-5 bg-surface border border-hairline flex flex-col justify-between">
                    <div>
                        <div class="w-8 h-8 rounded-md bg-accent-purple/20 text-secondary flex items-center justify-center mb-3">
                            <i data-lucide="network" class="w-4 h-4"></i>
                        </div>
                        <h3 class="text-base font-semibold text-ink mb-1.5">Pairing Perangkat Kriptografis</h3>
                        <p class="text-[12.5px] text-ink-muted leading-relaxed">
                            Setiap terminal gate diverifikasi menggunakan token pairing bertanda tangan. Administrator dapat menyetujui atau mencabut akses perangkat dalam 1 klik.
                        </p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-hairline/60 text-[11px] font-mono text-ink-faint">
                        Sanctum Token • Handshake Otentik
                    </div>
                </div>

                {{-- Feature 4 --}}
                <div class="card p-5 bg-surface border border-hairline flex flex-col justify-between">
                    <div>
                        <div class="w-8 h-8 rounded-md bg-primary/10 text-primary flex items-center justify-center mb-3">
                            <i data-lucide="calendar" class="w-4 h-4"></i>
                        </div>
                        <h3 class="text-base font-semibold text-ink mb-1.5">Kalkulasi DTR Harian Otomatis</h3>
                        <p class="text-[12.5px] text-ink-muted leading-relaxed">
                            Sistem secara cerdas menghitung jam kedatangan (MIN) dan kepulangan (MAX) per siswa per hari meskipun anggota melintas berkali-kali.
                        </p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-hairline/60 text-[11px] font-mono text-ink-faint">
                        Daily Time Record (DTR) Standard
                    </div>
                </div>

                {{-- Feature 5 --}}
                <div class="card p-5 bg-surface border border-hairline flex flex-col justify-between">
                    <div>
                        <div class="w-8 h-8 rounded-md bg-accent-teal/10 text-accent-teal flex items-center justify-center mb-3">
                            <i data-lucide="file-spreadsheet" class="w-4 h-4"></i>
                        </div>
                        <h3 class="text-base font-semibold text-ink mb-1.5">Ekspor Laporan Multi-Format</h3>
                        <p class="text-[12.5px] text-ink-muted leading-relaxed">
                            Unduh rekapitulasi kehadiran dalam format PDF siap cetak, file Excel/XLSX berbasis template resmi instansi, maupun CSV untuk pemrosesan lanjut.
                        </p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-hairline/60 text-[11px] font-mono text-ink-faint">
                        PDF • XLSX • CSV
                    </div>
                </div>

                {{-- Feature 6 --}}
                <div class="card p-5 bg-surface border border-hairline flex flex-col justify-between">
                    <div>
                        <div class="w-8 h-8 rounded-md bg-accent-orange/10 text-accent-orange flex items-center justify-center mb-3">
                            <i data-lucide="history" class="w-4 h-4"></i>
                        </div>
                        <h3 class="text-base font-semibold text-ink mb-1.5">Audit Trail & Koreksi Berizin</h3>
                        <p class="text-[12.5px] text-ink-muted leading-relaxed">
                            Seluruh koreksi data absensi manual dicatat dengan alasan terdokumentasi dan identitas operator untuk menjamin integritas data audit.
                        </p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-hairline/60 text-[11px] font-mono text-ink-faint">
                        Activity Log • Audit Compliance
                    </div>
                </div>

            </div>

        </div>
    </section>

    {{-- Technical Specifications Section --}}
    <section id="spesifikasi" class="py-16 md:py-20 border-b border-hairline bg-canvas">
        <div class="max-w-4xl mx-auto px-md md:px-lg">
            
            <div class="mb-8">
                <span class="text-[11px] font-mono uppercase tracking-wider text-primary font-semibold">SPESIFIKASI SISTEM</span>
                <h2 class="text-2xl font-bold tracking-tight text-ink mt-1">
                    Spesifikasi Teknis & Standar Rekayasa
                </h2>
            </div>

            <div class="border border-hairline rounded-md overflow-hidden bg-surface">
                <table class="w-full text-left text-[12.5px]">
                    <tbody class="divide-y divide-hairline">
                        <tr class="hover:bg-canvas-soft/50 transition-colors">
                            <td class="p-3 font-medium text-ink w-1/3 bg-canvas-soft/30">Edge Runtime</td>
                            <td class="p-3 text-ink-secondary font-mono">Rust Native (clients/edge-engine-rs) / Python Fallback</td>
                        </tr>
                        <tr class="hover:bg-canvas-soft/50 transition-colors">
                            <td class="p-3 font-medium text-ink bg-canvas-soft/30">Cloud Management Platform</td>
                            <td class="p-3 text-ink-secondary font-mono">Laravel 12 (Modular Monolith + Pragmatic Layered Architecture)</td>
                        </tr>
                        <tr class="hover:bg-canvas-soft/50 transition-colors">
                            <td class="p-3 font-medium text-ink bg-canvas-soft/30">Arsitektur Biometrik</td>
                            <td class="p-3 text-ink-secondary font-mono">MobileFaceNet 512D Embeddings via ONNX Runtime Engine</td>
                        </tr>
                        <tr class="hover:bg-canvas-soft/50 transition-colors">
                            <td class="p-3 font-medium text-ink bg-canvas-soft/30">Protokol Kamera Stream</td>
                            <td class="p-3 text-ink-secondary font-mono">MJPEG HTTP Live Stream (Port 5000 / Web Video Feed)</td>
                        </tr>
                        <tr class="hover:bg-canvas-soft/50 transition-colors">
                            <td class="p-3 font-medium text-ink bg-canvas-soft/30">Sinkronisasi & Integritas</td>
                            <td class="p-3 text-ink-secondary font-mono">Idempotent Batch Protocol (RFC-3339 UTC, UUID v4, Cooldown 300s)</td>
                        </tr>
                        <tr class="hover:bg-canvas-soft/50 transition-colors">
                            <td class="p-3 font-medium text-ink bg-canvas-soft/30">Penyimpanan Edge & Cloud</td>
                            <td class="p-3 text-ink-secondary font-mono">SQLite (Edge Queue) & MySQL / PostgreSQL (Cloud Master)</td>
                        </tr>
                    </tbody>
                </table>
            </div>

        </div>
    </section>

    {{-- Call to Action Strip --}}
    <section class="py-16 bg-gradient-to-t from-canvas-soft to-canvas border-b border-hairline">
        <div class="max-w-3xl mx-auto px-md md:px-lg text-center">
            <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-ink mb-3">
                Siap Mengimplementasikan Smart Absensi?
            </h2>
            <p class="text-sm text-ink-secondary mb-6 max-w-xl mx-auto">
                Masuk ke konsol manajemen untuk mengonfigurasi perangkat gate, mendaftarkan anggota, atau memantau kehadiran langsung melalui live video stream.
            </p>
            <div class="flex items-center justify-center gap-xs">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn btn-primary px-5 py-2">
                        Buka Live Kamera & Monitor
                    </a>
                @else
                    <a href="{{ route('login') }}" class="btn btn-primary px-5 py-2">
                        Masuk ke Konsol Manajemen
                    </a>
                @endauth
            </div>
        </div>
    </section>

    {{-- Minimalist Footer --}}
    <footer class="py-8 bg-canvas text-caption text-ink-muted">
        <div class="max-w-6xl mx-auto px-md md:px-lg flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <img src="{{ asset('images/logo.png') }}" class="w-5 h-5 object-contain" alt="Logo">
                <span class="font-bold text-ink">Smart Absensi</span>
                <span>— Edge AI Vision Attendance System</span>
            </div>
            <div class="flex items-center gap-md">
                <a href="#arsitektur" class="hover:text-ink transition-colors">Arsitektur</a>
                <a href="#fitur" class="hover:text-ink transition-colors">Fitur</a>
                <a href="{{ route('login') }}" class="hover:text-ink transition-colors">Login</a>
                <span class="text-hairline">|</span>
                <span class="font-mono text-[11px] text-ink-faint">v2.4.0</span>
            </div>
        </div>
    </footer>

</body>
</html>
