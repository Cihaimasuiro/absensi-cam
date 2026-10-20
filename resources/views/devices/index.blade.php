@extends('layouts.app')
@section('title', 'Manajemen Perangkat')

@section('header-actions')
    <button type="button" @click="addDeviceModal = true" class="btn btn-primary">
        <i data-lucide="plus" class="w-3.5 h-3.5"></i> Tambah Perangkat
    </button>
@endsection

@section('content')
<div x-data="devicesData(
    {{ session('show_edit_device') ? session('show_edit_device') : 'null' }},
    {{ session('show_config_device') ? session('show_config_device') : 'null' }},
    {{ session('new_device_env') ? json_encode(session('new_device_env')) : 'null' }}
)">

    {{-- ───────────────────── Pending pairing requests ───────────────────── --}}
    @if(isset($pendingPairings) && $pendingPairings->isNotEmpty())
        <section class="card p-0 overflow-hidden mb-md border-warning/40">
            <div class="px-md py-2 bg-warning-soft border-b border-hairline flex flex-wrap items-center justify-between gap-xs">
                <h2 class="text-[12.5px] font-semibold text-ink m-0 flex items-center gap-xs">
                    <i data-lucide="radio" class="w-3.5 h-3.5 text-warning"></i>
                    Menunggu persetujuan
                    <span class="badge badge-warning">{{ $pendingPairings->count() }}</span>
                </h2>
                <p class="text-[11px] text-ink-muted m-0">Cocokkan MAC address dengan stiker fisik pada perangkat.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Hostname</th>
                            <th>MAC Address</th>
                            <th>IP Address</th>
                            <th>Waktu</th>
                            <th>Penetapan Lokasi</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pendingPairings as $pairing)
                            <tr>
                                <td class="font-mono font-semibold text-ink">{{ $pairing->pairing_code }}</td>
                                <td class="font-medium text-ink">{{ $pairing->hostname }}</td>
                                <td class="font-mono text-ink">{{ $pairing->mac_address ?? '—' }}</td>
                                <td class="font-mono text-ink-muted">
                                    {{ $pairing->ip_address ?? '—' }}
                                    @if($pairing->ip_address === $clientIp)
                                        <span class="badge badge-warning ml-1">LAN Anda</span>
                                    @endif
                                </td>
                                <td class="text-ink-muted whitespace-nowrap">{{ $pairing->created_at->diffForHumans() }}</td>
                                <td>
                                    <form id="approve-form-{{ $pairing->id }}" method="POST"
                                          action="{{ route('devices.pairing.approve', $pairing) }}"
                                          class="flex items-center gap-xs">
                                        @csrf
                                        <label class="sr-only" for="pairing-name-{{ $pairing->id }}">Nama perangkat</label>
                                        <input type="text" id="pairing-name-{{ $pairing->id }}" name="name"
                                               value="{{ $pairing->hostname }}" placeholder="Nama perangkat"
                                               class="form-input w-36 py-1 text-[12px]" required>
                                        <label class="sr-only" for="pairing-building-{{ $pairing->id }}">Gedung</label>
                                        <select id="pairing-building-{{ $pairing->id }}" name="building_id"
                                                class="form-select w-36 py-1 text-[12px]" required>
                                            <option value="">Pilih gedung…</option>
                                            @foreach($buildings as $building)
                                                <option value="{{ $building->id }}">{{ $building->name }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                </td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-sm">
                                        <button type="submit" form="approve-form-{{ $pairing->id }}" class="btn btn-primary btn-sm">
                                            <i data-lucide="check" class="w-3 h-3"></i> Setujui
                                        </button>
                                        <form method="POST" action="{{ route('devices.pairing.reject', $pairing) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="btn-danger-link">
                                                <i data-lucide="x" class="w-3 h-3"></i> Tolak
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- ───────────────────────── Device table ───────────────────────────── --}}
    <section class="card p-0 overflow-hidden">
        <div class="overflow-x-auto">
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
                            <td class="font-mono">{{ $device->device_code }}</td>
                            <td>{{ $device->building->name ?? '—' }}</td>
                            <td class="font-mono text-ink-muted">{{ $device->ip_address ?? '—' }}</td>
                            <td>
                                @if($device->isOnline())
                                    <span class="badge badge-success"><span class="live-dot live-dot-online"></span> Online</span>
                                @else
                                    <span class="badge badge-muted">Offline</span>
                                @endif
                            </td>
                            <td class="text-ink-muted whitespace-nowrap">
                                {{ $device->last_heartbeat_at ? $device->last_heartbeat_at->diffForHumans() : 'Belum pernah' }}
                            </td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-sm">
                                    <button type="button" @click="openTelemetry({{ $device->toJson() }})" class="btn-link">
                                        <i data-lucide="activity" class="w-3 h-3"></i> Status
                                    </button>
                                    <button type="button" @click="openControl({{ $device->toJson() }})" class="btn-link">
                                        <i data-lucide="monitor-cog" class="w-3 h-3"></i> Kontrol
                                    </button>
                                    <button type="button" @click="openEdit({{ $device->toJson() }})" class="btn-link">
                                        <i data-lucide="edit" class="w-3 h-3"></i> Edit
                                    </button>
                                    <button type="button" @click="openConfig({{ $device->toJson() }})" class="btn-link">
                                        <i data-lucide="key" class="w-3 h-3"></i> Konfigurasi
                                    </button>
                                    <form method="POST" action="{{ route('devices.destroy', $device) }}" class="inline"
                                          @submit="confirmSubmit"
                                          data-confirm="Hapus perangkat ini secara permanen? Data absensi dari mesin ini akan ikut terhapus.">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn-danger-link">
                                            <i data-lucide="trash-2" class="w-3 h-3"></i> Hapus
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <span class="empty-state-icon"><i data-lucide="cpu" class="w-4 h-4"></i></span>
                                    <span class="empty-state-title">Belum ada perangkat</span>
                                    <p class="empty-state-text">Tambahkan perangkat untuk membuat kode pairing dan token konfigurasi.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ───────────────────────── Add device modal ───────────────────────── --}}
    <dialog x-show="addDeviceModal" x-cloak @close="addDeviceModal = false"
            @click="$event.target === $el && addDeviceModal = false"
            class="w-[min(420px,92vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="plus-circle" class="w-4 h-4 text-primary"></i> Tambah Perangkat</h2>
            <button type="button" @click="addDeviceModal = false" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" action="{{ route('devices.store') }}">
            @csrf
            <div class="modal-body flex flex-col gap-sm">
                <p class="text-[12px] text-ink-muted m-0">Daftarkan perangkat baru dan hasilkan token konfigurasinya seketika.</p>
                <div>
                    <label for="device_name" class="form-label">Nama Perangkat <span class="req">*</span></label>
                    <input type="text" id="device_name" name="name"
                           class="form-input @error('name') is-invalid @enderror"
                           required placeholder="Contoh: Kamera Gerbang Utama">
                    @error('name')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="device_building" class="form-label">Gedung / Lokasi <span class="req">*</span></label>
                    <select id="device_building" name="building_id"
                            class="form-select @error('building_id') is-invalid @enderror" required>
                        <option value="">Pilih gedung…</option>
                        @foreach($buildings as $building)
                            <option value="{{ $building->id }}">{{ $building->name }}</option>
                        @endforeach
                    </select>
                    @error('building_id')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" @click="addDeviceModal = false">Batal</button>
                <button type="submit" class="btn btn-primary">Tambah Perangkat</button>
            </div>
        </form>
    </dialog>

    {{-- ───────────────────────── Edit device modal ──────────────────────── --}}
    <dialog x-show="editDevice" x-cloak @close="closeEdit()"
            @click="$event.target === $el && closeEdit()"
            class="w-[min(480px,92vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="edit-3" class="w-4 h-4 text-primary"></i> Edit Perangkat</h2>
            <button type="button" @click="closeEdit()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <template x-if="editDevice">
            <form method="POST" :action="'/devices/' + editDevice.id">
                @csrf @method('PUT')
                <div class="modal-body flex flex-col gap-sm">
                    <div>
                        <label for="edit_name" class="form-label">Nama Perangkat</label>
                        <input type="text" id="edit_name" name="name" class="form-input" x-model="editDevice.name" required>
                    </div>
                    <div>
                        <label for="edit_building" class="form-label">Gedung / Lokasi</label>
                        <select id="edit_building" name="building_id" class="form-select" x-model="editDevice.building_id" required>
                            @foreach($buildings as $building)
                                <option value="{{ $building->id }}">{{ $building->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="edit_ip" class="form-label">Alamat IP (LAN)</label>
                        <input type="text" id="edit_ip" name="ip_address" class="form-input"
                               x-model="editDevice.ip_address" placeholder="192.168.1.50">
                        <span class="form-hint">Dipakai untuk menampilkan video streaming di halaman Live Kamera.</span>
                    </div>
                    <div class="rounded-md border border-hairline bg-canvas-soft p-sm">
                        <label class="flex items-start gap-xs cursor-pointer">
                            <input type="checkbox" name="liveness_enabled" x-model="editDevice.liveness_enabled"
                                   class="mt-0.5 w-3.5 h-3.5 rounded border-hairline text-primary focus:ring-2 focus:ring-primary/30 cursor-pointer">
                            <span>
                                <span class="block text-[12.5px] font-medium text-ink">Aktifkan anti-spoofing (liveness)</span>
                                <span class="block text-[11px] text-ink-muted mt-0.5">Mencegah presensi memakai foto/video dari ponsel. Matikan untuk menghemat CPU edge engine.</span>
                            </span>
                        </label>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" class="btn btn-secondary" @click="closeEdit()">Batal</button>
                    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                </div>
            </form>
        </template>
    </dialog>

    {{-- ───────────────────────── Config device modal ────────────────────── --}}
    <dialog x-show="configDevice" x-cloak @close="closeConfig()"
            @click="$event.target === $el && closeConfig()"
            class="w-[min(620px,94vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="key" class="w-4 h-4 text-primary"></i> Konfigurasi Perangkat</h2>
            <button type="button" @click="closeConfig()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <template x-if="configDevice">
            <div>
                <div class="modal-body flex flex-col gap-sm">
                    <p class="text-[12px] text-ink-muted m-0">Salin teks berikut ke berkas <code class="font-mono text-ink">.env</code> pada perangkat edge Anda.</p>
                    <pre class="m-0 p-3 rounded-md bg-[#0d1117] text-[#c9d1d9] text-[11.5px] font-mono leading-relaxed whitespace-pre-wrap break-all select-all"
                         x-text="newDeviceEnv ? newDeviceEnv : 'SMART_ABSENSI_URL={{ request()->getSchemeAndHttpHost() }}\nSMART_ABSENSI_TOKEN=<RAHASIA — generate ulang bila lupa>\nSMART_ABSENSI_DEVICE_ID=' + configDevice.device_code + '\nENROLLMENT_EMBED_KEY=<DARI_SERVER_ENV>'"></pre>
                    <p class="text-[11px] text-ink-faint m-0 flex items-start gap-1.5">
                        <i data-lucide="alert-triangle" class="w-3.5 h-3.5 text-warning shrink-0 mt-px"></i>
                        <span>Token hanya ditampilkan sekali saat perangkat dibuat. Kunci enkripsi diambil dari konfigurasi server, bukan dari halaman ini.</span>
                    </p>
                </div>
                <div class="modal-foot justify-between">
                    <form method="POST" :action="'/devices/' + configDevice.id + '/reset-token'"
                          @submit="confirmSubmit"
                          data-confirm="Ganti (reset) token lama? Perangkat akan terputus sampai token baru dipasang.">
                        @csrf
                        <button type="submit" class="btn btn-danger-outline btn-sm">
                            <i data-lucide="refresh-cw" class="w-3 h-3"></i> Generate Ulang Token
                        </button>
                    </form>
                    <button type="button" class="btn btn-secondary" @click="closeConfig()">Tutup</button>
                </div>
            </div>
        </template>
    </dialog>

    {{-- ───────────────────────── Device control panel ───────────────────── --}}
    <dialog x-ref="controlModal" x-show="controlDevice" x-cloak @close="closeControl()"
            @click="$event.target === $el && closeControl()"
            class="w-[min(1180px,96vw)] h-[min(880px,92vh)] m-auto flex flex-col">
        <div class="modal-head">
            <h2 class="modal-title">
                <i data-lucide="monitor-cog" class="w-4 h-4 text-primary"></i>
                <span>Kontrol Perangkat</span>
                <span class="font-mono text-[11px] font-normal text-ink-muted"
                      x-text="controlDevice ? controlDevice.name + ' · ' + controlDevice.ip_address + ':5000' : ''"></span>
            </h2>
            <div class="flex items-center gap-xs shrink-0">
                <a x-show="controlDevice && controlDevice.ip_address" x-cloak
                   :href="controlDevice ? 'http://' + controlDevice.ip_address + ':5000/control' : '#'"
                   target="_blank" rel="noopener noreferrer"
                   class="btn btn-secondary btn-sm">
                    <i data-lucide="external-link" class="w-3 h-3"></i> Tab Baru
                </a>
                <button type="button" @click="reloadControl()" class="btn-icon" aria-label="Muat ulang"
                        title="Muat ulang panel">
                    <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                </button>
                <button type="button" @click="closeControl()" aria-label="Tutup" class="btn-icon">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        </div>

        {{-- No IP configured --}}
        <template x-if="controlDevice && !controlDevice.ip_address">
            <div class="flex-1 flex items-center justify-center">
                <div class="empty-state">
                    <span class="empty-state-icon"><i data-lucide="wifi-off" class="w-4 h-4"></i></span>
                    <span class="empty-state-title">Alamat IP belum diatur</span>
                    <p class="empty-state-text">
                        Isi alamat IP perangkat pada dialog Edit agar panel kontrol dapat dimuat.
                    </p>
                </div>
            </div>
        </template>

        {{-- Iframe --}}
        <template x-if="controlDevice && controlDevice.ip_address">
            <div class="flex-1 min-h-0 relative bg-[#0b0b0d]">
                <div x-show="controlLoading" x-cloak
                     class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 text-[#8e8e93]">
                    <i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i>
                    <span class="text-[12px] font-mono"
                          x-text="'Memuat ' + controlDevice.ip_address + ':5000/control…'"></span>
                </div>
                <iframe x-ref="controlFrame"
                        :src="controlDevice ? 'http://' + controlDevice.ip_address + ':5000/control' : 'about:blank'"
                        @load="controlLoading = false"
                        title="Panel kontrol perangkat edge"
                        class="w-full h-full border-0 block"
                        referrerpolicy="no-referrer"
                        allow="camera; microphone; fullscreen"></iframe>
            </div>
        </template>

        <div class="px-md py-2 border-t border-hairline bg-canvas-soft shrink-0">
            <p class="text-[11px] text-ink-faint m-0 flex items-start gap-1.5">
                <i data-lucide="info" class="w-3.5 h-3.5 shrink-0 mt-px"></i>
                <span>
                    Panel ini dilayani langsung oleh perangkat edge dan memiliki sesi login sendiri.
                    Bila halaman ini kosong, pastikan perangkat menyala dan berada di jaringan yang sama.
                </span>
            </p>
        </div>
    </dialog>

    {{-- ───────────────────── Device telemetry (artcraft theme) ─────────── --}}
    <dialog x-ref="telemetryModal" x-show="telemetryDevice" x-cloak @close="closeTelemetry()"
            @click="$event.target === $el && closeTelemetry()"
            class="w-[min(760px,94vw)] max-h-[min(880px,92vh)] m-auto flex flex-col
                   bg-[#121316] border border-white/15 text-[#f2f1ee] p-0">

        {{-- Header --}}
        <div class="flex items-center justify-between gap-sm px-4 py-3 border-b border-white/15 shrink-0">
            <div class="min-w-0">
                <p class="hud-label text-[#8e8e93] m-0">Edge Device Telemetry</p>
                <h2 class="text-[15px] font-semibold tracking-[-0.03em] m-0 mt-0.5 truncate"
                    x-text="telemetryDevice ? telemetryDevice.name : ''"></h2>
            </div>
            <div class="flex items-center gap-xs shrink-0">
                <span class="flex items-center gap-1.5 px-2 py-1 rounded-sm border text-[10px] font-mono uppercase tracking-[0.15em]"
                      :class="telemetryError
                          ? 'border-[#f87171]/40 bg-[#f87171]/10 text-[#f87171]'
                          : (telemetryLoading ? 'border-white/15 bg-white/5 text-[#8e8e93]'
                                             : 'border-[#4ade80]/40 bg-[#4ade80]/10 text-[#4ade80]')">
                    <span class="w-1.5 h-1.5 rounded-full"
                          :class="telemetryError ? 'bg-[#f87171]' : (telemetryLoading ? 'bg-[#8e8e93] animate-pulse' : 'bg-[#4ade80]')"></span>
                    <span x-text="telemetryError ? 'OFFLINE' : (telemetryLoading ? 'POLLING' : 'LIVE')"></span>
                </span>
                <button type="button" @click="refreshTelemetry()" class="btn-icon text-[#8e8e93] hover:text-[#f2f1ee] hover:bg-white/10"
                        aria-label="Muat ulang" title="Muat ulang">
                    <i data-lucide="refresh-cw" class="w-4 h-4" :class="{ 'animate-spin': telemetryLoading }"></i>
                </button>
                <button type="button" @click="closeTelemetry()" aria-label="Tutup"
                        class="btn-icon text-[#8e8e93] hover:text-[#f2f1ee] hover:bg-white/10">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        </div>

        {{-- Body --}}
        <div class="flex-1 overflow-y-auto custom-scroll p-4 space-y-4">

            {{-- No IP --}}
            <template x-if="telemetryDevice && !telemetryDevice.ip_address">
                <div class="py-12 text-center">
                    <p class="text-[12px] text-[#8e8e93] m-0">Alamat IP belum diatur untuk perangkat ini.</p>
                </div>
            </template>

            {{-- Error --}}
            <template x-if="telemetryError">
                <div class="border border-[#f87171]/30 bg-[#f87171]/5 rounded-sm p-3">
                    <p class="hud-label text-[#f87171] m-0">Connection Error</p>
                    <p class="text-[12px] text-[#f2f1ee]/80 m-0 mt-1" x-text="telemetryError"></p>
                </div>
            </template>

            <template x-if="telemetryDevice && telemetryDevice.ip_address && telemetry">
                <div class="space-y-4">

                    {{-- Identity --}}
                    <section>
                        <p class="hud-label text-[#8e8e93] m-0 mb-2">Identity</p>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-px bg-white/10 border border-white/15 rounded-sm overflow-hidden">
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Hostname</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5 truncate" x-text="telemetry.hostname || '—'"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Device Code</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5 truncate" x-text="telemetry.device_code || '—'"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Firmware</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5" x-text="'v' + (telemetry.firmware_version || '—')"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Model</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5 truncate" x-text="telemetry.model_version || '—'"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Local IP</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5" x-text="telemetry.local_ip || '—'"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Paired</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5"
                                   :class="telemetry.is_paired ? 'text-[#4ade80]' : 'text-[#f87171]'"
                                   x-text="telemetry.is_paired ? 'YES' : 'NO'"></p>
                            </div>
                        </div>
                    </section>

                    {{-- CPU --}}
                    <section>
                        <div class="flex items-baseline justify-between mb-2">
                            <p class="hud-label text-[#8e8e93] m-0">CPU</p>
                            <p class="text-[10px] font-mono text-[#8e8e93] m-0 truncate max-w-[60%]"
                               x-text="telemetry.cpu_model"></p>
                        </div>
                        <div class="border border-white/15 rounded-sm bg-[#101014] p-3 space-y-2.5">
                            <div>
                                <div class="flex items-baseline justify-between mb-1">
                                    <span class="text-[11px] text-[#f2f1ee]/70">Usage</span>
                                    <span class="text-[12px] font-mono tabular-nums"
                                          x-text="fmtPct(telemetry.cpu_usage_pct)"></span>
                                </div>
                                <div class="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-500"
                                         :class="barColor(telemetry.cpu_usage_pct)"
                                         :style="'width:' + clampPct(telemetry.cpu_usage_pct) + '%'"></div>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1">
                                <div>
                                    <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Cores</p>
                                    <p class="text-[12px] font-mono m-0 mt-0.5" x-text="telemetry.cpu_cores"></p>
                                </div>
                                <div>
                                    <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Temp</p>
                                    <p class="text-[12px] font-mono m-0 mt-0.5"
                                       :class="telemetry.cpu_temp_c >= 75 ? 'text-[#f87171]' : (telemetry.cpu_temp_c >= 65 ? 'text-[#fbbf24]' : '')"
                                       x-text="telemetry.cpu_temp_c ? telemetry.cpu_temp_c.toFixed(1) + '°C' : '—'"></p>
                                </div>
                                <div>
                                    <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Load 1m</p>
                                    <p class="text-[12px] font-mono m-0 mt-0.5" x-text="telemetry.load_avg_1.toFixed(2)"></p>
                                </div>
                                <div>
                                    <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Load 5/15m</p>
                                    <p class="text-[12px] font-mono m-0 mt-0.5"
                                       x-text="telemetry.load_avg_5.toFixed(2) + ' / ' + telemetry.load_avg_15.toFixed(2)"></p>
                                </div>
                            </div>
                        </div>
                    </section>

                    {{-- Memory --}}
                    <section>
                        <p class="hud-label text-[#8e8e93] m-0 mb-2">Memory</p>
                        <div class="border border-white/15 rounded-sm bg-[#101014] p-3 space-y-2.5">
                            <div>
                                <div class="flex items-baseline justify-between mb-1">
                                    <span class="text-[11px] text-[#f2f1ee]/70">RAM</span>
                                    <span class="text-[12px] font-mono tabular-nums"
                                          x-text="telemetry.ram_used_mb + ' / ' + telemetry.ram_total_mb + ' MB (' + fmtPct(telemetry.ram_usage_pct) + ')'"></span>
                                </div>
                                <div class="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-500"
                                         :class="barColor(telemetry.ram_usage_pct)"
                                         :style="'width:' + clampPct(telemetry.ram_usage_pct) + '%'"></div>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-2 pt-1">
                                <div>
                                    <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Engine RSS</p>
                                    <p class="text-[12px] font-mono m-0 mt-0.5" x-text="telemetry.process_ram_mb + ' MB'"></p>
                                </div>
                                <div>
                                    <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Engine CPU</p>
                                    <p class="text-[12px] font-mono m-0 mt-0.5" x-text="fmtPct(telemetry.process_cpu_pct)"></p>
                                </div>
                            </div>
                        </div>
                    </section>

                    {{-- Storage --}}
                    <section>
                        <p class="hud-label text-[#8e8e93] m-0 mb-2">Storage</p>
                        <div class="border border-white/15 rounded-sm bg-[#101014] p-3 space-y-2.5">
                            <div>
                                <div class="flex items-baseline justify-between mb-1">
                                    <span class="text-[11px] text-[#f2f1ee]/70">Disk</span>
                                    <span class="text-[12px] font-mono tabular-nums"
                                          x-text="telemetry.disk_used_mb + ' / ' + telemetry.disk_total_mb + ' MB (' + fmtPct(telemetry.disk_usage_pct) + ')'"></span>
                                </div>
                                <div class="h-1.5 bg-white/10 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-500"
                                         :class="barColor(telemetry.disk_usage_pct)"
                                         :style="'width:' + clampPct(telemetry.disk_usage_pct) + '%'"></div>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1">
                                <div>
                                    <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Database</p>
                                    <p class="text-[12px] font-mono m-0 mt-0.5" x-text="telemetry.db_size_mb + ' MB'"></p>
                                </div>
                                <div>
                                    <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Templates</p>
                                    <p class="text-[12px] font-mono m-0 mt-0.5" x-text="telemetry.template_count"></p>
                                </div>
                                <div>
                                    <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Outbox</p>
                                    <p class="text-[12px] font-mono m-0 mt-0.5"
                                       :class="telemetry.outbox_count > 0 ? 'text-[#fbbf24]' : 'text-[#4ade80]'"
                                       x-text="telemetry.outbox_count + ' pending'"></p>
                                </div>
                                <div>
                                    <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Last Sync</p>
                                    <p class="text-[12px] font-mono m-0 mt-0.5 truncate"
                                       x-text="telemetry.last_sync_at ? telemetry.last_sync_at : '—'"></p>
                                </div>
                            </div>
                        </div>
                    </section>

                    {{-- Network --}}
                    <section>
                        <p class="hud-label text-[#8e8e93] m-0 mb-2">Network</p>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-white/10 border border-white/15 rounded-sm overflow-hidden">
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Interface</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5 truncate" x-text="telemetry.network_interface || '—'"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">IP</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5 truncate" x-text="telemetry.network_ip || '—'"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">RX</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5" x-text="fmtBytes(telemetry.network_rx_bytes)"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">TX</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5" x-text="fmtBytes(telemetry.network_tx_bytes)"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5 col-span-2">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">MAC</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5 truncate" x-text="telemetry.network_mac || '—'"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Wi-Fi</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5 truncate"
                                   x-text="telemetry.wifi_ssid ? telemetry.wifi_ssid : '—'"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Signal</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5"
                                   x-text="telemetry.wifi_signal_dbm !== null ? telemetry.wifi_signal_dbm + ' dBm' : '—'"></p>
                            </div>
                        </div>
                    </section>

                    {{-- Hardware / System --}}
                    <section>
                        <p class="hud-label text-[#8e8e93] m-0 mb-2">System</p>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-px bg-white/10 border border-white/15 rounded-sm overflow-hidden">
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">OS</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5 truncate" x-text="telemetry.os_name || '—'"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Kernel</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5 truncate" x-text="telemetry.kernel_version || '—'"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Arch</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5" x-text="telemetry.arch || '—'"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">System Uptime</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5" x-text="fmtUptime(telemetry.uptime_system_sec)"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Engine Uptime</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5" x-text="fmtUptime(telemetry.uptime_sec)"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Camera</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5 truncate"
                                   :class="telemetry.is_streaming ? 'text-[#4ade80]' : ''"
                                   x-text="telemetry.camera_device + ' · ' + telemetry.camera_resolution"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Serial</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5"
                                   :class="telemetry.serial_connected ? 'text-[#4ade80]' : 'text-[#8e8e93]'"
                                   x-text="telemetry.serial_connected ? 'CONNECTED' : 'NONE'"></p>
                            </div>
                            <div class="bg-[#101014] p-2.5">
                                <p class="hud-label text-[#8e8e93] m-0 text-[9px]">Power</p>
                                <p class="text-[12px] font-mono m-0 mt-0.5"
                                   x-text="telemetry.power_volts !== null ? telemetry.power_volts.toFixed(3) + ' V' : '—'"></p>
                            </div>
                        </div>

                        {{-- Throttle warning --}}
                        <template x-if="telemetry.throttled && telemetry.throttled !== 'OK'">
                            <div class="mt-2 border border-[#fbbf24]/30 bg-[#fbbf24]/5 rounded-sm p-2.5 flex items-start gap-2">
                                <i data-lucide="alert-triangle" class="w-3.5 h-3.5 text-[#fbbf24] shrink-0 mt-px"></i>
                                <div>
                                    <p class="hud-label text-[#fbbf24] m-0">Throttling Detected</p>
                                    <p class="text-[11px] font-mono text-[#f2f1ee]/70 m-0 mt-0.5" x-text="telemetry.throttled"></p>
                                </div>
                            </div>
                        </template>
                    </section>
                </div>
            </template>

            {{-- Loading skeleton --}}
            <template x-if="telemetryLoading && !telemetry">
                <div class="space-y-3">
                    <div class="h-16 bg-white/5 rounded-sm animate-pulse"></div>
                    <div class="h-24 bg-white/5 rounded-sm animate-pulse"></div>
                    <div class="h-24 bg-white/5 rounded-sm animate-pulse"></div>
                </div>
            </template>
        </div>

        {{-- Footer --}}
        <div class="px-4 py-2.5 border-t border-white/15 bg-[#101014] shrink-0 flex items-center justify-between gap-sm">
            <p class="text-[10px] font-mono text-[#8e8e93] m-0"
               x-text="telemetryUpdatedAt ? 'Updated ' + telemetryUpdatedAt : 'Auto-refresh 5s'"></p>
            <button type="button" @click="closeTelemetry()" class="btn btn-sm bg-white/10 text-[#f2f1ee] border-white/15 hover:bg-white/15">
                Tutup
            </button>
        </div>
    </dialog>
</div>
@endsection
