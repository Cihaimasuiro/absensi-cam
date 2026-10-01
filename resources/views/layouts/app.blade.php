<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Smart Absensi') — Smart Absensi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full" x-data="{ sidebarOpen: false }">

{{-- ══════════════════════════════════════════
     SIDEBAR — ported from Facenox Sidebar.tsx
     Fixed 200px column, dark bg-secondary
     ══════════════════════════════════════════ --}}
<div class="fixed inset-y-0 left-0 z-50 w-[200px] flex flex-col"
     style="background: var(--bg-secondary); border-right: 1px solid var(--border-primary);">

    {{-- Logo / brand --}}
    <div class="flex items-center gap-2 px-4 h-12 shrink-0"
         style="border-bottom: 1px solid var(--border-primary);">
        {{-- Minimal icon (Facenox uses SVG camera icon) --}}
        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175A2.31 2.31 0 0116.773 6.175l-.821-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/>
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z"/>
        </svg>
        <span style="font-size: 0.8125rem; font-weight: 600; color: var(--text-primary);">Smart Absensi</span>
    </div>

    {{-- Navigation (mirrors Facenox ContentPanel sections) --}}
    <nav class="flex-1 overflow-y-auto custom-scroll p-2 flex flex-col gap-0.5">

        {{-- Dashboard / Overview --}}
        <a href="{{ route('dashboard') }}"
           id="nav-dashboard"
           class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/>
            </svg>
            Dashboard
        </a>

        {{-- Members --}}
        <a href="{{ route('members.index') }}"
           id="nav-members"
           class="nav-item {{ request()->routeIs('members.*') ? 'active' : '' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/>
            </svg>
            Anggota
        </a>

        {{-- Reports --}}
        <a href="{{ route('reports.index') }}"
           id="nav-reports"
           class="nav-item {{ request()->routeIs('reports.*') ? 'active' : '' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125h17.25"/>
            </svg>
            Laporan
        </a>

        {{-- Divider --}}
        <div style="height: 1px; background: var(--border-primary); margin: 0.375rem 0;"></div>
        <p style="font-size: 0.625rem; font-weight: 600; letter-spacing: 0.08em; color: var(--text-muted); padding: 0 0.75rem 0.125rem; text-transform: uppercase;">Manajemen</p>

        {{-- Organizations --}}
        <a href="{{ route('organizations.index') }}"
           id="nav-organizations"
           class="nav-item {{ request()->routeIs('organizations.*') ? 'active' : '' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/>
            </svg>
            Organisasi
        </a>

        {{-- Devices / Sync (from settings/Sync.tsx) --}}
        <a href="{{ route('devices.index') }}"
           id="nav-devices"
           class="nav-item {{ request()->routeIs('devices.*') ? 'active' : '' }}">
            <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0H3"/>
            </svg>
            Perangkat
        </a>
    </nav>

    {{-- Footer — firmware/stack info --}}
    <div class="px-3 py-2 shrink-0" style="border-top: 1px solid var(--border-primary);">
        <p style="font-size: 0.625rem; color: var(--text-muted);">Edge → Laravel 13</p>
    </div>
</div>

{{-- ══════════════════════════════════════════
     MAIN CONTENT AREA
     ══════════════════════════════════════════ --}}
<div class="ml-[200px] min-h-full flex flex-col" style="background: var(--bg-primary);">

    {{-- Top bar --}}
    <header class="h-12 flex items-center px-5 shrink-0"
            style="border-bottom: 1px solid var(--border-primary); background: var(--bg-secondary);">
        <h1 class="page-title">@yield('page-title', 'Dashboard')</h1>
        <div class="ml-auto flex items-center gap-2">
            @yield('header-actions')
        </div>
    </header>

    {{-- Flash messages --}}
    @if(session('success') || session('error') || session('warning'))
        <div class="px-5 pt-4" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
             style="transition: opacity 0.3s ease;" x-transition:leave="opacity-0">
            @if(session('success'))
                <div class="alert alert-success mb-2">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-error mb-2">{{ session('error') }}</div>
            @endif
            @if(session('warning'))
                <div class="alert alert-warning mb-2">{{ session('warning') }}</div>
            @endif
        </div>
    @endif

    {{-- Page content --}}
    <main class="flex-1 p-5 custom-scroll overflow-y-auto">
        @yield('content')
    </main>
</div>

</body>
</html>
