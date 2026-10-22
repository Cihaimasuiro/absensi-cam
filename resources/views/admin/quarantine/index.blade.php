@extends('layouts.app')
@section('title', 'Karantina Biometrik')

@section('header-actions')
    <form method="POST" action="{{ route('admin.quarantine.scan') }}" class="inline">
        @csrf
        <button type="submit" class="btn btn-secondary text-xs" title="Periksa integritas seluruh template wajah di database">
            <i data-lucide="scan-line" class="w-3.5 h-3.5"></i> Scan Integritas Biometrik
        </button>
    </form>
@endsection

@section('content')
<div x-data="quarantineData" class="space-y-sm">
    {{-- Summary KPIs --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-xs">
        <div class="card p-xs flex items-center justify-between">
            <div>
                <span class="text-xs text-ink-muted block">Aktif Dikarantina</span>
                <span class="text-lg font-semibold text-danger">{{ $totalActive }}</span>
            </div>
            <i data-lucide="alert-octagon" class="w-5 h-5 text-danger opacity-80"></i>
        </div>
        <div class="card p-xs flex items-center justify-between">
            <div>
                <span class="text-xs text-ink-muted block">Terselesaikan</span>
                <span class="text-lg font-semibold text-success">{{ $totalResolved }}</span>
            </div>
            <i data-lucide="check-circle" class="w-5 h-5 text-success opacity-80"></i>
        </div>
        <div class="card p-xs flex items-center justify-between">
            <div>
                <span class="text-xs text-ink-muted block">Terminal Lapor Ditolak</span>
                <span class="text-lg font-semibold text-ink">{{ $devicesWithQuarantine->count() }}</span>
            </div>
            <i data-lucide="cpu" class="w-5 h-5 text-ink-muted opacity-80"></i>
        </div>
    </div>

    {{-- Filter bar --}}
    <div class="card p-xs flex flex-wrap items-center justify-between gap-xs">
        <form method="GET" action="{{ route('admin.quarantine.index') }}" class="flex flex-wrap items-center gap-xs">
            <input type="text" name="search" value="{{ $search }}" placeholder="Cari siswa atau kode..."
                   class="form-input text-xs py-1 w-48 md:w-64">

            <select name="status" class="form-select text-xs py-1 w-36">
                <option value="quarantined" {{ $status === 'quarantined' ? 'selected' : '' }}>Aktif Karantina</option>
                <option value="resolved" {{ $status === 'resolved' ? 'selected' : '' }}>Telah Selesai</option>
                <option value="all" {{ $status === 'all' ? 'selected' : '' }}>Semua Status</option>
            </select>

            <button type="submit" class="btn btn-secondary text-xs py-1">Filter</button>
            @if($search || $status !== 'quarantined')
                <a href="{{ route('admin.quarantine.index') }}" class="btn btn-ghost text-xs py-1">Reset</a>
            @endif
        </form>
        <span class="text-xs text-ink-muted">Total: {{ $quarantines->total() }} catatan</span>
    </div>

    {{-- Quarantined table --}}
    <div class="card p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Anggota</th>
                        <th>Grup / Kelas</th>
                        <th>Alasan Karantina</th>
                        <th>Rincian Error</th>
                        <th>Waktu Masuk</th>
                        <th>Status</th>
                        <th class="text-right">Tindakan Pemulihan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($quarantines as $item)
                        <tr>
                            <td>
                                @if($item->student)
                                    <div class="font-medium text-ink">{{ $item->student->name }}</div>
                                    <div class="font-mono text-[11px] text-ink-muted">{{ $item->student->code }}</div>
                                @else
                                    <span class="text-ink-muted text-xs">Siswa ID: {{ $item->student_id }}</span>
                                @endif
                            </td>
                            <td class="text-xs text-ink-muted">
                                {{ $item->student?->classroom?->name ?? '—' }}
                            </td>
                            <td>
                                <span class="font-mono text-xs font-semibold text-danger">{{ $item->reason }}</span>
                            </td>
                            <td class="text-xs text-ink-muted max-w-xs truncate" title="{{ $item->error_details }}">
                                {{ $item->error_details ?? '—' }}
                            </td>
                            <td class="text-xs text-ink-muted whitespace-nowrap">{{ $item->quarantined_at->format('Y-m-d H:i') }}</td>
                            <td>
                                @if($item->status === 'quarantined')
                                    <span class="text-xs font-semibold text-danger">Karantina</span>
                                @else
                                    <span class="text-xs font-semibold text-success">Selesai</span>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap space-x-1">
                                @if($item->status === 'quarantined')
                                    <form method="POST" action="{{ route('admin.quarantine.re-enroll', $item) }}" class="inline"
                                          onsubmit="return confirm('Ekstrak ulang embedding wajah dari foto tersimpan untuk {{ $item->student?->name }}?');">
                                        @csrf
                                        <button type="submit" class="btn btn-primary text-xs py-0.5 px-2">
                                            Re-Enroll
                                        </button>
                                    </form>

                                    <button type="button"
                                            data-item="{{ json_encode(['id' => $item->id, 'student' => $item->student?->name]) }}"
                                            @click="openResolve"
                                            class="btn btn-secondary text-xs py-0.5 px-2">
                                        Selesaikan
                                    </button>
                                @else
                                    <span class="text-[11px] text-ink-muted" title="{{ $item->resolution_notes }}">
                                        Oleh: {{ $item->resolver?->name ?? 'Sistem' }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-md text-ink-muted text-xs">
                                Tidak ada data karantina biometrik yang ditemukan. Seluruh template dalam keadaan sehat.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($quarantines->hasPages())
            <div class="p-xs border-t border-hairline">
                {{ $quarantines->links() }}
            </div>
        @endif
    </div>

    {{-- ───────────────────────── Resolve Modal ───────────────────────── --}}
    <dialog id="resolveModal" x-ref="resolveModal" @click="$event.target === $el && $el.close()"
            class="w-[min(460px,94vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title">
                <i data-lucide="check-circle-2" class="w-4 h-4 text-primary"></i> Selesaikan Status Karantina
            </h2>
            <button type="button" @click="$refs.resolveModal.close()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="resolveForm" x-ref="resolveForm" method="POST" action="">
            @csrf
            <div class="modal-body flex flex-col gap-sm">
                <p class="text-xs text-ink-muted">
                    Tandai template untuk <span id="resolveStudentName" class="font-medium text-ink"></span> sebagai selesai / telah ditinjau manual.
                </p>

                <div>
                    <label for="resolve_notes" class="form-label">Catatan Resolusi <span class="req">*</span></label>
                    <textarea id="resolve_notes" name="notes" rows="3" required class="form-input text-xs w-full"
                              placeholder="Contoh: Telah diverifikasi fisik dengan kartu identitas."></textarea>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" @click="$refs.resolveModal.close()" class="btn btn-secondary text-xs">Batal</button>
                <button type="submit" class="btn btn-primary text-xs">Simpan Resolusi</button>
            </div>
        </form>
    </dialog>
</div>
@endsection
