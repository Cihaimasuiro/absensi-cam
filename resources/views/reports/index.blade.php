@extends('layouts.app')
@section('title', 'Laporan Presensi')

@section('content')

{{-- Filter bar --}}
<div class="card" style="padding:var(--space-md); margin-bottom:var(--space-md);">
    <form method="GET" action="{{ route('reports.index') }}" style="display:flex; gap:var(--space-md); align-items:flex-end; flex-wrap:wrap;">
        <div>
            <label class="form-label">Tanggal Mulai</label>
            <input type="date" name="start_date" value="{{ request('start_date', now()->format('Y-m-d')) }}" class="form-input" style="width:160px;">
        </div>
        <div>
            <label class="form-label">Tanggal Akhir</label>
            <input type="date" name="end_date" value="{{ request('end_date', now()->format('Y-m-d')) }}" class="form-input" style="width:160px;">
        </div>
        <div style="flex:1; min-width:160px;">
            <label class="form-label">Cari NIS / Nama</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Ketik untuk cari…" class="form-input">
        </div>
        <div>
            <button type="submit" class="btn btn-utility">Filter</button>
        </div>
    </form>
</div>

{{-- Table --}}
<div class="card" style="padding:0; overflow:hidden;">
    <table class="data-table">
        <thead>
            <tr>
                <th>Nama Anggota</th>
                <th>NIS</th>
                <th>Sekolah</th>
                <th>Waktu</th>
                <th>Status</th>
                <th>Keyakinan</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs ?? [] as $log)
                <tr>
                    <td style="font-weight:500; color:var(--color-ink);">{{ $log->student->full_name ?? '-' }}</td>
                    <td style="font-family:monospace; font-size:12px;">{{ $log->student->nis ?? '-' }}</td>
                    <td>{{ $log->student->school->name ?? '-' }}</td>
                    <td>{{ $log->scanned_at->format('Y-m-d H:i:s') }}</td>
                    <td>
                        <span class="badge {{ $log->direction === 'in' ? 'badge-success' : 'badge-muted' }}">
                            {{ $log->direction === 'in' ? 'Masuk' : 'Keluar' }}
                        </span>
                    </td>
                    <td>{{ number_format($log->confidence_score, 3) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" style="text-align:center; color:var(--color-ink-faint); padding:var(--space-xxl);">
                        Tidak ada data absensi.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if(isset($logs) && $logs->hasPages())
        <div style="padding:var(--space-sm) var(--space-md); border-top:1px solid var(--color-hairline); background:var(--color-canvas-soft);">
            {{ $logs->links('pagination::tailwind') }}
        </div>
    @endif
</div>
@endsection
