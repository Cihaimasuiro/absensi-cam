@extends('layouts.app')
@section('title', 'Dashboard')

@section('content')
<div style="display:grid; grid-template-columns:1fr 320px; gap:var(--space-lg);">

    {{-- Live Camera --}}
    <div class="card" style="padding:var(--space-md);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:var(--space-md); padding-bottom:var(--space-xs); border-bottom:1px solid var(--color-hairline);">
            <span style="font-size:14px; font-weight:600; color:var(--color-ink);">Live Camera Stream</span>
            <span class="text-eyebrow" style="color:var(--color-ink-faint);">Edge Engine 01 · 192.168.10.50</span>
        </div>
        <div style="background:#000; aspect-ratio:16/9; position:relative; border-radius:var(--rounded-sm); overflow:hidden; display:flex; align-items:center; justify-content:center;">
            <span style="color:var(--color-ink-faint); font-size:13px; position:absolute; z-index:0;">Stream Offline</span>
            <img src="http://192.168.10.50:5000/video_feed"
                 alt="Live Stream"
                 style="position:relative; z-index:1; width:100%; height:100%; object-fit:contain;">
        </div>
    </div>

    {{-- Activity Log --}}
    <div class="card" style="padding:var(--space-md); display:flex; flex-direction:column; max-height:520px;">
        <div style="font-size:14px; font-weight:600; color:var(--color-ink); margin-bottom:var(--space-md); padding-bottom:var(--space-xs); border-bottom:1px solid var(--color-hairline);">
            Log Aktivitas Terbaru
        </div>
        <div style="flex:1; overflow-y:auto;" class="custom-scroll">
            @if(isset($recentLogs) && $recentLogs->count() > 0)
                @foreach($recentLogs as $log)
                    <div style="display:flex; justify-content:space-between; padding:var(--space-xs) 0; border-bottom:1px solid var(--color-hairline);">
                        <div>
                            <div style="font-size:13px; font-weight:500; color:var(--color-ink);">{{ $log->student->full_name ?? 'Unknown' }}</div>
                            <div class="text-caption" style="color:var(--color-ink-muted);">{{ $log->student->nis ?? '-' }}</div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:{{ $log->direction === 'in' ? 'var(--color-accent-green)' : 'var(--color-ink-muted)' }};">
                                {{ $log->direction }}
                            </div>
                            <div class="text-caption" style="color:var(--color-ink-faint);">{{ $log->scanned_at->format('H:i') }}</div>
                        </div>
                    </div>
                @endforeach
            @else
                <div class="text-caption" style="color:var(--color-ink-faint); text-align:center; padding:var(--space-xxl) 0;">Belum ada aktivitas.</div>
            @endif
        </div>
        <div style="padding-top:var(--space-sm); border-top:1px solid var(--color-hairline); text-align:center;">
            <a href="{{ route('reports.index') }}" style="font-size:13px; color:var(--color-primary); font-weight:500; text-decoration:none;">Lihat Semua Laporan →</a>
        </div>
    </div>

</div>
@endsection
