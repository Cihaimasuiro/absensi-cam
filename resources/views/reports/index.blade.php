@extends('layouts.app')
@section('title', 'Laporan Presensi')

@section('content')

{{-- Filter bar --}}
<div class="card p-md mb-md">
    <form method="GET" action="{{ route('reports.index') }}" class="flex gap-md items-end flex-wrap">
        <div>
            <label class="form-label">Tanggal Mulai</label>
            <input type="date" name="start_date" value="{{ request('start_date', now()->format('Y-m-d')) }}" class="form-input w-[160px]">
        </div>
        <div>
            <label class="form-label">Tanggal Akhir</label>
            <input type="date" name="end_date" value="{{ request('end_date', now()->format('Y-m-d')) }}" class="form-input w-[160px]">
        </div>
        <div class="flex-1 min-w-[160px]">
            <label class="form-label">Cari NIS / Nama</label>
            <div class="relative">
                <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint"></i>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Ketik untuk cari…" class="form-input pl-9 w-full">
            </div>
        </div>
        <div>
            <button type="submit" class="btn btn-utility flex items-center gap-xs">
                <i data-lucide="filter" class="w-4 h-4"></i> Filter
            </button>
        </div>
    </form>
</div>

{{-- Table --}}
<div class="card p-0 overflow-hidden">
    <table class="data-table">
        <thead>
            <tr>
                <th>Nama Anggota</th>
                <th>NIS</th>
                <th>Gedung / Organisasi</th>
                <th>Waktu</th>
                <th>Status</th>
                <th>Keyakinan</th>
            </tr>
        </thead>
        <tbody>
            @forelse($logs ?? [] as $log)
                <tr>
                    <td class="font-medium text-ink">{{ $log->student->name ?? '-' }}</td>
                    <td class="font-mono text-[12px]">{{ $log->student->code ?? '-' }}</td>
                    <td>{{ $log->student->school->name ?? '-' }}</td>
                    <td>{{ $log->scanned_at->format('Y-m-d H:i:s') }}</td>
                    <td>
                        <span class="badge {{ $log->direction === 'in' ? 'badge-success' : 'badge-muted' }} inline-flex items-center gap-1">
                            <i data-lucide="{{ $log->direction === 'in' ? 'log-in' : 'log-out' }}" class="w-3 h-3"></i>
                            {{ $log->direction === 'in' ? 'Masuk' : 'Keluar' }}
                        </span>
                    </td>
                    <td>{{ number_format($log->confidence_score, 3) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="text-center text-ink-faint p-xxl">
                        <div class="flex flex-col items-center justify-center gap-sm">
                            <i data-lucide="inbox" class="w-8 h-8 opacity-50"></i>
                            Tidak ada data absensi.
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if(isset($logs) && $logs->hasPages())
        <div class="p-sm px-md border-t border-hairline bg-canvas-soft">
            {{ $logs->links('pagination::tailwind') }}
        </div>
    @endif
</div>
@endsection
