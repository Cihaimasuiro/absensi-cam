@extends('layouts.app')

@section('title', 'Perangkat')
@section('page-title', 'Perangkat Edge')

@section('header-actions')
    {{-- Generate pairing code --}}
    <button class="btn btn-primary btn-sm" id="btn-new-pairing"
            @click="document.getElementById('pairing-modal').style.display='flex'">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/>
        </svg>
        Kode Pairing
    </button>
@endsection

@section('content')

{{-- Pairing code success flash --}}
@if(session('pairing_code'))
    <div class="alert alert-success mb-3" x-data="{ show: true, copied: false }" x-show="show"
         style="display: flex; align-items: center; justify-content: space-between;">
        <span>
            Kode pairing: <strong style="font-family: monospace; font-size: 1rem; letter-spacing: 0.1em; color: var(--accent);">
                {{ session('pairing_code') }}
            </strong>
            &nbsp;— berlaku 15 menit.
        </span>
        <button class="btn btn-xs btn-secondary" id="btn-copy-code"
                @click="navigator.clipboard.writeText('{{ session('pairing_code') }}'); copied = true"
                x-text="copied ? 'Disalin!' : 'Salin'">
        </button>
    </div>
@endif

{{-- ════════════════════════════════════════
     Device table — ported from Facenox Sync.tsx device panel
     status, heartbeat, cpu_temp, outbox_len, fw_version
     ════════════════════════════════════════ --}}
<div class="card mb-4">
    @if($devices->isEmpty())
        <div style="text-align: center; padding: 3rem; color: var(--text-muted); font-size: 0.8125rem;">
            Belum ada perangkat terpasang. Buat kode pairing untuk menghubungkan Orange Pi Lite 2 pertama Anda.
        </div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th></th>
                    <th>Nama</th>
                    <th>Kode</th>
                    <th>Gedung</th>
                    <th>Firmware</th>
                    <th>Model</th>
                    <th>CPU °C</th>
                    <th>Outbox</th>
                    <th>IP</th>
                    <th>Heartbeat</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($devices as $device)
                    @php $isOnline = $device->status === 'online' && $device->last_heartbeat_at?->diffInMinutes(now()) <= 3; @endphp
                    <tr id="device-row-{{ $device->id }}">
                        <td>
                            <span class="{{ $isOnline ? 'dot-online' : 'dot-offline' }}"></span>
                        </td>
                        <td style="font-weight: 500; color: var(--text-primary);">{{ $device->name }}</td>
                        <td style="font-family: monospace; font-size: 0.75rem; color: var(--accent);">{{ $device->device_code }}</td>
                        <td style="color: var(--text-muted); font-size: 0.75rem;">{{ $device->building?->name ?? '—' }}</td>
                        <td style="font-family: monospace; font-size: 0.6875rem; color: var(--text-tertiary);">{{ $device->fw_version ?? '—' }}</td>
                        <td style="font-family: monospace; font-size: 0.6875rem; color: var(--text-tertiary);">{{ $device->model_version ?? '—' }}</td>
                        <td style="font-variant-numeric: tabular-nums; font-size: 0.75rem;
                                   color: {{ ($device->last_cpu_temp ?? 0) > 80 ? 'var(--error)' : (($device->last_cpu_temp ?? 0) > 70 ? 'var(--warning)' : 'var(--text-muted)') }}">
                            {{ $device->last_cpu_temp !== null ? number_format($device->last_cpu_temp, 1).'°' : '—' }}
                        </td>
                        <td style="font-variant-numeric: tabular-nums; font-size: 0.75rem;
                                   color: {{ ($device->last_outbox_len ?? 0) > 50 ? 'var(--warning)' : 'var(--text-muted)' }}">
                            {{ $device->last_outbox_len ?? '—' }}
                        </td>
                        <td style="font-family: monospace; font-size: 0.6875rem; color: var(--text-muted);">{{ $device->ip_address ?? '—' }}</td>
                        <td style="font-size: 0.6875rem; color: var(--text-muted); font-variant-numeric: tabular-nums;">
                            {{ $device->last_heartbeat_at ? $device->last_heartbeat_at->diffForHumans() : 'Belum pernah' }}
                        </td>
                        <td>
                            <form method="POST" action="{{ route('devices.revoke', $device) }}"
                                  onsubmit="return confirm('Cabut token perangkat {{ $device->name }}?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-xs btn-danger" id="btn-revoke-{{ $device->id }}"
                                        title="Cabut token">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                    </svg>
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- ════════════════════════════════════════
     Pairing code modal — ported from Sync.tsx pair section
     ════════════════════════════════════════ --}}
<div id="pairing-modal" style="display: none;" class="modal-overlay"
     x-data @click.self="$el.style.display='none'">
    <div class="modal-panel">
        <h2 style="font-size: 0.875rem; font-weight: 600; color: var(--text-primary); margin-bottom: 1rem;">
            Buat Kode Pairing
        </h2>
        <p style="font-size: 0.8125rem; color: var(--text-tertiary); margin-bottom: 1rem; line-height: 1.6;">
            Pilih gedung dan buat kode pairing sekali pakai. Kode berlaku selama <strong>15 menit</strong>.
            Masukkan kode ini pada terminal Orange Pi Lite 2 yang belum terpasang.
        </p>
        <form method="POST" action="{{ route('devices.pair.code') }}" id="pairing-form">
            @csrf
            <label class="form-label" for="pairing-building">Gedung</label>
            <select id="pairing-building" name="building_id" class="form-input mb-3" required>
                <option value="">— Pilih Gedung —</option>
                @foreach($buildings as $building)
                    <option value="{{ $building->id }}">{{ $building->name }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary flex-1" id="btn-generate-code">Buat Kode</button>
                <button type="button" class="btn btn-secondary" @click="document.getElementById('pairing-modal').style.display='none'">Batal</button>
            </div>
        </form>
    </div>
</div>

@endsection
