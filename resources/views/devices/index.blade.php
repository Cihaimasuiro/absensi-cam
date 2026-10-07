@extends('layouts.app')
@section('title', 'Manajemen Perangkat')

@section('content')
<div x-data="{ editDevice: null, addDeviceModal: false }">

{{-- Direct Token Result --}}
@if(session('new_device_env'))
    <div class="card bg-blue-50 border-blue-200 mb-md p-md">
        <p class="text-eyebrow text-primary mb-xs">Perangkat Berhasil Ditambahkan — Salin Konfigurasi Berikut</p>
        <p class="text-caption text-primary mb-sm">
            Salin teks di bawah ini dan simpan ke dalam file <code class="bg-blue-100 px-1 py-[2px] rounded-sm">clients/edge-engine/.env</code> di Orange Pi Anda:
        </p>
        <div class="bg-gray-900 text-gray-100 p-3 rounded-md font-mono text-[13px] overflow-x-auto whitespace-pre">
{{ session('new_device_env') }}
        </div>
    </div>
@endif

<div class="flex items-center justify-between mb-md">
    <h1 class="text-[18px] font-bold text-ink">Daftar Perangkat</h1>
    <button type="button" @click="addDeviceModal = true" class="btn-primary inline-flex items-center gap-xs">
        <i data-lucide="plus" class="w-4 h-4"></i> Tambah Perangkat
    </button>
</div>

<div>
    {{-- Device Table --}}
    <div class="card p-0 overflow-hidden">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nama Perangkat</th>
                    <th>Kode</th>
                    <th>Gedung</th>
                    <th>IP (LAN)</th>
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
                        <td class="font-mono text-[12px] text-ink-muted">{{ $device->ip_address ?? '-' }}</td>
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
                        <td class="text-right flex items-center justify-end gap-2">
                            <button type="button" @click='editDevice = {{ $device->toJson() }}' class="btn-secondary hover:underline inline-flex items-center gap-[4px] px-2 py-1 text-[12px]">
                                <i data-lucide="edit" class="w-3 h-3"></i> Edit
                            </button>
                            <form method="POST" action="{{ route('devices.reset-token', $device) }}" class="inline"
                                  @submit="confirmSubmit" data-confirm="Menampilkan konfigurasi akan MENGGANTI (reset) token lama. Orange Pi akan terputus sampai Anda memasukkan token yang baru. Lanjutkan?">
                                @csrf
                                <button type="submit" class="btn-secondary hover:underline inline-flex items-center gap-[4px] px-2 py-1 text-[12px]">
                                    <i data-lucide="key" class="w-3 h-3"></i> Konfigurasi (.env)
                                </button>
                            </form>
                            <form method="POST" action="{{ route('devices.destroy', $device) }}" class="inline"
                                  @submit="confirmSubmit" data-confirm="Hapus perangkat ini secara permanen? Peringatan: Data absensi dari mesin ini akan ikut terhapus.">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn-danger hover:underline inline-flex items-center gap-[4px] px-2 py-1 text-[12px]">
                                    <i data-lucide="trash-2" class="w-3 h-3"></i> Hapus
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
</div>

{{-- Add Device Modal --}}
<div x-show="addDeviceModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" style="display: none;">
    <div @click.away="addDeviceModal = false" class="card p-lg animate-fade-in-up" style="width: 400px; max-width: 90vw;">
        <h2 class="text-[16px] font-bold text-ink mb-[4px] flex items-center gap-xs">
            <i data-lucide="plus-circle" class="w-4 h-4 text-primary"></i> Tambah Perangkat
        </h2>
        <p class="text-caption text-ink-muted mb-md">
            Daftarkan perangkat baru dan generate token konfigurasinya seketika.
        </p>

        <form method="POST" action="{{ route('devices.store') }}">
            @csrf
            <div class="mb-sm">
                <label class="form-label">Nama Perangkat</label>
                <input type="text" name="name" class="form-input" required placeholder="Contoh: Kamera Gerbang Utama">
                @error('name')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>
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
            
            <div class="flex justify-end gap-sm">
                <button type="button" class="btn btn-secondary" @click="addDeviceModal = false">Batal</button>
                <button type="submit" class="btn btn-primary">Tambah Perangkat</button>
            </div>
        </form>
    </div>
</div>

{{-- Edit Device Modal --}}
<div x-show="editDevice" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" style="display: none;">
    <div @click.away="editDevice = null" class="card p-lg animate-fade-in-up" style="width: 400px; max-width: 90vw;">
        <h2 class="text-[16px] font-bold text-ink mb-md">Edit Perangkat</h2>
        <template x-if="editDevice">
            <form method="POST" x-bind:action="'/devices/' + editDevice.id" @submit="$el.action = '/devices/' + editDevice.id">
                @csrf @method('PUT')
                
                <div class="mb-sm">
                    <label class="form-label">Nama Perangkat</label>
                    <input type="text" name="name" class="form-input" x-model="editDevice.name" required>
                </div>
                
                <div class="mb-sm">
                    <label class="form-label">Gedung / Lokasi</label>
                    <select name="building_id" class="form-select" x-model="editDevice.building_id" required>
                        @foreach($buildings as $building)
                            <option value="{{ $building->id }}">{{ $building->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-md">
                    <label class="form-label">Alamat IP (LAN)</label>
                    <input type="text" name="ip_address" class="form-input" x-model="editDevice.ip_address" placeholder="Contoh: 192.168.1.50">
                    <p class="text-[11px] text-ink-muted mt-1">Digunakan untuk melihat video streaming di halaman dashboard.</p>
                </div>

                <div class="flex justify-end gap-sm">
                    <button type="button" class="btn btn-secondary" @click="editDevice = null">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                </div>
            </form>
        </template>
    </div>
</div>

</div>
@endsection
