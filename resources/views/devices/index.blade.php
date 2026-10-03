@extends('layouts.app')
@section('title', 'Manajemen Perangkat')

@section('content')

{{-- Pairing Code Result --}}
@if(session('pairing_code'))
    <div class="card" style="background:#eff6ff; border-color:#bfdbfe; margin-bottom:var(--space-md); padding:var(--space-md);">
        <p class="text-eyebrow" style="color:var(--color-primary); margin-bottom:var(--space-xs);">Kode Pairing Berhasil Dibuat — berlaku 15 menit</p>
        <p style="font-family:monospace; font-size:28px; font-weight:700; letter-spacing:0.2em; color:var(--color-secondary); margin-bottom:var(--space-xs);">
            {{ session('pairing_code') }}
        </p>
        <p class="text-caption" style="color:var(--color-primary);">
            Jalankan <code style="background:#dbeafe; padding:1px 4px; border-radius:3px;">python pairing.py</code> di Orange Pi, lalu masukkan kode ini.
        </p>
    </div>
@endif

<div style="display:grid; grid-template-columns:1fr 280px; gap:var(--space-lg);">

    {{-- Device Table --}}
    <div class="card" style="padding:0; overflow:hidden;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nama Perangkat</th>
                    <th>Kode</th>
                    <th>Gedung</th>
                    <th>Status</th>
                    <th>Heartbeat</th>
                    <th style="text-align:right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($devices as $device)
                    <tr>
                        <td style="font-weight:500; color:var(--color-ink);">{{ $device->name }}</td>
                        <td style="font-family:monospace; font-size:12px;">{{ $device->device_code }}</td>
                        <td>{{ $device->building->name ?? '-' }}</td>
                        <td>
                            @if($device->isOnline())
                                <span class="badge badge-success">Online</span>
                            @else
                                <span class="badge badge-muted">Offline</span>
                            @endif
                        </td>
                        <td class="text-caption" style="color:var(--color-ink-muted);">
                            {{ $device->last_heartbeat_at ? $device->last_heartbeat_at->diffForHumans() : 'Belum pernah' }}
                        </td>
                        <td style="text-align:right;">
                            <form method="POST" action="{{ route('devices.revoke', $device) }}" class="inline"
                                  onsubmit="return confirm('Cabut token perangkat ini?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn-danger" style="font-size:12px;">Cabut Token</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align:center; color:var(--color-ink-faint); padding:var(--space-xxl);">
                            Belum ada perangkat. Generate kode pairing untuk mendaftarkan perangkat baru.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Add Device Sidebar --}}
    <div>
        {{-- Auto Discovery --}}
        <div class="card" style="padding:var(--space-md); margin-bottom:var(--space-md);">
            <p style="font-size:14px; font-weight:600; color:var(--color-ink); margin-bottom:4px;">Auto-Discovery (LAN)</p>
            <p class="text-caption" style="color:var(--color-ink-muted); margin-bottom:var(--space-md);">
                Cari perangkat Edge Engine yang terhubung di jaringan WiFi yang sama.
            </p>
            
            <button onclick="scanNetwork()" id="btn-scan" class="btn btn-primary" style="width:100%; font-size:13px; padding:8px 0; border-radius:var(--rounded-md); display:flex; align-items:center; justify-content:center; gap:8px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path><path d="M2 12h20"></path></svg>
                Scan Jaringan Sekarang
            </button>
            
            <div id="scan-results" style="margin-top:var(--space-md); display:none;">
                <p class="text-eyebrow" style="color:var(--color-ink-muted); margin-bottom:var(--space-xs);">Hasil Scan</p>
                <div id="devices-list" style="display:flex; flex-direction:column; gap:8px;">
                    <!-- Injected by JS -->
                </div>
            </div>
        </div>

        {{-- Manual Pairing Code --}}
        <div class="card" style="padding:var(--space-md);">
            <p style="font-size:14px; font-weight:600; color:var(--color-ink); margin-bottom:4px;">Pairing Manual</p>
            <p class="text-caption" style="color:var(--color-ink-muted); margin-bottom:var(--space-md);">
                Generate kode 8 karakter untuk di-input manual ke Orange Pi.
            </p>

            <form method="POST" action="{{ route('devices.pair.code') }}">
                @csrf
                <div style="margin-bottom:var(--space-md);">
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
                <button type="submit" class="btn" style="width:100%; font-size:13px; padding:8px 0; border-radius:var(--rounded-md); border:1px solid var(--color-hairline); background:#fff;">
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
    btn.innerHTML = 'Mencari perangkat...';
    list.innerHTML = '<p class="text-caption" style="color:var(--color-ink-muted); text-align:center;">Scanning UDP Port 55555...</p>';
    resultsDiv.style.display = 'block';

    try {
        const res = await fetch('{{ route("devices.discover") }}');
        const data = await res.json();
        
        list.innerHTML = '';
        if (data.devices.length === 0) {
            list.innerHTML = '<p class="text-caption" style="color:var(--color-ink-muted); text-align:center;">Tidak ada perangkat ditemukan.</p>';
        } else {
            data.devices.forEach(dev => {
                const isPaired = dev.status === 'paired';
                const actionHtml = isPaired 
                    ? `<span class="badge badge-muted">Sudah Paired</span>`
                    : `<button onclick="autoPair('${dev.ip_address}', ${dev.port}, '${dev.device_id}')" class="btn btn-primary" style="font-size:11px; padding:4px 8px; border-radius:4px;">Pair</button>`;
                    
                list.innerHTML += `
                    <div style="padding:12px; border:1px solid var(--color-hairline); border-radius:var(--rounded-md); display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-size:12px; font-weight:600; color:var(--color-ink);">${dev.device_id}</div>
                            <div style="font-size:11px; color:var(--color-ink-muted); font-family:monospace;">${dev.ip_address}:${dev.port}</div>
                        </div>
                        ${actionHtml}
                    </div>
                `;
            });
        }
    } catch (e) {
        list.innerHTML = '<p class="text-caption" style="color:var(--color-danger); text-align:center;">Gagal melakukan scan jaringan.</p>';
    } finally {
        btn.disabled = false;
        btn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path><path d="M2 12h20"></path></svg> Scan Jaringan Ulang`;
    }
}

async function autoPair(ip, port, name) {
    if(!confirm('Pair otomatis dengan perangkat ini?')) return;
    
    try {
        const res = await fetch('{{ route("devices.auto-pair") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ ip_address: ip, port: port, name: name })
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
</div>

@endsection
