@extends('layouts.app')
@section('title', 'Laporan Presensi')

@section('header-actions')
    @role('super_admin|admin')
        <a href="{{ route('reports.export.csv', request()->all()) }}" class="btn btn-secondary btn-sm btn-export-csv">
            <i data-lucide="file-down" class="w-3.5 h-3.5"></i> CSV
        </a>
        <a href="{{ route('reports.export.dtr.xlsx', request()->all()) }}" class="btn btn-secondary btn-sm btn-export-xlsx">
            <i data-lucide="file-spreadsheet" class="w-3.5 h-3.5"></i> XLSX
        </a>
        <a href="{{ route('reports.export.dtr.pdf', request()->all()) }}" class="btn btn-secondary btn-sm btn-export-pdf">
            <i data-lucide="file-text" class="w-3.5 h-3.5"></i> PDF
        </a>
    @endrole
@endsection

@section('content')
<div x-data="reportsData">

    {{-- ───────────────────────────── Filter bar ─────────────────────────── --}}
    <section class="card p-md mb-md">
        <form method="GET" action="{{ route('reports.index') }}" class="flex flex-wrap gap-sm items-end">
            <div>
                <label for="start_date" class="form-label">Tanggal Mulai</label>
                <input type="date" id="start_date" name="start_date"
                       value="{{ request('start_date', $start ?? now()->format('Y-m-d')) }}"
                       class="form-input w-[150px]">
            </div>
            <div>
                <label for="end_date" class="form-label">Tanggal Akhir</label>
                <input type="date" id="end_date" name="end_date"
                       value="{{ request('end_date', $end ?? now()->format('Y-m-d')) }}"
                       class="form-input w-[150px]">
            </div>
            <div class="flex-1 min-w-[180px]">
                <label for="report_search" class="form-label">Cari</label>
                <div class="relative">
                    <i data-lucide="search" class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-ink-faint pointer-events-none"></i>
                    <input type="text" id="report_search" name="search" value="{{ request('search') }}"
                           placeholder="Nama atau NIS…" class="form-input pl-8 w-full">
                </div>
            </div>
            <div class="flex items-center gap-xs">
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="filter" class="w-3.5 h-3.5"></i> Terapkan
                </button>
                @if(request('start_date') || request('end_date') || request('search'))
                    <a href="{{ route('reports.index') }}" class="btn-link text-ink-muted">Reset</a>
                @endif
            </div>
        </form>
    </section>

    {{-- ───────────────────────────── DTR table ──────────────────────────── --}}
    <section class="card p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama Anggota</th>
                        <th>NIS</th>
                        <th>Gedung / Organisasi</th>
                        <th>Waktu Masuk</th>
                        <th>Terakhir Terdeteksi</th>
                        <th class="text-center">Deteksi</th>
                        @role('super_admin|admin')
                            <th class="text-right">Aksi</th>
                        @endrole
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs ?? [] as $log)
                        <tr>
                            <td>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-ink">{{ $log->student->name ?? '—' }}</span>
                                    @if($log->is_corrected)
                                        <span class="badge badge-warning" title="Dikoreksi manual">Dikoreksi</span>
                                    @endif
                                </div>
                            </td>
                            <td class="font-mono">{{ $log->student->code ?? '—' }}</td>
                            <td>{{ $log->student->school->name ?? '—' }}</td>
                            <td class="whitespace-nowrap">
                                <span class="font-semibold text-ink tabular-nums">{{ \Carbon\Carbon::parse($log->first_in, 'UTC')->timezone(config('app.timezone'))->format('H:i:s') }}</span>
                                <span class="block text-[10.5px] text-ink-muted">{{ \Carbon\Carbon::parse($log->first_in, 'UTC')->timezone(config('app.timezone'))->format('d M Y') }}</span>
                            </td>
                            <td class="text-ink-muted tabular-nums whitespace-nowrap">
                                @if($log->last_out && $log->last_out !== $log->first_in)
                                    {{ \Carbon\Carbon::parse($log->last_out, 'UTC')->timezone(config('app.timezone'))->format('H:i:s') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-center font-mono text-ink-muted">{{ $log->total_logs }}×</td>
                            @role('super_admin|admin')
                                <td class="text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="button" class="btn btn-secondary btn-sm"
                                                data-log="{{ json_encode(['id' => $log->first_log_id, 'captured_at' => \Carbon\Carbon::parse($log->first_in, 'UTC')->timezone(config('app.timezone'))->format('Y-m-d\TH:i'), 'direction' => 'in']) }}"
                                                @click="openCorrectionModal">
                                            <i data-lucide="edit-2" class="w-3 h-3"></i> Koreksi
                                        </button>
                                        <button type="button" class="btn btn-secondary btn-sm"
                                                data-student-id="{{ $log->student_id }}"
                                                data-date="{{ $log->log_date }}"
                                                @click="fetchLogs">
                                            <i data-lucide="list" class="w-3 h-3"></i> Log
                                        </button>
                                    </div>
                                </td>
                            @endrole
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <span class="empty-state-icon"><i data-lucide="inbox" class="w-4 h-4"></i></span>
                                    <span class="empty-state-title">Tidak ada data absensi</span>
                                    <p class="empty-state-text">Ubah rentang tanggal atau filter untuk melihat data lain.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(isset($logs) && $logs->hasPages())
            <div class="px-md py-sm border-t border-hairline bg-canvas-soft">
                {{ $logs->links('pagination::tailwind') }}
            </div>
        @endif
    </section>

    @role('super_admin|admin')
    {{-- ───────────────────────── Correction modal ───────────────────────── --}}
    <dialog x-ref="correctionModal" @click="$event.target === $refs.correctionModal && closeCorrectionModal()"
            class="w-[min(440px,92vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="edit-3" class="w-4 h-4 text-primary"></i> Koreksi Absensi</h2>
            <button type="button" @click="closeCorrectionModal" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form x-ref="correctionForm" method="POST" action="">
            @csrf
            <div class="modal-body flex flex-col gap-sm">
                <div>
                    <label for="corrected_captured_at" class="form-label">Waktu Sebenarnya (lokal) <span class="req">*</span></label>
                    <input type="datetime-local" name="corrected_captured_at" id="corrected_captured_at"
                           required class="form-input" x-model="correctionLogData.captured_at">
                </div>
                <div>
                    <label for="corrected_direction" class="form-label">Status Absensi <span class="req">*</span></label>
                    <select name="corrected_direction" id="corrected_direction" required class="form-select"
                            x-model="correctionLogData.direction">
                        <option value="in">Masuk</option>
                        <option value="out">Keluar</option>
                    </select>
                </div>
                <div>
                    <label for="correction_reason" class="form-label">Alasan Koreksi <span class="req">*</span></label>
                    <textarea name="reason" id="correction_reason" required rows="2" class="form-textarea"
                              placeholder="Misal: lupa absen, mesin error, dll"></textarea>
                    <span class="form-hint">Alasan dicatat pada audit log bersama identitas operator.</span>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" @click="closeCorrectionModal" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Koreksi</button>
            </div>
        </form>
    </dialog>
    @endrole

    {{-- ───────────────────────── Detection log modal ────────────────────── --}}
    <dialog x-ref="logsModal" @click="$event.target === $refs.logsModal && closeLogsModal()"
            class="w-[min(500px,92vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="history" class="w-4 h-4 text-primary"></i> Riwayat Deteksi</h2>
            <button type="button" @click="closeLogsModal" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <div class="modal-body">
            <template x-if="isFetchingLogs">
                <div class="py-lg text-center text-ink-faint flex flex-col items-center gap-xs">
                    <i data-lucide="loader-2" class="w-5 h-5 animate-spin text-primary"></i>
                    <span class="text-[12px]">Memuat data…</span>
                </div>
            </template>

            <template x-if="!isFetchingLogs && logs.length === 0">
                <div class="empty-state">
                    <span class="empty-state-icon"><i data-lucide="inbox" class="w-4 h-4"></i></span>
                    <span class="empty-state-title">Tidak ada log</span>
                </div>
            </template>

            <template x-if="!isFetchingLogs && logs.length > 0">
                <div class="max-h-[320px] overflow-y-auto custom-scroll flex flex-col gap-xs pr-1">
                    <template x-for="log in logs" :key="log.id">
                        <div class="flex items-center justify-between gap-sm p-sm border border-hairline rounded-md bg-canvas-soft">
                            <div class="flex flex-col gap-1 min-w-0">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="font-semibold text-[13px] tabular-nums text-ink" x-text="log.time"></span>
                                    <template x-if="log.is_first">
                                        <span class="badge badge-success">Masuk (dihitung)</span>
                                    </template>
                                    <template x-if="log.is_last && !log.is_first">
                                        <span class="badge badge-muted">Terakhir</span>
                                    </template>
                                    <template x-if="log.is_corrected">
                                        <span class="badge badge-warning">Dikoreksi</span>
                                    </template>
                                </div>
                                <div class="text-[11px] text-ink-muted flex items-center gap-1.5">
                                    <i data-lucide="camera" class="w-3 h-3"></i>
                                    <span x-text="log.device"></span>
                                </div>
                            </div>
                            <div class="shrink-0">
                                <template x-if="log.direction === 'in'">
                                    <span class="badge badge-success">Masuk</span>
                                </template>
                                <template x-if="log.direction === 'out'">
                                    <span class="badge badge-primary">Keluar</span>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>

        <div class="modal-foot">
            <button type="button" @click="closeLogsModal" class="btn btn-secondary">Tutup</button>
        </div>
    </dialog>
</div>
@endsection
