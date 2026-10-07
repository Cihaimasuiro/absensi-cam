@extends('layouts.app')
@section('title', 'Laporan Presensi')

@section('content')
<div x-data="reportsData">

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
        <div class="flex gap-2">
            <button type="submit" class="btn btn-utility flex items-center gap-xs">
                <i data-lucide="filter" class="w-4 h-4"></i> Filter
            </button>
            @role('super_admin|admin')
            <div class="flex gap-1 ml-4 border-l pl-4 border-surface-border">
                <a href="{{ route('reports.export.csv', request()->all()) }}" class="btn btn-utility flex items-center gap-xs text-[12px]">
                    <i data-lucide="download" class="w-4 h-4"></i> Raw CSV
                </a>
                <a href="{{ route('reports.export.dtr.xlsx', request()->all()) }}" class="btn btn-primary flex items-center gap-xs text-[12px]">
                    <i data-lucide="file-spreadsheet" class="w-4 h-4"></i> DTR XLSX
                </a>
                <a href="{{ route('reports.export.dtr.pdf', request()->all()) }}" class="btn btn-danger flex items-center gap-xs text-[12px]">
                    <i data-lucide="file-text" class="w-4 h-4"></i> DTR PDF
                </a>
            </div>
            @endrole
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
                @role('super_admin|admin')
                <th>Aksi</th>
                @endrole
            </tr>
        </thead>
        <tbody>
            @forelse($logs ?? [] as $log)
                <tr>
                    <td class="font-medium text-ink flex items-center gap-2">
                        {{ $log->student->name ?? '-' }}
                        @if($log->is_corrected)
                            <span class="badge badge-warning text-[10px]" title="Dikoreksi Manual">Edited</span>
                        @endif
                    </td>
                    <td class="font-mono text-[12px]">{{ $log->student->code ?? '-' }}</td>
                    <td>{{ $log->student->school->name ?? '-' }}</td>
                    <td>{{ $log->captured_at->format('Y-m-d H:i:s') }}</td>
                    <td>
                        <span class="badge {{ $log->direction === 'in' ? 'badge-success' : 'badge-muted' }} inline-flex items-center gap-1">
                            <i data-lucide="{{ $log->direction === 'in' ? 'log-in' : 'log-out' }}" class="w-3 h-3"></i>
                            {{ $log->direction === 'in' ? 'Masuk' : 'Keluar' }}
                        </span>
                    </td>
                    <td>{{ $log->score ? number_format($log->score, 3) : '-' }}</td>
                    @role('super_admin|admin')
                    <td>
                        <button type="button" class="btn btn-utility text-xs py-1 px-2" data-log="{{ json_encode(['id' => $log->id, 'captured_at' => $log->captured_at->format('Y-m-d\TH:i'), 'direction' => $log->direction]) }}" @click="openCorrectionModal">
                            Koreksi
                        </button>
                    </td>
                    @endrole
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

@role('super_admin|admin')
<dialog x-ref="correctionModal" class="bg-transparent p-0 m-auto backdrop:bg-black/50 open:flex items-center justify-center min-w-full min-h-full">
    <div class="bg-canvas rounded-lg w-full max-w-md shadow-lg overflow-hidden m-auto" @click.stop>
        <div class="px-md py-sm border-b border-hairline flex justify-between items-center">
            <h3 class="font-bold text-ink">Koreksi Absensi</h3>
            <button type="button" @click="closeCorrectionModal" class="text-ink-faint hover:text-ink">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <form x-ref="correctionForm" method="POST" action="" class="p-md flex flex-col gap-md">
            @csrf
            <div>
                <label class="form-label">Waktu Sebenarnya (Lokal)</label>
                <input type="datetime-local" name="corrected_captured_at" id="corrected_captured_at" required class="form-input w-full" x-model="correctionLogData.captured_at">
            </div>
            <div>
                <label class="form-label">Status Absensi</label>
                <select name="corrected_direction" id="corrected_direction" class="form-input w-full" required x-model="correctionLogData.direction">
                    <option value="in">Masuk</option>
                    <option value="out">Keluar</option>
                </select>
            </div>
            <div>
                <label class="form-label">Alasan Koreksi</label>
                <textarea name="reason" required rows="2" class="form-input w-full" placeholder="Misal: Lupa absen, mesin error, dll"></textarea>
            </div>
            <div class="flex justify-end gap-sm mt-sm">
                <button type="button" @click="closeCorrectionModal" class="btn btn-utility">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Koreksi</button>
            </div>
        </form>
    </div>
</dialog>


@endrole
</div>
@endsection
