@extends('layouts.app')

@section('title', 'Laporan Absensi')
@section('page-title', 'Laporan')

@section('header-actions')
    <a href="{{ route('reports.export.csv', request()->query()) }}"
       class="btn btn-secondary btn-sm" id="btn-export-csv">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
        </svg>
        Export CSV
    </a>
@endsection

@section('content')
{{-- ════════════════════════════════════════
     Report toolbar — ported from Reports.tsx ReportToolbar
     date-range + group filter
     ════════════════════════════════════════ --}}
<div class="card p-3 mb-3">
    <form method="GET" action="{{ route('reports.index') }}" id="report-filter-form" class="flex flex-wrap gap-2 items-end">
        <div>
            <label class="form-label" for="report-start">Dari</label>
            <input id="report-start" type="date" name="start" value="{{ $start }}" class="form-input" style="width: auto;">
        </div>
        <div>
            <label class="form-label" for="report-end">Sampai</label>
            <input id="report-end" type="date" name="end" value="{{ $end }}" class="form-input" style="width: auto;">
        </div>
        <div>
            <label class="form-label" for="report-group">Grup</label>
            <select id="report-group" name="group_id" class="form-input" style="width: auto; min-width: 140px;">
                <option value="">Semua Grup</option>
                @foreach($groups as $group)
                    <option value="{{ $group->id }}" {{ $groupId == $group->id ? 'selected' : '' }}>{{ $group->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary" id="btn-apply-report">Terapkan</button>
    </form>
</div>

{{-- Summary row --}}
<div class="flex gap-3 mb-3">
    <div class="stat-card flex-1">
        <span class="stat-card__value">{{ $logs->total() }}</span>
        <span class="stat-card__label">Total Catatan</span>
    </div>
    <div class="stat-card flex-1">
        <span class="stat-card__value" style="color: var(--success);">
            {{ $logs->getCollection()->where('direction', 'in')->count() }}
        </span>
        <span class="stat-card__label">Masuk</span>
    </div>
    <div class="stat-card flex-1">
        <span class="stat-card__value" style="color: var(--text-muted);">
            {{ $logs->getCollection()->where('direction', 'out')->count() }}
        </span>
        <span class="stat-card__label">Keluar</span>
    </div>
    <div class="stat-card flex-1">
        <span class="stat-card__value" style="color: var(--warning);">
            {{ $logs->getCollection()->where('is_corrected', true)->count() }}
        </span>
        <span class="stat-card__label">Dikoreksi</span>
    </div>
</div>

{{-- ════════════════════════════════════════
     Report table — ported from Reports.tsx ReportTable
     columns: name, code, date, time, direction, score, corrected
     ════════════════════════════════════════ --}}
<div class="card">
    @if($logs->isEmpty())
        <div style="text-align: center; padding: 3rem; color: var(--text-muted); font-size: 0.8125rem;">
            Tidak ada data absensi untuk rentang tanggal ini.
        </div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Kode</th>
                    <th>Grup</th>
                    <th>Tanggal</th>
                    <th>Waktu</th>
                    <th>Arah</th>
                    <th>Skor</th>
                    <th>Sumber Waktu</th>
                    <th>Perangkat</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($logs as $log)
                    <tr id="report-row-{{ $log->id }}">
                        <td style="font-weight: 500; color: var(--text-primary);">{{ $log->member?->name ?? '—' }}</td>
                        <td style="font-family: monospace; font-size: 0.75rem; color: var(--text-tertiary);">{{ $log->member?->code ?? '—' }}</td>
                        <td style="color: var(--text-muted); font-size: 0.75rem;">{{ $log->member?->group?->name ?? '—' }}</td>
                        <td style="font-variant-numeric: tabular-nums; font-size: 0.75rem; color: var(--text-tertiary);">
                            {{ $log->captured_at->format('d M Y') }}
                        </td>
                        <td style="font-variant-numeric: tabular-nums; font-size: 0.75rem; color: var(--text-secondary);">
                            {{ $log->captured_at->format('H:i:s') }}
                        </td>
                        <td>
                            @if($log->direction === 'in')
                                <span class="badge badge-success">Masuk</span>
                            @else
                                <span class="badge badge-muted">Keluar</span>
                            @endif
                        </td>
                        <td style="font-variant-numeric: tabular-nums; font-size: 0.75rem; color: var(--text-tertiary);">
                            {{ number_format($log->score, 3) }}
                        </td>
                        <td>
                            @if($log->time_source === 'ntp')
                                <span class="badge badge-accent">NTP</span>
                            @elseif($log->time_source === 'rtc')
                                <span class="badge badge-warning">RTC</span>
                            @else
                                <span class="badge badge-danger">Unsynced</span>
                            @endif
                        </td>
                        <td style="color: var(--text-muted); font-size: 0.75rem;">{{ $log->device?->name ?? '—' }}</td>
                        <td>
                            @if($log->is_corrected)
                                <span class="badge badge-warning">Koreksi</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if($logs->hasPages())
            <div class="px-4 py-3" style="border-top: 1px solid var(--border-primary);">
                {{ $logs->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
