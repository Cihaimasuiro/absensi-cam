@extends('layouts.app')
@section('title', 'Log Audit Keamanan')

@section('header-actions')
    <a href="{{ route('admin.audit.export', request()->query()) }}" class="btn btn-secondary text-xs">
        <i data-lucide="download" class="w-3.5 h-3.5"></i> Ekspor CSV
    </a>
@endsection

@section('content')
<div x-data="auditData" class="space-y-sm">
    {{-- Filter bar --}}
    <div class="card p-xs">
        <form method="GET" action="{{ route('admin.audit.index') }}" class="flex flex-wrap items-center gap-xs">
            <input type="text" name="search" value="{{ $search }}" placeholder="Cari deskripsi..."
                   class="form-input text-xs py-1 w-44 md:w-56">

            <select name="log_name" class="form-select text-xs py-1 w-36">
                <option value="">Semua Kategori</option>
                @foreach($logNames as $name)
                    <option value="{{ $name }}" {{ $selectedLogName === $name ? 'selected' : '' }}>
                        {{ ucfirst($name) }}
                    </option>
                @endforeach
            </select>

            <select name="user_id" class="form-select text-xs py-1 w-40">
                <option value="">Semua Pengguna</option>
                @foreach($users as $user)
                    <option value="{{ $user->id }}" {{ (string)$selectedUserId === (string)$user->id ? 'selected' : '' }}>
                        {{ $user->name }}
                    </option>
                @endforeach
            </select>

            <input type="date" name="start_date" value="{{ $startDate }}" class="form-input text-xs py-1 w-32" title="Dari tanggal">
            <span class="text-xs text-ink-muted">s/d</span>
            <input type="date" name="end_date" value="{{ $endDate }}" class="form-input text-xs py-1 w-32" title="Sampai tanggal">

            <button type="submit" class="btn btn-secondary text-xs py-1">Filter</button>
            @if($search || $selectedLogName || $selectedUserId || $startDate || $endDate)
                <a href="{{ route('admin.audit.index') }}" class="btn btn-ghost text-xs py-1">Reset</a>
            @endif
        </form>
    </div>

    {{-- Audit logs table --}}
    <div class="card p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="w-16">ID</th>
                        <th>Waktu (WIB)</th>
                        <th>Kategori</th>
                        <th>Deskripsi</th>
                        <th>Dilakukan Oleh</th>
                        <th>Target Entitas</th>
                        <th class="text-right">Rincian</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td class="font-mono text-xs text-ink-muted">#{{ $log->id }}</td>
                            <td class="text-xs text-ink whitespace-nowrap">{{ $log->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}</td>
                            <td>
                                <span class="font-mono text-xs font-semibold text-ink">{{ $log->log_name ?? 'system' }}</span>
                            </td>
                            <td class="text-xs text-ink font-medium">{{ $log->description }}</td>
                            <td class="text-xs text-ink-muted">
                                @if($log->causer)
                                    <span class="text-ink font-medium">{{ $log->causer->name }}</span>
                                @else
                                    <span class="font-mono text-ink-muted">Sistem / CLI</span>
                                @endif
                            </td>
                            <td class="font-mono text-xs text-ink-muted">
                                @if($log->subject_type)
                                    {{ class_basename($log->subject_type) }} #{{ $log->subject_id }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <button type="button"
                                        data-log="{{ json_encode([
                                            'id' => $log->id,
                                            'description' => $log->description,
                                            'log_name' => $log->log_name,
                                            'time' => $log->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                                            'causer' => $log->causer?->name ?? 'Sistem',
                                            'subject' => ($log->subject_type ? class_basename($log->subject_type) : '—') . ' (#' . ($log->subject_id ?? '—') . ')',
                                            'properties' => $log->properties,
                                        ]) }}"
                                        @click="openDetails"
                                        class="btn btn-secondary text-xs py-0.5 px-2">
                                    Detail
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-md text-ink-muted text-xs">Belum ada riwayat aktivitas yang tercatat.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($logs->hasPages())
            <div class="p-xs border-t border-hairline">
                {{ $logs->links() }}
            </div>
        @endif
    </div>

    {{-- ───────────────────────── Details Modal ───────────────────────── --}}
    <dialog id="auditDetailsModal" x-ref="detailsModal" @click="$event.target === $el && $el.close()"
            class="w-[min(600px,94vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title">
                <i data-lucide="file-text" class="w-4 h-4 text-primary"></i>
                Detail Log Audit <span id="auditDetailId" class="font-mono text-ink-muted text-xs"></span>
            </h2>
            <button type="button" @click="$refs.detailsModal.close()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <div class="modal-body flex flex-col gap-sm text-xs">
            <div class="grid grid-cols-2 gap-xs bg-canvas-soft p-xs rounded border border-hairline">
                <div>
                    <span class="text-ink-muted block text-[11px]">Waktu Eksekusi</span>
                    <span id="auditDetailTime" class="font-mono font-medium text-ink"></span>
                </div>
                <div>
                    <span class="text-ink-muted block text-[11px]">Kategori</span>
                    <span id="auditDetailName" class="font-mono font-medium text-ink"></span>
                </div>
                <div>
                    <span class="text-ink-muted block text-[11px]">Pelaku (Causer)</span>
                    <span id="auditDetailCauser" class="font-medium text-ink"></span>
                </div>
                <div>
                    <span class="text-ink-muted block text-[11px]">Target Entitas</span>
                    <span id="auditDetailSubject" class="font-mono font-medium text-ink"></span>
                </div>
            </div>

            <div>
                <span class="text-ink-muted block text-[11px] mb-1">Payload Properti / Parameter</span>
                <pre id="auditDetailProps" class="bg-canvas-soft p-xs rounded font-mono text-[11px] overflow-x-auto max-h-60 border border-hairline"></pre>
            </div>
        </div>

        <div class="modal-foot">
            <button type="button" @click="$refs.detailsModal.close()" class="btn btn-secondary text-xs">Tutup</button>
        </div>
    </dialog>
</div>
@endsection
