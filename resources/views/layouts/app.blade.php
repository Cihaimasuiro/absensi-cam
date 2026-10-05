<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Smart Absensi') — Smart Absensi</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="bg-canvas-soft text-ink font-sans antialiased min-h-screen">

{{-- WowDash Sidebar --}}
<aside class="fixed inset-y-0 left-0 w-[260px] bg-canvas border-r border-hairline flex flex-col transition-all duration-300 z-50 shadow-sm">
    <div class="h-[72px] flex items-center px-xl border-b border-hairline shrink-0">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-sm">
            <div class="w-8 h-8 rounded-lg bg-primary flex items-center justify-center text-white">
                <i data-lucide="scan-face" class="w-5 h-5"></i>
            </div>
            <span class="text-[20px] font-bold text-ink tracking-tight">Smart Absensi</span>
        </a>
    </div>

    <div class="flex-1 overflow-y-auto py-md custom-scroll">
        <ul class="flex flex-col gap-[4px] px-md">
            <li>
                <a href="{{ route('dashboard') }}" class="flex items-center gap-sm px-md py-[10px] rounded-lg transition-colors {{ request()->routeIs('dashboard') ? 'bg-primary-50 text-primary font-semibold' : 'text-ink-muted hover:bg-surface hover:text-ink font-medium' }}">
                    <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li class="mt-md mb-xs px-md">
                <span class="text-[12px] font-semibold text-ink-faint uppercase tracking-wider">Application</span>
            </li>
            
            <li>
                <a href="{{ route('students.index') }}" class="flex items-center gap-sm px-md py-[10px] rounded-lg transition-colors {{ request()->routeIs('students.*') ? 'bg-primary-50 text-primary font-semibold' : 'text-ink-muted hover:bg-surface hover:text-ink font-medium' }}">
                    <i data-lucide="users" class="w-5 h-5"></i>
                    <span>Anggota</span>
                </a>
            </li>
            <li>
                <a href="{{ route('reports.index') }}" class="flex items-center gap-sm px-md py-[10px] rounded-lg transition-colors {{ request()->routeIs('reports.*') ? 'bg-primary-50 text-primary font-semibold' : 'text-ink-muted hover:bg-surface hover:text-ink font-medium' }}">
                    <i data-lucide="file-text" class="w-5 h-5"></i>
                    <span>Laporan</span>
                </a>
            </li>

            <li class="mt-md mb-xs px-md">
                <span class="text-[12px] font-semibold text-ink-faint uppercase tracking-wider">Management</span>
            </li>

            <li>
                <a href="{{ route('schools.index') }}" class="flex items-center gap-sm px-md py-[10px] rounded-lg transition-colors {{ request()->routeIs('schools.*') ? 'bg-primary-50 text-primary font-semibold' : 'text-ink-muted hover:bg-surface hover:text-ink font-medium' }}">
                    <i data-lucide="building" class="w-5 h-5"></i>
                    <span>Organisasi</span>
                </a>
            </li>
            <li>
                <a href="{{ route('devices.index') }}" class="flex items-center gap-sm px-md py-[10px] rounded-lg transition-colors {{ request()->routeIs('devices.*') ? 'bg-primary-50 text-primary font-semibold' : 'text-ink-muted hover:bg-surface hover:text-ink font-medium' }}">
                    <i data-lucide="cpu" class="w-5 h-5"></i>
                    <span>Perangkat</span>
                </a>
            </li>
        </ul>
    </div>
</aside>

{{-- WowDash Main Content --}}
<main class="ml-[260px] min-h-screen flex flex-col transition-all duration-300 relative">
    
    {{-- WowDash Navbar Header --}}
    <div class="h-[72px] px-xl bg-canvas border-b border-hairline flex items-center justify-between sticky top-0 z-40 shadow-sm">
        <div class="flex items-center gap-md">
            <button class="text-ink-muted hover:text-primary transition-colors flex items-center justify-center w-10 h-10 rounded-full hover:bg-surface">
                <i data-lucide="menu" class="w-6 h-6"></i>
            </button>
            <form class="relative hidden md:block">
                <i data-lucide="search" class="w-4 h-4 absolute left-md top-1/2 -translate-y-1/2 text-ink-faint"></i>
                <input type="text" placeholder="Search..." class="pl-[40px] pr-md py-[8px] rounded-full border border-hairline bg-canvas-soft text-[14px] focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary w-[320px] transition-all">
            </form>
        </div>
        
        <div class="flex items-center gap-md">
            <button class="w-10 h-10 rounded-full flex items-center justify-center bg-canvas-soft border border-hairline text-ink-muted hover:text-primary transition-colors">
                <i data-lucide="moon" class="w-5 h-5"></i>
            </button>
            <button class="w-10 h-10 rounded-full flex items-center justify-center bg-canvas-soft border border-hairline text-ink-muted hover:text-primary transition-colors relative">
                <i data-lucide="bell" class="w-5 h-5"></i>
                <span class="absolute top-2 right-2 w-2 h-2 bg-accent-orange rounded-full border border-canvas"></span>
            </button>
            <div class="flex items-center gap-sm cursor-pointer ml-xs pl-md border-l border-hairline">
                <div class="w-10 h-10 rounded-full bg-primary-100 flex items-center justify-center border border-primary-200 shrink-0">
                    <img src="https://ui-avatars.com/api/?name=Admin+User&background=0075de&color=fff" class="w-full h-full rounded-full" alt="Profile">
                </div>
                <div class="hidden md:block text-left">
                    <p class="text-[14px] font-semibold text-ink leading-tight">Admin User</p>
                    <p class="text-[12px] text-ink-muted">Admin</p>
                </div>
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

        {{-- Flash Messages --}}
        @if(session('success') || session('error'))
            <div class="mb-lg">
                @if(session('success'))
                    <div class="alert alert-success flex items-center gap-xs">
                        <i data-lucide="check-circle" class="w-4 h-4"></i> {{ session('success') }}
                    </div>
                @endif
                @if(session('error'))
                    <div class="alert alert-error flex items-center gap-xs">
                        <i data-lucide="alert-circle" class="w-4 h-4"></i> {{ session('error') }}
                    </div>
                @endif
            </div>
        @endif

        {{-- Page Content --}}
        @yield('content')
    </div>

</main>

<script>
  lucide.createIcons();
</script>
</body>
</html>
