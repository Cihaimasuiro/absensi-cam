@extends('layouts.app')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
{{-- ════════════════════════════════════════
     Stats cards — ported from Facenox Overview.tsx StatsCard
     ════════════════════════════════════════ --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">

    <div class="stat-card">
        <span class="stat-card__value">{{ $totalStudents }}</span>
        <span class="stat-card__label">Total Anggota</span>
    </div>

    <div class="stat-card">
        <span class="stat-card__value" style="color: var(--accent);">{{ $presentToday }}</span>
        <span class="stat-card__label">Hadir Hari Ini</span>
    </div>

    <div class="stat-card">
        <span class="stat-card__value" style="color: var(--success);">{{ $totalEnrolled }}</span>
        <span class="stat-card__label">Terdaftar Wajah</span>
    </div>

    <div class="stat-card">
        <span class="stat-card__value" style="color: {{ $devicesOnline > 0 ? 'var(--success)' : 'var(--text-muted)' }};">
            {{ $devicesOnline }}
        </span>
        <span class="stat-card__label">Perangkat Online</span>
    </div>
</div>

{{-- ════════════════════════════════════════
     Live Stream & Activity Log Layout
     ════════════════════════════════════════ --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
    
    {{-- Left Column: Live Video Feed --}}
    <div class="lg:col-span-2">
        <div class="card p-4" style="height: 100%;">
            <div class="page-header mb-4">
                <div class="flex items-center gap-2">
                    <span class="dot-online"></span>
                    <span style="font-size: 0.8125rem; font-weight: 600; color: var(--text-secondary);">Live Face Recognition</span>
                </div>
                <div class="text-xs text-[var(--text-muted)]">Camera: Edge Engine 01</div>
            </div>
            
            <div class="relative w-full overflow-hidden rounded-lg border border-[var(--border-primary)]" style="background: var(--bg-primary); aspect-ratio: 16/9; display: flex; align-items: center; justify-content: center;">
                {{-- Fallback text if stream is offline --}}
                <div class="absolute inset-0 flex flex-col items-center justify-center text-center p-4 z-0">
                    <svg class="w-8 h-8 text-[var(--text-muted)] mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                    <span class="text-sm text-[var(--text-tertiary)] font-medium">Stream Offline</span>
                    <span class="text-xs text-[var(--text-muted)] mt-1">Make sure the Edge Engine is running and connected.</span>
                </div>
                
                {{-- The actual MJPEG stream. Points to the Orange Pi's IP on port 5000 --}}
                {{-- Hardcoded IP for demonstration. In production, this might come from Device settings. --}}
                <img src="http://192.168.10.50:5000/video_feed" 
                     alt="Live Stream" 
                     class="relative z-10 w-full h-full object-contain"
                     onerror="this.style.display='none'"
                     onload="this.style.display='block'"
                     style="display: none;">
            </div>
        </div>
    </div>

    {{-- Right Column: Activity Log --}}
    <div class="lg:col-span-1">
        <div class="card p-4" x-data="activityFeed()" style="height: 100%; display: flex; flex-direction: column;">
            <div class="page-header mb-4">
                <span style="font-size: 0.8125rem; font-weight: 600; color: var(--text-secondary);">Aktivitas Terkini</span>
                <span class="badge badge-success text-[10px]">Live</span>
            </div>

            {{-- Activity log list --}}
            <div class="flex-1 overflow-y-auto custom-scroll pr-1" style="max-height: 400px;" id="activity-container">
                @if($recentActivity->isEmpty())
                    <div style="text-align: center; padding: 2rem 0; color: var(--text-muted); font-size: 0.8125rem;">
                        Belum ada aktivitas.
                    </div>
                @else
                    <div class="flex flex-col gap-3">
                        @foreach($recentActivity as $log)
                            <div class="flex items-start gap-3 p-2 rounded-md border border-transparent hover:border-[var(--border-primary)] hover:bg-[var(--bg-hover)] transition-colors">
                                <div class="mt-1">
                                    @if($log->direction === 'in')
                                        <div class="w-6 h-6 rounded-full flex items-center justify-center bg-[rgba(34,197,94,0.12)] text-[#4ade80]">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        </div>
                                    @else
                                        <div class="w-6 h-6 rounded-full flex items-center justify-center bg-[rgba(239,68,68,0.12)] text-[#f87171]">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <div class="text-[0.8125rem] font-medium text-[var(--text-primary)] truncate">
                                            {{ $log->student?->name ?? 'Unknown' }}
                                        </div>
                                        <div class="text-[0.6875rem] text-[var(--text-muted)] whitespace-nowrap">
                                            {{ $log->captured_at->diffForHumans() }}
                                        </div>
                                    </div>
                                    <div class="flex justify-between items-center mt-0.5">
                                        <div class="text-[0.6875rem] text-[var(--text-tertiary)] truncate">
                                            {{ $log->student?->code ?? '-' }} • {{ $log->device?->name ?? 'Scanner' }}
                                        </div>
                                        <div class="text-[0.6875rem] text-[var(--accent)] font-medium">
                                            {{ number_format($log->score * 100, 1) }}% Match
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
// Simple Alpine Component for Activity Feed Auto-Refresh (Polling every 5s)
document.addEventListener('alpine:init', () => {
    Alpine.data('activityFeed', () => ({
        init() {
            // Very simple polling mechanism to reload the page data silently
            setInterval(() => {
                // In a full SPA/Livewire setup, we'd do a partial fetch.
                // For this pure blade setup, we can fetch the HTML of the #activity-container and replace it.
                fetch(window.location.href)
                    .then(res => res.text())
                    .then(html => {
                        const doc = new DOMParser().parseFromString(html, 'text/html');
                        const newContainer = doc.getElementById('activity-container');
                        if (newContainer) {
                            document.getElementById('activity-container').innerHTML = newContainer.innerHTML;
                        }
                    });
            }, 3000);
        }
    }))
})
</script>
@endsection
