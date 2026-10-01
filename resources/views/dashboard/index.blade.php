@extends('layouts.app')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
{{-- ════════════════════════════════════════
     Stats cards — ported from Facenox Overview.tsx StatsCard
     ════════════════════════════════════════ --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">

    <div class="stat-card">
        <span class="stat-card__value">{{ $totalMembers }}</span>
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
     Date filter — ported from Overview.tsx DateFilter
     ════════════════════════════════════════ --}}
<div class="card p-4" x-data="{ range: '{{ request('range', 'today') }}' }">
    <div class="page-header">
        <span style="font-size: 0.8125rem; font-weight: 600; color: var(--text-secondary);">Aktivitas Terkini</span>
        <div class="flex gap-1">
            @foreach(['today' => 'Hari Ini', 'yesterday' => 'Kemarin', 'week' => 'Minggu Ini'] as $val => $label)
                <a href="{{ route('dashboard', ['range' => $val]) }}"
                   class="btn btn-sm {{ request('range', 'today') === $val ? 'btn-primary' : 'btn-secondary' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    {{-- Activity log table — mirrors Overview.tsx log panel --}}
    @if($recentActivity->isEmpty())
        <div style="text-align: center; padding: 2rem 0; color: var(--text-muted); font-size: 0.8125rem;">
            Belum ada aktivitas absensi hari ini.
        </div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Anggota</th>
                    <th>Kode</th>
                    <th>Arah</th>
                    <th>Skor</th>
                    <th>Perangkat</th>
                    <th>Waktu</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentActivity as $log)
                    <tr>
                        <td style="color: var(--text-primary); font-weight: 500;">
                            {{ $log->member?->name ?? '—' }}
                        </td>
                        <td>{{ $log->member?->code ?? '—' }}</td>
                        <td>
                            @if($log->direction === 'in')
                                <span class="badge badge-success">↑ Masuk</span>
                            @else
                                <span class="badge badge-muted">↓ Keluar</span>
                            @endif
                        </td>
                        <td>
                            <span style="font-variant-numeric: tabular-nums; font-size: 0.75rem; color: var(--text-tertiary);">
                                {{ number_format($log->score, 3) }}
                            </span>
                        </td>
                        <td style="color: var(--text-muted); font-size: 0.75rem;">{{ $log->device?->name ?? '—' }}</td>
                        <td style="color: var(--text-muted); font-size: 0.75rem; font-variant-numeric: tabular-nums;">
                            {{ $log->captured_at->format('H:i:s') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
