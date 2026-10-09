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
            <label class="form-label">Cari</label>
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
            <div class="flex gap-1 items-center">
                <a href="{{ route('reports.export.csv', request()->all()) }}" class="btn btn-utility flex items-center gap-xs text-[12px]">
                    <i data-lucide="download" class="w-4 h-4"></i> Raw CSV
                </a>
                <a href="{{ route('reports.export.dtr.xlsx', request()->all()) }}" class="btn btn-primary flex items-center gap-xs text-[12px]">
                    <i data-lucide="file-spreadsheet" class="w-4 h-4"></i> XLSX
                </a>
                <a href="{{ route('reports.export.dtr.pdf', request()->all()) }}" class="btn btn-danger flex items-center gap-xs text-[12px]">
                    <i data-lucide="file-text" class="w-4 h-4"></i> PDF
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
                <th>Waktu Masuk</th>
                <th>Terakhir Terdeteksi</th>
                <th>Jumlah Deteksi</th>
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
                    <td>
                        <span class="font-medium">{{ \Carbon\Carbon::parse($log->first_in)->format('H:i:s') }}</span>
                        <div class="text-[10px] text-ink-muted">{{ \Carbon\Carbon::parse($log->first_in)->format('d M Y') }}</div>
                    </td>
                    <td class="text-ink-muted text-[13px]">
                        @if($log->last_out && $log->last_out !== $log->first_in)
                            {{ \Carbon\Carbon::parse($log->last_out)->format('H:i:s') }}
                        @else
                            -
                        @endif
                    </td>
                    <td>
                        <span class="badge badge-muted">{{ $log->total_logs }}x</span>
                    </td>
                    @role('super_admin|admin')
                    <td>
                        <div class="flex gap-xs items-center">
                            <button type="button" class="btn btn-utility text-xs py-1 px-2" data-log="{{ json_encode(['id' => $log->first_log_id, 'captured_at' => \Carbon\Carbon::parse($log->first_in)->timezone(config('app.timezone'))->format('Y-m-d\TH:i'), 'direction' => 'in']) }}" @click="openCorrectionModal">
                                Koreksi
                            </button>
                            <button type="button" class="btn btn-secondary text-xs py-1 px-2" @click="fetchLogs('{{ $log->student_id }}', '{{ $log->log_date }}')">
                                <i data-lucide="list" class="w-3 h-3"></i> Log
                            </button>
                        </div>
                    </td>
                    @endrole
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-ink-faint p-xxl">
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
<dialog x-ref="correctionModal" @click="$event.target === $refs.correctionModal && closeCorrectionModal()" class="p-lg border border-hairline bg-surface rounded-lg max-w-[420px] w-full backdrop:bg-black/40 shadow-xl m-auto">
    <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
        <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs text-ink">
            <i data-lucide="edit-3" class="w-4 h-4 text-primary"></i> Koreksi Absensi
        </h3>
        <button type="button" @click="closeCorrectionModal" aria-label="Tutup Modal" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <form x-ref="correctionForm" method="POST" action="" class="flex flex-col gap-md">
        @csrf
        <div>
            <label for="corrected_captured_at" class="form-label">Waktu Sebenarnya (Lokal) *</label>
            <input type="datetime-local" name="corrected_captured_at" id="corrected_captured_at" required class="form-input w-full" x-model="correctionLogData.captured_at">
        </div>
        <div>
            <label for="corrected_direction" class="form-label">Status Absensi *</label>
            <select name="corrected_direction" id="corrected_direction" class="form-input w-full" required x-model="correctionLogData.direction">
                <option value="in">Masuk</option>
                <option value="out">Keluar</option>
            </select>
        </div>
        <div>
            <label for="correction_reason" class="form-label">Alasan Koreksi *</label>
            <textarea name="reason" id="correction_reason" required rows="2" class="form-input w-full" placeholder="Misal: Lupa absen, mesin error, dll"></textarea>
        </div>
        <div class="flex justify-end gap-xs mt-sm">
            <button type="button" @click="closeCorrectionModal" class="btn btn-utility">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan Koreksi</button>
        </div>
    </form>
</dialog>
@endrole

<dialog x-ref="logsModal" @click="$event.target === $refs.logsModal && closeLogsModal()" class="p-lg border border-hairline bg-surface rounded-lg max-w-[500px] w-full backdrop:bg-black/40 shadow-xl m-auto">
    <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
        <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs text-ink">
            <i data-lucide="history" class="w-4 h-4 text-primary"></i> Riwayat Deteksi
        </h3>
        <button type="button" @click="closeLogsModal" aria-label="Tutup Modal" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    
    <div class="flex flex-col gap-sm">
        <template x-if="isFetchingLogs">
            <div class="py-md text-center text-ink-faint flex flex-col items-center gap-xs">
                <i data-lucide="loader-2" class="w-5 h-5 animate-spin text-primary"></i>
                Memuat data...
            </div>
        </template>
        
        <template x-if="!isFetchingLogs && logs.length === 0">
            <div class="py-md text-center text-ink-faint">
                Tidak ada data log.
            </div>
        </template>

        <template x-if="!isFetchingLogs && logs.length > 0">
            <div class="max-h-[300px] overflow-y-auto pr-sm relative space-y-3">
                <template x-for="log in logs" :key="log.id">
                    <div class="flex items-center justify-between p-sm border border-hairline rounded bg-canvas-soft relative">
                        <div class="flex flex-col gap-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-[14px]" x-text="log.time"></span>
                                <template x-if="log.is_first">
                                    <span class="badge badge-success text-[10px]">Masuk (Dihitung)</span>
                                </template>
                                <template x-if="log.is_last && !log.is_first">
                                    <span class="badge badge-muted text-[10px]">Terakhir</span>
                                </template>
                                <template x-if="log.is_corrected">
                                    <span class="badge badge-warning text-[10px]">Edited</span>
                                </template>
                            </div>
                            <div class="text-[11px] text-ink-muted flex items-center gap-2">
                                <span><i data-lucide="camera" class="w-3 h-3 inline"></i> <span x-text="log.device"></span></span>
                            </div>
                        </div>
                        <div class="text-right">
                            <template x-if="log.direction === 'in'">
                                <span class="text-emerald-600 text-xs font-medium"><i data-lucide="log-in" class="w-3 h-3 inline"></i> IN</span>
                            </template>
                            <template x-if="log.direction === 'out'">
                                <span class="text-amber-600 text-xs font-medium"><i data-lucide="log-out" class="w-3 h-3 inline"></i> OUT</span>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>
    <div class="flex justify-end gap-xs mt-md pt-sm border-t border-hairline">
        <button type="button" @click="closeLogsModal" class="btn btn-secondary">Tutup</button>
    </div>
</dialog>

</div>
@endsection
