<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Smart Absensi') — Smart Absensi</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full" style="display:flex; background:var(--color-canvas-soft); color:var(--color-ink);">

{{-- ═══════ SIDEBAR ═══════ --}}
<aside style="
    width: 200px; flex-shrink: 0;
    background: var(--color-canvas);
    border-right: 1px solid var(--color-hairline);
    display: flex; flex-direction: column;
    height: 100vh; position: sticky; top: 0;
">
    {{-- Brand --}}
    <div style="
        height: 52px; display: flex; align-items: center;
        padding: 0 var(--space-md);
        border-bottom: 1px solid var(--color-hairline);
        gap: var(--space-xs);
    ">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--color-primary)" stroke-width="1.75">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175A2.31 2.31 0 0116.773 6.175l-.821-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/>
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z"/>
        </svg>
        <span style="font-size:14px; font-weight:600; color:var(--color-ink);">Smart Absensi</span>
    </div>

    {{-- Nav --}}
    <nav style="flex:1; overflow-y:auto; padding:var(--space-xs);" class="custom-scroll">
        <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">Dashboard</a>
        <a href="{{ route('students.index') }}" class="nav-item {{ request()->routeIs('students.*') ? 'active' : '' }}">Anggota</a>
        <a href="{{ route('reports.index') }}" class="nav-item {{ request()->routeIs('reports.*') ? 'active' : '' }}">Laporan</a>

        <div style="height:1px; background:var(--color-hairline); margin:var(--space-xs) 0;"></div>
        <p style="font-size:10px; font-weight:600; letter-spacing:0.08em; color:var(--color-ink-faint); padding:0 var(--space-sm) 4px; text-transform:uppercase;">Manajemen</p>

        <a href="{{ route('schools.index') }}" class="nav-item {{ request()->routeIs('schools.*') ? 'active' : '' }}">Sekolah</a>
        <a href="{{ route('devices.index') }}" class="nav-item {{ request()->routeIs('devices.*') ? 'active' : '' }}">Perangkat</a>
    </nav>

    {{-- Footer --}}
    <div style="padding:var(--space-xs) var(--space-md); border-top:1px solid var(--color-hairline);">
        <p style="font-size:11px; color:var(--color-ink-faint);">Edge → Laravel 13</p>
    </div>
</aside>

{{-- ═══════ MAIN ═══════ --}}
<div style="flex:1; display:flex; flex-direction:column; min-width:0;">

    {{-- Top bar --}}
    <header style="
        height: 52px; display:flex; align-items:center; justify-content:space-between;
        padding: 0 var(--space-lg);
        background: var(--color-canvas);
        border-bottom: 1px solid var(--color-hairline);
        flex-shrink: 0;
    ">
        <h1 style="font-size:15px; font-weight:600; color:var(--color-ink);">@yield('title')</h1>
        <div style="display:flex; align-items:center; gap:var(--space-xs);">
            @yield('header-actions')
        </div>
    </header>

    {{-- Flash messages --}}
    @if(session('success') || session('error'))
        <div style="padding:var(--space-md) var(--space-lg) 0;">
            @if(session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-error">{{ session('error') }}</div>
            @endif
        </div>
    @endif

    {{-- Content --}}
    <main style="flex:1; padding:var(--space-lg); overflow-y:auto;" class="custom-scroll">
        @yield('content')
    </main>
</div>

</body>
</html>
