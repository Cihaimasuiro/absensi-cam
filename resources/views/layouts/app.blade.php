<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Smart Absensi') — Smart Absensi</title>
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
<aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'" class="fixed inset-y-0 left-0 bg-canvas border-r border-hairline flex flex-col transition-all duration-300 ease-in-out z-50 shadow-sm md:translate-x-0 w-[260px] [.sidebar-collapsed_&]:md:w-[80px] overflow-hidden">
    <div class="h-[72px] flex items-center border-b border-hairline shrink-0 px-lg transition-all duration-300 overflow-hidden">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-sm">
            <div class="w-8 h-8 rounded-lg bg-primary flex items-center justify-center text-white shrink-0 transition-all duration-300">
                <i data-lucide="scan-face" class="w-5 h-5"></i>
            </div>
            <span class="text-[18px] font-bold text-ink tracking-tight whitespace-nowrap transition-all duration-300 overflow-hidden md:max-w-[200px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Smart Absensi</span>
        </a>
    </div>

    <div class="flex-1 overflow-y-auto py-md custom-scroll overflow-x-hidden">
        <ul class="flex flex-col gap-[4px] transition-all duration-300 px-md [.sidebar-collapsed_&]:md:px-2">
            <li>
                <a href="{{ route('dashboard') }}" class="flex items-center gap-sm py-[10px] rounded-lg transition-all duration-300 px-md [.sidebar-collapsed_&]:md:px-[22px] {{ request()->routeIs('dashboard') ? 'bg-primary/10 text-primary font-semibold' : 'text-ink-muted hover:bg-surface hover:text-ink font-medium' }}">
                    <i data-lucide="layout-dashboard" class="w-5 h-5 shrink-0"></i>
                    <span class="whitespace-nowrap transition-all duration-300 overflow-hidden md:max-w-[200px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Dashboard</span>
                </a>
            </li>

            <li class="mt-md mb-xs relative flex items-center h-[20px] transition-all duration-300 overflow-hidden px-0">
                <span class="text-[12px] font-semibold text-ink-faint uppercase tracking-wider whitespace-nowrap transition-all duration-300 overflow-hidden absolute left-[16px] md:max-w-[200px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Aplikasi</span>
                <span class="transition-all duration-300 absolute left-1/2 -translate-x-1/2 text-ink-faint hidden md:opacity-0 [.sidebar-collapsed_&]:md:opacity-100 [.sidebar-collapsed_&]:md:block">•••</span>
            </li>
            
            <li>
                <a href="{{ route('students.index') }}" class="flex items-center gap-sm py-[10px] rounded-lg transition-all duration-300 px-md [.sidebar-collapsed_&]:md:px-[22px] {{ request()->routeIs('students.*') ? 'bg-primary/10 text-primary font-semibold' : 'text-ink-muted hover:bg-surface hover:text-ink font-medium' }}">
                    <i data-lucide="users" class="w-5 h-5 shrink-0"></i>
                    <span class="whitespace-nowrap transition-all duration-300 overflow-hidden md:max-w-[200px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Anggota</span>
                </a>
            </li>
            <li>
                <a href="{{ route('reports.index') }}" class="flex items-center gap-sm py-[10px] rounded-lg transition-all duration-300 px-md [.sidebar-collapsed_&]:md:px-[22px] {{ request()->routeIs('reports.*') ? 'bg-primary/10 text-primary font-semibold' : 'text-ink-muted hover:bg-surface hover:text-ink font-medium' }}">
                    <i data-lucide="file-text" class="w-5 h-5 shrink-0"></i>
                    <span class="whitespace-nowrap transition-all duration-300 overflow-hidden md:max-w-[200px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Laporan</span>
                </a>
            </li>

            <li class="mt-md mb-xs relative flex items-center h-[20px] transition-all duration-300 overflow-hidden px-0">
                <span class="text-[12px] font-semibold text-ink-faint uppercase tracking-wider whitespace-nowrap transition-all duration-300 overflow-hidden absolute left-[16px] md:max-w-[200px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Manajemen</span>
                <span class="transition-all duration-300 absolute left-1/2 -translate-x-1/2 text-ink-faint hidden md:opacity-0 [.sidebar-collapsed_&]:md:opacity-100 [.sidebar-collapsed_&]:md:block">•••</span>
            </li>

            <li>
                <a href="{{ route('schools.index') }}" class="flex items-center gap-sm py-[10px] rounded-lg transition-all duration-300 px-md [.sidebar-collapsed_&]:md:px-[22px] {{ request()->routeIs('schools.*') ? 'bg-primary/10 text-primary font-semibold' : 'text-ink-muted hover:bg-surface hover:text-ink font-medium' }}">
                    <i data-lucide="building" class="w-5 h-5 shrink-0"></i>
                    <span class="whitespace-nowrap transition-all duration-300 overflow-hidden md:max-w-[200px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Organisasi</span>
                </a>
            </li>
            <li>
                <a href="{{ route('devices.index') }}" class="flex items-center gap-sm py-[10px] rounded-lg transition-all duration-300 px-md [.sidebar-collapsed_&]:md:px-[22px] {{ request()->routeIs('devices.*') ? 'bg-primary/10 text-primary font-semibold' : 'text-ink-muted hover:bg-surface hover:text-ink font-medium' }}">
                    <i data-lucide="cpu" class="w-5 h-5 shrink-0"></i>
                    <span class="whitespace-nowrap transition-all duration-300 overflow-hidden md:max-w-[200px] md:opacity-100 [.sidebar-collapsed_&]:md:max-w-0 [.sidebar-collapsed_&]:md:opacity-0">Perangkat</span>
                </a>
            </li>
        </ul>
    </div>
</aside>

{{-- Main Content --}}
<main class="min-h-screen flex flex-col transition-all duration-300 ease-in-out relative ml-0 md:ml-[260px] [.sidebar-collapsed_&]:md:ml-[80px]">
    
    {{-- Navbar Header --}}
    <div class="h-[72px] px-xl bg-canvas border-b border-hairline flex items-center justify-between sticky top-0 z-40 shadow-sm">
        <div class="flex items-center gap-md">
            <button type="button" @click="toggleSidebar()" aria-label="Toggle Sidebar" class="text-ink-muted hover:text-primary transition-colors flex items-center justify-center w-9 h-9 rounded-md hover:bg-canvas-soft cursor-pointer">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
        </div>
        
        <div class="flex items-center gap-md">
            <div class="flex items-center gap-sm cursor-pointer ml-xs pl-md">
                <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center border border-primary/20 shrink-0 text-primary font-bold">
                    {{ substr(Auth::user()->name ?? 'A', 0, 1) }}
                </div>
                <div class="hidden md:block text-left mr-sm">
                    <p class="text-[14px] font-semibold text-ink leading-tight">{{ Auth::user()->name ?? 'Admin User' }}</p>
                    <p class="text-[12px] text-ink-muted">{{ Auth::user()->role ?? 'Admin' }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="m-0">
                    @csrf
                    <button type="submit" class="text-ink-muted hover:text-primary transition-colors flex items-center justify-center w-8 h-8 rounded-full hover:bg-surface" aria-label="Logout">
                        <i data-lucide="log-out" class="w-5 h-5"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- WowDash Dashboard Main Body --}}
    <div class="flex-1 p-xl">
        {{-- Breadcrumb Area --}}
        <div class="flex flex-wrap items-center justify-between gap-md mb-lg">
            <div>
                <h6 class="text-[24px] font-bold text-ink mb-1">@yield('title')</h6>
                <ul class="flex items-center gap-2 text-[14px] text-ink-muted">
                    <li>
                        <a href="{{ route('dashboard') }}" class="flex items-center gap-1 hover:text-primary transition-colors">
                            <i data-lucide="home" class="w-4 h-4"></i> Dashboard
                        </a>
                    </li>
                    <li><span class="text-hairline">/</span></li>
                    <li class="text-primary font-medium">@yield('title')</li>
                </ul>
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
            <button type="button" @click="$refs.globalConfirmModal.close()" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <div class="mb-lg">
            <p class="text-[14px] text-ink" x-text="confirmMessage"></p>
        </div>
        <div class="flex justify-end gap-xs">
            <button type="button" @click="$refs.globalConfirmModal.close()" class="btn btn-utility">Batal</button>
            <button type="button" @click="executeConfirm()" class="btn btn-primary">Ya, Lanjutkan</button>
        </div>
    </dialog>

</body>
</html>
