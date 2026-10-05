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
        {{-- Auto Discovery --}}
        <div class="card p-md mb-md">
            <h3 class="text-[14px] font-semibold text-ink mb-[4px] flex items-center gap-xs">
                <i data-lucide="radar" class="w-4 h-4 text-primary"></i> Auto-Discovery (LAN)
            </h3>
            <p class="text-caption text-ink-muted mb-sm">
                Cari dan pair perangkat Edge Engine di jaringan yang sama — tanpa terminal.
            </p>

            {{-- Building selector for auto-pair --}}
            <div class="mb-sm">
                <label class="form-label">Gedung Tujuan</label>
                <select id="auto-pair-building" class="form-select">
                    <option value="">— Pilih Gedung —</option>
                    @foreach($buildings as $building)
                        <option value="{{ $building->id }}">{{ $building->name }}</option>
                    @endforeach
                </select>
            </div>
            
            <button onclick="scanNetwork()" id="btn-scan" class="btn btn-primary w-full text-[13px] py-2 rounded-md flex justify-center items-center gap-2">
                <i data-lucide="search" class="w-4 h-4"></i> Scan Jaringan Sekarang
            </button>
            
            <div id="scan-results" class="mt-md hidden">
                <p class="text-eyebrow text-ink-muted mb-xs">Hasil Scan</p>
                <div id="devices-list" class="flex flex-col gap-2">
                    <!-- Injected by JS -->
                </div>
            </div>
        </div>

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

<script>
async function scanNetwork() {
    const btn = document.getElementById('btn-scan');
    const resultsDiv = document.getElementById('scan-results');
    const list = document.getElementById('devices-list');
    
    btn.disabled = true;
    btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Mencari...';
    lucide.createIcons();
    list.innerHTML = '<p class="text-caption text-ink-muted text-center py-4">Scanning UDP Port 55555...</p>';
    resultsDiv.classList.remove('hidden');

    try {
        const res = await fetch('{{ route("devices.discover") }}');
        const data = await res.json();
        
        list.innerHTML = '';
        if (data.devices.length === 0) {
            list.innerHTML = '<p class="text-caption text-ink-muted text-center py-4">Tidak ada perangkat ditemukan.</p>';
        } else {
            data.devices.forEach(dev => {
                const isPaired = dev.status === 'paired';
                const actionHtml = isPaired 
                    ? `<span class="badge badge-muted text-[10px]">Sudah Paired</span>`
                    : `<button onclick="autoPair('${dev.ip_address}', ${dev.port}, '${dev.device_id}')" class="btn btn-primary text-[11px] px-2 py-1 rounded-sm">Pair</button>`;
                    
                list.innerHTML += `
                    <div class="p-3 border border-hairline rounded-md flex justify-between items-center bg-canvas">
                        <div>
                            <div class="text-[12px] font-semibold text-ink">${dev.device_id}</div>
                            <div class="text-[11px] text-ink-muted font-mono">${dev.ip_address}:${dev.port}</div>
                        </div>
                        ${actionHtml}
                    </div>
                `;
            });
        }
    } catch (e) {
        list.innerHTML = '<p class="text-caption text-red-500 text-center py-4">Gagal melakukan scan jaringan.</p>';
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="search" class="w-4 h-4"></i> Scan Jaringan Ulang';
        lucide.createIcons();
    }
}

async function autoPair(ip, port, name) {
    const buildingId = document.getElementById('auto-pair-building').value;
    if (!buildingId) {
        alert('Pilih Gedung Tujuan terlebih dahulu sebelum pairing.');
        return;
    }
    if (!confirm(`Pair otomatis dengan perangkat "${name}"?`)) return;
    
    try {
        const res = await fetch('{{ route("devices.auto-pair") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ ip_address: ip, port: port, building_id: buildingId })
        });
        
        const data = await res.json();
        if (data.success) {
            alert(data.message);
            window.location.reload();
        } else {
            alert('Gagal: ' + data.message);
        }
    } catch (e) {
        alert('Terjadi kesalahan jaringan.');
    }
}
</script>

@endsection
