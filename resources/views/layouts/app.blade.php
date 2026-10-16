<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Smart Absensi') — Smart Absensi</title>
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

{{-- Mobile Drawer Backdrop --}}
<div x-show="sidebarOpen" @click="sidebarOpen = false" class="fixed inset-0 bg-black/40 z-40 md:hidden transition-opacity" x-cloak></div>

{{-- Sidebar --}}
<aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'" class="fixed inset-y-0 left-0 bg-canvas border-r border-hairline flex flex-col transition-all duration-300 ease-in-out z-50 shadow-sm md:translate-x-0 w-[240px] [.sidebar-collapsed_&]:md:w-[72px] overflow-hidden">
    <div class="h-[56px] flex items-center border-b border-hairline shrink-0 px-md transition-all duration-300 overflow-hidden">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-xs">
            <img src="{{ asset('images/logo.png') }}" class="w-7 h-7 rounded-md object-contain shrink-0 transition-all duration-300" alt="Logo">
            <span class="text-[15px] font-bold text-ink tracking-tight whitespace-nowrap transition-all duration-300 overflow-hidden md:max-w-[180px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Smart Absensi</span>
        </a>
    </div>

    <div class="flex-1 overflow-y-auto py-sm custom-scroll overflow-x-hidden">
        <ul class="flex flex-col gap-[2px] transition-all duration-300 px-xs [.sidebar-collapsed_&]:md:px-1">
            <li>
                <a href="{{ route('dashboard') }}" class="flex items-center gap-xs py-[7px] px-sm rounded-md transition-colors text-[13px] {{ request()->routeIs('dashboard') ? 'bg-primary/10 text-primary font-semibold' : 'text-ink-secondary hover:bg-canvas-soft hover:text-ink font-medium' }}">
                    <i data-lucide="video" class="w-4 h-4 shrink-0"></i>
                    <span class="whitespace-nowrap transition-all duration-300 overflow-hidden md:max-w-[180px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Live Kamera</span>
                </a>
            </li>

            <li class="mt-sm mb-xxs px-sm relative flex items-center h-[18px] transition-all duration-300 overflow-hidden">
                <span class="text-[10px] font-semibold text-ink-faint uppercase tracking-wider whitespace-nowrap transition-all duration-300 overflow-hidden md:max-w-[180px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Aplikasi</span>
                <span class="transition-all duration-300 absolute left-1/2 -translate-x-1/2 text-ink-faint hidden md:opacity-0 [.sidebar-collapsed_&]:md:opacity-100 [.sidebar-collapsed_&]:md:block">•••</span>
            </li>
            
            <li>
                <a href="{{ route('students.index') }}" class="flex items-center gap-xs py-[7px] px-sm rounded-md transition-colors text-[13px] {{ request()->routeIs('students.*') ? 'bg-primary/10 text-primary font-semibold' : 'text-ink-secondary hover:bg-canvas-soft hover:text-ink font-medium' }}">
                    <i data-lucide="users" class="w-4 h-4 shrink-0"></i>
                    <span class="whitespace-nowrap transition-all duration-300 overflow-hidden md:max-w-[180px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Anggota</span>
                </a>
            </li>
            <li>
                <a href="{{ route('reports.index') }}" class="flex items-center gap-xs py-[7px] px-sm rounded-md transition-colors text-[13px] {{ request()->routeIs('reports.*') ? 'bg-primary/10 text-primary font-semibold' : 'text-ink-secondary hover:bg-canvas-soft hover:text-ink font-medium' }}">
                    <i data-lucide="file-text" class="w-4 h-4 shrink-0"></i>
                    <span class="whitespace-nowrap transition-all duration-300 overflow-hidden md:max-w-[180px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Laporan</span>
                </a>
            </li>

            <li class="mt-sm mb-xxs px-sm relative flex items-center h-[18px] transition-all duration-300 overflow-hidden">
                <span class="text-[10px] font-semibold text-ink-faint uppercase tracking-wider whitespace-nowrap transition-all duration-300 overflow-hidden md:max-w-[180px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Manajemen</span>
                <span class="transition-all duration-300 absolute left-1/2 -translate-x-1/2 text-ink-faint hidden md:opacity-0 [.sidebar-collapsed_&]:md:opacity-100 [.sidebar-collapsed_&]:md:block">•••</span>
            </li>

            <li>
                <a href="{{ route('schools.index') }}" class="flex items-center gap-xs py-[7px] px-sm rounded-md transition-colors text-[13px] {{ request()->routeIs('schools.*') ? 'bg-primary/10 text-primary font-semibold' : 'text-ink-secondary hover:bg-canvas-soft hover:text-ink font-medium' }}">
                    <i data-lucide="building" class="w-4 h-4 shrink-0"></i>
                    <span class="whitespace-nowrap transition-all duration-300 overflow-hidden md:max-w-[180px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Organisasi</span>
                </a>
            </li>
            <li>
                <a href="{{ route('devices.index') }}" class="flex items-center gap-xs py-[7px] px-sm rounded-md transition-colors text-[13px] {{ request()->routeIs('devices.*') ? 'bg-primary/10 text-primary font-semibold' : 'text-ink-secondary hover:bg-canvas-soft hover:text-ink font-medium' }}">
                    <i data-lucide="cpu" class="w-4 h-4 shrink-0"></i>
                    <span class="whitespace-nowrap transition-all duration-300 overflow-hidden md:max-w-[180px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Perangkat</span>
                </a>
            </li>
        </ul>
    </div>
</aside>

{{-- Main Content Shell --}}
<main class="min-h-screen flex flex-col transition-all duration-300 ease-in-out relative ml-0 md:ml-[240px] [.sidebar-collapsed_&]:md:ml-[72px]">
    
    {{-- Navbar Header --}}
    <header class="h-[56px] px-lg bg-canvas border-b border-hairline flex items-center justify-between sticky top-0 z-40">
        <div class="flex items-center gap-xs">
            <button type="button" @click="toggleSidebar()" aria-label="Toggle Sidebar" class="btn-icon w-8 h-8 text-ink-muted hover:text-primary">
                <i data-lucide="menu" class="w-4 h-4"></i>
            </button>
        </div>
        
        <div class="flex items-center gap-sm">
            <div class="flex items-center gap-xs cursor-pointer pl-sm">
                <div class="w-7 h-7 rounded-full bg-primary/10 flex items-center justify-center border border-primary/20 shrink-0 text-primary font-bold text-[12px]">
                    {{ substr(Auth::user()->name ?? 'A', 0, 1) }}
                </div>
                <div class="hidden md:block text-left mr-xs">
                    <p class="text-[13px] font-semibold text-ink leading-tight">{{ Auth::user()->name ?? 'Admin User' }}</p>
                    <p class="text-[11px] text-ink-muted leading-tight capitalize">{{ Auth::user()->roles->first()?->name ?? 'Administrator' }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="m-0">
                    @csrf
                    <button type="submit" class="btn-icon hover:text-danger" aria-label="Logout">
                        <i data-lucide="log-out" class="w-4 h-4"></i>
                    </button>
                </form>
            </div>
        </div>
    </header>

    {{-- Main Body --}}
    <div class="flex-1 p-lg md:p-xl">
        {{-- Breadcrumb Area --}}
        <div class="flex flex-wrap items-center justify-between gap-sm mb-lg">
            <div>
                <h1 class="text-[20px] font-bold text-ink tracking-tight mb-0.5">@yield('title')</h1>
                @if(!request()->routeIs('dashboard'))
                    <nav aria-label="Breadcrumb">
                        <ul class="flex items-center gap-1.5 text-[12px] text-ink-muted">
                            <li>
                                <a href="{{ route('dashboard') }}" class="hover:text-primary transition-colors">Beranda</a>
                            </li>
                            <li><span class="text-hairline">/</span></li>
                            <li class="text-ink-secondary font-medium">@yield('title')</li>
                        </ul>
                    </nav>
                @endif
            </div>
            @hasSection('header-actions')
                <div class="flex items-center gap-xs">
                    @yield('header-actions')
                </div>
            @endif
        </div>

        {{-- Flash Messages & Validation Errors --}}
        @if(session('success') || session('error') || ($errors->any()))
            <div class="mb-lg">
                @if(session('success'))
                    <div class="alert alert-success flex items-center gap-xs" role="alert">
                        <i data-lucide="check-circle" class="w-4 h-4"></i> {{ session('success') }}
                    </div>
                @endif
                @if(session('error'))
                    <div class="alert alert-error flex items-center gap-xs" role="alert">
                        <i data-lucide="alert-circle" class="w-4 h-4"></i> {{ session('error') }}
                    </div>
                @endif
                @if($errors->any())
                    <div class="alert alert-error flex flex-col gap-xs" role="alert">
                        <div class="flex items-center gap-xs font-semibold">
                            <i data-lucide="alert-circle" class="w-4 h-4"></i> Terdapat kesalahan pada formulir:
                        </div>
                        <ul class="list-disc list-inside text-[13px] m-0 pl-xs">
                            @foreach($errors->all() as $err)
                                <li>{{ $err }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        {{-- Page Content --}}
        @yield('content')
    </div>

</main>

    {{-- Global Confirm Modal --}}
    <dialog x-ref="globalConfirmModal" @click="$event.target === $refs.globalConfirmModal && $refs.globalConfirmModal.close()" class="p-lg border border-hairline bg-surface rounded-lg max-w-[400px] w-full backdrop:bg-black/40 shadow-2xl m-auto">
        <div class="flex justify-between items-center mb-md border-b pb-xs">
            <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
                <i data-lucide="alert-triangle" class="w-4 h-4 text-danger"></i> Konfirmasi
            </h3>
            <button type="button" @click="$refs.globalConfirmModal.close()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <div class="mb-lg">
            <p class="text-[14px] text-ink" x-text="confirmMessage"></p>
        </div>
        <div class="flex justify-end gap-xs">
            <button type="button" @click="$refs.globalConfirmModal.close()" class="btn btn-secondary">Batal</button>
            <button type="button" @click="executeConfirm()" class="btn btn-primary">Ya, Lanjutkan</button>
        </div>
    </dialog>

</body>
</html>
