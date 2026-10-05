@extends('layouts.app')
@section('title', 'Manajemen Perangkat')

@section('content')

{{-- Pairing Code Result --}}
@if(session('pairing_code'))
    <div class="card bg-blue-50 border-blue-200 mb-md p-md">
        <p class="text-eyebrow text-primary mb-xs">Kode Pairing Berhasil Dibuat — berlaku 15 menit</p>
        <p class="font-mono text-[28px] font-bold tracking-[0.2em] text-secondary mb-xs">
            {{ session('pairing_code') }}
        </p>
        <p class="text-caption text-primary">
            Jalankan <code class="bg-blue-100 px-1 py-[2px] rounded-sm">python pairing.py</code> di Orange Pi, lalu masukkan kode ini.
        </p>
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-[1fr_280px] gap-lg">

    {{-- Device Table --}}
    <div class="card p-0 overflow-hidden">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nama Perangkat</th>
                    <th>Kode</th>
                    <th>Gedung</th>
                    <th>Status</th>
                    <th>Heartbeat</th>
                    <th class="text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($devices as $device)
                    <tr>
                        <td class="font-medium text-ink">{{ $device->name }}</td>
                        <td class="font-mono text-[12px]">{{ $device->device_code }}</td>
                        <td>{{ $device->building->name ?? '-' }}</td>
                        <td>
                            @if($device->isOnline())
                                <span class="badge badge-success">Online</span>
                            @else
                                <span class="badge badge-muted">Offline</span>
                            @endif
                        </td>
                        <td class="text-caption text-ink-muted">
                            {{ $device->last_heartbeat_at ? $device->last_heartbeat_at->diffForHumans() : 'Belum pernah' }}
                        </td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('devices.revoke', $device) }}" class="inline"
                                  onsubmit="return confirm('Cabut token perangkat ini?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn-danger hover:underline inline-flex items-center gap-[4px]">
                                    <i data-lucide="power-off" class="w-3 h-3"></i> Cabut Token
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-ink-faint p-xxl">
                            <div class="flex flex-col items-center justify-center gap-sm">
                                <i data-lucide="cpu" class="w-8 h-8 opacity-50"></i>
                                Belum ada perangkat. Generate kode pairing untuk mendaftarkan perangkat baru.
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Add Device Sidebar --}}
    <div>


        {{-- Manual Pairing Code --}}
        <div class="card p-md">
            <h3 class="text-[14px] font-semibold text-ink mb-[4px] flex items-center gap-xs">
                <i data-lucide="key" class="w-4 h-4 text-primary"></i> Pairing Manual
            </h3>
            <p class="text-caption text-ink-muted mb-md">
                Generate kode 8 karakter untuk di-input manual ke Orange Pi.
            </p>

            <form method="POST" action="{{ route('devices.pair.code') }}">
                @csrf
                <div class="mb-md">
                    <label class="form-label">Gedung / Lokasi</label>
                    <select name="building_id" required class="form-select">
                        <option value="">— Pilih Gedung —</option>
                        @foreach($buildings as $building)
                            <option value="{{ $building->id }}">{{ $building->name }}</option>
                        @endforeach
                    </select>
                    @error('building_id')
                        <p class="form-error">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="btn btn-secondary w-full text-[13px] py-2 rounded-md">
                    Generate Pairing Code
                </button>
            </form>
        </div>
    </div>
</div>



@endsection
