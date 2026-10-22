<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Beranda') — Smart Absensi</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            document.documentElement.classList.add('sidebar-collapsed');
        }
    </script>
</head>
<body x-data="layoutData" class="bg-canvas-soft text-ink font-sans antialiased min-h-screen">

{{-- Mobile drawer backdrop --}}
<div x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false"
     class="fixed inset-0 bg-ink/40 z-40 md:hidden" x-cloak></div>

{{-- ─────────────────────────────── Sidebar ─────────────────────────────── --}}
<aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
       class="fixed inset-y-0 left-0 z-50 w-[240px] bg-canvas border-r border-hairline flex flex-col
              transition-[transform,width] duration-200 ease-out md:translate-x-0
              [.sidebar-collapsed_&]:md:w-[64px]">

    <div class="h-[56px] shrink-0 flex items-center border-b border-hairline px-sm overflow-hidden">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-xs min-w-0">
            <img src="{{ asset('images/logo.png') }}" alt="Smart Absensi"
                 class="w-7 h-7 rounded-md object-contain shrink-0">
            <span class="text-[15px] font-bold tracking-tight text-ink whitespace-nowrap
                         transition-opacity duration-200
                         [.sidebar-collapsed_&]:md:opacity-0 [.sidebar-collapsed_&]:md:pointer-events-none">
                Smart Absensi
            </span>
        </a>
    </div>

    <nav class="flex-1 overflow-y-auto overflow-x-hidden custom-scroll py-xs px-xs" aria-label="Navigasi utama">
        <ul class="flex flex-col gap-[2px] list-none m-0 p-0">
            <li>
                <a href="{{ route('dashboard') }}" title="Live Kamera"
                   class="nav-item {{ request()->routeIs('dashboard') || request()->routeIs('live-camera.*') ? 'is-active' : '' }}">
                    <i data-lucide="video" class="w-4 h-4"></i>
                    <span class="transition-opacity duration-200 [.sidebar-collapsed_&]:md:opacity-0">Live Kamera</span>
                </a>
            </li>

            <li class="nav-section" aria-hidden="true">
                <span class="transition-opacity duration-200 [.sidebar-collapsed_&]:md:opacity-0">Aplikasi</span>
            </li>

            <li>
                <a href="{{ route('students.index') }}" title="Anggota"
                   class="nav-item {{ request()->routeIs('students.*') ? 'is-active' : '' }}">
                    <i data-lucide="users" class="w-4 h-4"></i>
                    <span class="transition-opacity duration-200 [.sidebar-collapsed_&]:md:opacity-0">Anggota</span>
                </a>
            </li>
            <li>
                <a href="{{ route('reports.index') }}" title="Laporan"
                   class="nav-item {{ request()->routeIs('reports.*') ? 'is-active' : '' }}">
                    <i data-lucide="file-text" class="w-4 h-4"></i>
                    <span class="transition-opacity duration-200 [.sidebar-collapsed_&]:md:opacity-0">Laporan</span>
                </a>
            </li>

            <li class="nav-section" aria-hidden="true">
                <span class="transition-opacity duration-200 [.sidebar-collapsed_&]:md:opacity-0">Manajemen</span>
            </li>

            <li>
                <a href="{{ route('schools.index') }}" title="Organisasi"
                   class="nav-item {{ request()->routeIs('schools.*') ? 'is-active' : '' }}">
                    <i data-lucide="building" class="w-4 h-4"></i>
                    <span class="transition-opacity duration-200 [.sidebar-collapsed_&]:md:opacity-0">Organisasi</span>
                </a>
            </li>
            <li>
                <a href="{{ route('devices.index') }}" title="Perangkat"
                   class="nav-item {{ request()->routeIs('devices.*') ? 'is-active' : '' }}">
                    <i data-lucide="cpu" class="w-4 h-4"></i>
                    <span class="transition-opacity duration-200 [.sidebar-collapsed_&]:md:opacity-0">Perangkat</span>
                </a>
            </li>

            @canany(['user.manage', 'audit.view', 'quarantine.view', 'consent.manage'])
                <li class="nav-section" aria-hidden="true">
                    <span class="transition-opacity duration-200 [.sidebar-collapsed_&]:md:opacity-0">Konsol Admin</span>
                </li>

                @can('user.manage')
                    <li>
                        <a href="{{ route('admin.users.index') }}" title="Pengguna"
                           class="nav-item {{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}">
                            <i data-lucide="user-cog" class="w-4 h-4"></i>
                            <span class="transition-opacity duration-200 [.sidebar-collapsed_&]:md:opacity-0">Pengguna</span>
                        </a>
                    </li>
                @endcan

                @can('audit.view')
                    <li>
                        <a href="{{ route('admin.audit.index') }}" title="Log Audit"
                           class="nav-item {{ request()->routeIs('admin.audit.*') ? 'is-active' : '' }}">
                            <i data-lucide="shield-check" class="w-4 h-4"></i>
                            <span class="transition-opacity duration-200 [.sidebar-collapsed_&]:md:opacity-0">Log Audit</span>
                        </a>
                    </li>
                @endcan

                @can('quarantine.view')
                    <li>
                        <a href="{{ route('admin.quarantine.index') }}" title="Karantina Biometrik"
                           class="nav-item {{ request()->routeIs('admin.quarantine.*') ? 'is-active' : '' }}">
                            <i data-lucide="alert-octagon" class="w-4 h-4"></i>
                            <span class="transition-opacity duration-200 [.sidebar-collapsed_&]:md:opacity-0">Karantina</span>
                        </a>
                    </li>
                @endcan

                @can('consent.manage')
                    <li>
                        <a href="{{ route('admin.consents.index') }}" title="Persetujuan UU PDP"
                           class="nav-item {{ request()->routeIs('admin.consents.*') ? 'is-active' : '' }}">
                            <i data-lucide="file-check" class="w-4 h-4"></i>
                            <span class="transition-opacity duration-200 [.sidebar-collapsed_&]:md:opacity-0">Persetujuan</span>
                        </a>
                    </li>
                @endcan
            @endcanany
        </ul>
    </nav>

    <div class="shrink-0 border-t border-hairline p-xs">
        <a href="{{ route('landing') }}" title="Halaman Depan" class="nav-item text-ink-muted">
            <i data-lucide="globe" class="w-4 h-4"></i>
            <span class="transition-opacity duration-200 [.sidebar-collapsed_&]:md:opacity-0">Halaman Depan</span>
        </a>
    </div>
</aside>

{{-- ─────────────────────────────── Main shell ─────────────────────────── --}}
<main class="min-h-screen flex flex-col transition-[margin] duration-200 ease-out
             ml-0 md:ml-[240px] [.sidebar-collapsed_&]:md:ml-[64px]">

    <header class="h-[56px] shrink-0 px-md md:px-lg bg-canvas border-b border-hairline
                   flex items-center justify-between sticky top-0 z-30">
        <div class="flex items-center gap-xs min-w-0">
            <button type="button" @click="toggleSidebar()" aria-label="Buka atau tutup sidebar"
                    class="btn-icon w-8 h-8 text-ink-muted hover:text-ink">
                <i data-lucide="menu" class="w-4 h-4"></i>
            </button>
            <span class="hidden sm:inline text-[13px] font-semibold text-ink-muted truncate">@yield('title', 'Beranda')</span>
        </div>

        <div class="flex items-center gap-sm shrink-0">
            <div class="flex items-center gap-xs">
                <div class="w-7 h-7 rounded-full bg-primary-soft border border-primary/20 shrink-0
                            flex items-center justify-center text-primary font-bold text-[12px]">
                    {{ mb_strtoupper(mb_substr(Auth::user()->name ?? 'A', 0, 1)) }}
                </div>
                <div class="hidden md:block text-left">
                    <p class="text-[12.5px] font-semibold text-ink leading-tight truncate max-w-[160px]">
                        {{ Auth::user()->name ?? 'Admin' }}
                    </p>
                    <p class="text-[11px] text-ink-muted leading-tight capitalize">
                        {{ Auth::user()->roles->first()?->name ?? 'Administrator' }}
                    </p>
                </div>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="m-0">
                @csrf
                <button type="submit" class="btn-icon hover:text-danger" aria-label="Keluar" title="Keluar">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                </button>
            </form>
        </div>
    </header>

    @php($isFullBleed = request()->routeIs('dashboard') || request()->routeIs('live-camera.*'))

    <div class="flex-1 min-h-0 {{ $isFullBleed ? 'flex flex-col bg-canvas-soft overflow-hidden' : 'p-md md:p-lg' }}">

        @unless($isFullBleed)
            <div class="flex flex-wrap items-start justify-between gap-sm mb-md">
                <div class="min-w-0">
                    <h1 class="text-[20px] font-bold text-ink tracking-tight m-0">@yield('title')</h1>
                    <nav aria-label="Breadcrumb" class="mt-0.5">
                        <ol class="flex items-center gap-1.5 text-[11.5px] text-ink-muted list-none m-0 p-0">
                            <li><a href="{{ route('dashboard') }}" class="hover:text-primary transition-colors">Beranda</a></li>
                            <li aria-hidden="true" class="text-hairline">/</li>
                            <li class="text-ink-secondary font-medium" aria-current="page">@yield('title')</li>
                        </ol>
                    </nav>
                </div>
                @hasSection('header-actions')
                    <div class="flex items-center gap-xs shrink-0">@yield('header-actions')</div>
                @endif
            </div>

            @if(session('success') || session('error') || $errors->any())
                <div class="flex flex-col gap-xs mb-md">
                    @if(session('success'))
                        <div class="alert alert-success" role="status">
                            <i data-lucide="check-circle" class="w-4 h-4 shrink-0 mt-px"></i>
                            <span>{{ session('success') }}</span>
                        </div>
                    @endif
                    @if(session('error'))
                        <div class="alert alert-error" role="alert">
                            <i data-lucide="alert-circle" class="w-4 h-4 shrink-0 mt-px"></i>
                            <span>{{ session('error') }}</span>
                        </div>
                    @endif
                    @if($errors->any())
                        <div class="alert alert-error" role="alert">
                            <i data-lucide="alert-circle" class="w-4 h-4 shrink-0 mt-px"></i>
                            <div>
                                <p class="font-semibold m-0">Terdapat kesalahan pada formulir:</p>
                                <ul class="list-disc list-inside m-0 mt-1 pl-1">
                                    @foreach($errors->all() as $err)
                                        <li>{{ $err }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        @endunless

        @yield('content')
    </div>
</main>

{{-- Global confirm dialog --}}
<dialog id="globalConfirmModal" x-ref="globalConfirmModal"
        @click="($event.target.id === 'globalConfirmModal' || $event.target === $refs.globalConfirmModal) && ($refs.globalConfirmModal?.close() || $el.close())"
        class="w-[min(400px,92vw)] m-auto">
    <div class="modal-head">
        <h2 class="modal-title">
            <i data-lucide="alert-triangle" class="w-4 h-4 text-warning"></i> Konfirmasi
        </h2>
        <button type="button" @click="$refs.globalConfirmModal?.close() || $el.closest('dialog')?.close()"
                onclick="document.getElementById('globalConfirmModal')?.close()" aria-label="Tutup" class="btn-icon">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <div class="modal-body">
        <p id="globalConfirmMessage" class="text-[13px] text-ink m-0 leading-relaxed" x-text="confirmMessage"></p>
    </div>
    <div class="modal-foot">
        <button type="button" @click="$refs.globalConfirmModal?.close() || $el.closest('dialog')?.close()"
                onclick="document.getElementById('globalConfirmModal')?.close()" class="btn btn-secondary">Batal</button>
        <button type="button" @click="executeConfirm()" onclick="window.executeGlobalConfirm && window.executeGlobalConfirm()" class="btn btn-primary">Ya, Lanjutkan</button>
    </div>
</dialog>

@stack('scripts')
</body>
</html>
