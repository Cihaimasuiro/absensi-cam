@extends('layouts.app')

@section('title', $member->name)
@section('page-title', 'Detail Anggota')

@section('header-actions')
    <a href="{{ route('members.index') }}" class="btn btn-secondary btn-sm">← Anggota</a>
    <a href="{{ route('members.edit', $member) }}" class="btn btn-secondary btn-sm" id="btn-edit-member">Edit</a>
    <a href="{{ route('members.enroll', $member) }}" class="btn btn-primary btn-sm" id="btn-enroll-member">Enrollment Wajah</a>
@endsection

@section('content')
<div class="flex flex-col gap-4">

    {{-- ── Top row: profile + status ── --}}
    <div class="flex gap-4">

        {{-- Profile card --}}
        <div class="card p-4 flex gap-4" style="flex: 2; min-width: 0;">
            {{-- Avatar --}}
            <div style="width: 56px; height: 56px; border-radius: 9999px; flex-shrink: 0;
                        background: var(--bg-tertiary); border: 1px solid var(--border-primary);
                        display: flex; align-items: center; justify-content: center;
                        font-size: 1.25rem; font-weight: 700; color: var(--accent);">
                {{ strtoupper(substr($member->name, 0, 1)) }}
            </div>

            {{-- Info --}}
            <div class="flex flex-col gap-1.5 min-w-0">
                <p style="font-size: 0.9375rem; font-weight: 600; color: var(--text-primary);">{{ $member->name }}</p>

                <div class="flex flex-wrap gap-x-4 gap-y-1">
                    <span style="font-size: 0.75rem; color: var(--text-muted);">
                        <span style="color: var(--text-tertiary);">Kode</span>
                        {{ $member->code }}
                    </span>
                    @if($member->role)
                    <span style="font-size: 0.75rem; color: var(--text-muted);">
                        <span style="color: var(--text-tertiary);">Peran</span>
                        {{ Str::ucfirst($member->role) }}
                    </span>
                    @endif
                    @if($member->email)
                    <span style="font-size: 0.75rem; color: var(--text-muted);">
                        <span style="color: var(--text-tertiary);">Email</span>
                        {{ $member->email }}
                    </span>
                    @endif
                    @if($member->phone)
                    <span style="font-size: 0.75rem; color: var(--text-muted);">
                        <span style="color: var(--text-tertiary);">Telp</span>
                        {{ $member->phone }}
                    </span>
                    @endif
                </div>

                <div class="flex gap-x-4 gap-y-1 flex-wrap">
                    @if($member->organization)
                    <span style="font-size: 0.75rem; color: var(--text-muted);">
                        <span style="color: var(--text-tertiary);">Organisasi</span>
                        {{ $member->organization->name }}
                    </span>
                    @endif
                    @if($member->group)
                    <span style="font-size: 0.75rem; color: var(--text-muted);">
                        <span style="color: var(--text-tertiary);">Grup</span>
                        {{ $member->group->name }}
                    </span>
                    @endif
                </div>

                <p style="font-size: 0.6875rem; color: var(--text-muted);">
                    Dibuat {{ $member->created_at->diffForHumans() }}
                </p>
            </div>
        </div>

        {{-- Status card --}}
        <div class="card p-4 flex flex-col gap-3" style="width: 220px; flex-shrink: 0;">
            <p style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Status</p>

            <div class="flex flex-col gap-2">
                {{-- Account --}}
                <div class="flex items-center justify-between">
                    <span style="font-size: 0.6875rem; color: var(--text-tertiary);">Akun</span>
                    @if($member->is_active)
                        <span class="badge badge-success">Aktif</span>
                    @else
                        <span class="badge badge-muted">Nonaktif</span>
                    @endif
                </div>

                {{-- Consent --}}
                <div class="flex items-center justify-between">
                    <span style="font-size: 0.6875rem; color: var(--text-tertiary);">Consent UU PDP</span>
                    @if($member->activeConsent)
                        <span class="badge badge-success">Aktif</span>
                    @else
                        <span class="badge badge-warning">Belum</span>
                    @endif
                </div>

                {{-- Enrollment --}}
                <div class="flex items-center justify-between">
                    <span style="font-size: 0.6875rem; color: var(--text-tertiary);">Enrollment</span>
                    @if($member->faceTemplate)
                        <span class="badge badge-success">Terdaftar</span>
                    @else
                        <span class="badge badge-muted">Belum</span>
                    @endif
                </div>
            </div>

            @if($member->faceTemplate)
            <div style="padding-top: 0.25rem; border-top: 1px solid var(--border-primary);">
                <p style="font-size: 0.625rem; color: var(--text-muted); line-height: 1.5;">
                    Model: <code style="color: var(--accent);">{{ $member->faceTemplate->model_version }}</code><br>
                    Cursor: {{ $member->faceTemplate->version_cursor }}<br>
                    Diperbarui {{ $member->faceTemplate->updated_at->diffForHumans() }}
                </p>
            </div>
            @endif
        </div>
    </div>

    {{-- ── Recent attendance ── --}}
    <div class="card">
        <div class="p-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border-primary);">
            <p style="font-size: 0.8125rem; font-weight: 600; color: var(--text-primary);">Absensi Terbaru</p>
            <span style="font-size: 0.6875rem; color: var(--text-muted);">10 terakhir</span>
        </div>

        @if($member->attendanceLogs->isEmpty())
            <div class="p-6 text-center" style="color: var(--text-muted); font-size: 0.75rem;">
                Belum ada data absensi.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="data-table w-full">
                    <thead>
                        <tr>
                            <th>Waktu Rekam</th>
                            <th>Diterima</th>
                            <th>Arah</th>
                            <th>Skor</th>
                            <th>Liveness</th>
                            <th>Sumber Waktu</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($member->attendanceLogs as $log)
                        <tr>
                            <td>{{ $log->captured_at->setTimezone('Asia/Jakarta')->format('d M Y H:i:s') }}</td>
                            <td>{{ $log->received_at->setTimezone('Asia/Jakarta')->format('d M H:i') }}</td>
                            <td>
                                @if($log->direction === 'in')
                                    <span style="color: #4ade80; font-size: 0.6875rem;">↑ Masuk</span>
                                @elseif($log->direction === 'out')
                                    <span style="color: #f87171; font-size: 0.6875rem;">↓ Keluar</span>
                                @else
                                    <span style="color: var(--text-muted); font-size: 0.6875rem;">—</span>
                                @endif
                            </td>
                            <td>{{ $log->score !== null ? number_format($log->score, 3) : '—' }}</td>
                            <td>{{ $log->liveness_score !== null ? number_format($log->liveness_score, 3) : '—' }}</td>
                            <td style="color: var(--text-muted);">{{ $log->time_source ?? '—' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</div>
@endsection
