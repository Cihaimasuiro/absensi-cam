@extends('layouts.app')
@section('title', 'Manajemen Perangkat')

@section('header-actions')
    <button type="button" @click="$dispatch('open-add-device')" onclick="window.dispatchEvent(new CustomEvent('open-add-device'))" class="btn btn-primary">
        <i data-lucide="plus" class="w-3.5 h-3.5"></i> Tambah
    </button>
@endsection

@section('content')
<div x-data="devicesData"
     data-initial-config="{{ session('show_config_device') ? session('show_config_device') : 'null' }}"
     data-initial-env="{{ session('new_device_env') ? json_encode(session('new_device_env')) : 'null' }}"
     data-initial-edit="{{ session('show_edit_device') ? session('show_edit_device') : 'null' }}"
     @open-add-device.window="openAdd()">

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
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button type="submit" form="approve-form-{{ $pairing->id }}" class="btn btn-primary btn-sm">
                                            <i data-lucide="check" class="w-3 h-3"></i> Setujui
                                        </button>
                                        <form method="POST" action="{{ route('devices.pairing.reject', $pairing) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="btn btn-danger-outline btn-sm">
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
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button" data-device="{{ json_encode($device) }}" @click="openTelemetry" class="btn btn-secondary btn-sm" title="Status Telemetri">
                                        <i data-lucide="activity" class="w-3 h-3"></i> Status
                                    </button>
                                    <button type="button" data-device="{{ json_encode($device) }}" @click="openControl" class="btn btn-secondary btn-sm" title="Panel Kontrol">
                                        <i data-lucide="monitor-cog" class="w-3 h-3"></i> Kontrol
                                    </button>
                                    <button type="button" data-device="{{ json_encode($device) }}" @click="openEdit" class="btn btn-secondary btn-sm" title="Edit Perangkat">
                                        <i data-lucide="edit" class="w-3 h-3"></i> Edit
                                    </button>
                                    <button type="button" data-device="{{ json_encode($device) }}" @click="openConfig" class="btn btn-secondary btn-sm" title="Konfigurasi Perangkat">
                                        <i data-lucide="key" class="w-3 h-3"></i> Konfigurasi
                                    </button>
                                    <form method="POST" action="{{ route('devices.destroy', $device) }}" class="inline"
                                          @submit="confirmSubmit"
                                          data-confirm="Hapus perangkat ini secara permanen? Data absensi dari mesin ini akan ikut terhapus.">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-danger-outline btn-sm" title="Hapus Perangkat">
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
    <dialog x-ref="addModal" x-cloak @close="closeAdd"
            @click="$event.target === $refs.addModal && closeAdd()"
            class="w-[min(420px,92vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="plus-circle" class="w-4 h-4 text-primary"></i> Tambah</h2>
            <button type="button" @click="closeAdd" aria-label="Tutup" class="btn-icon">
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
                <button type="button" class="btn btn-secondary" @click="closeAdd">Batal</button>
                <button type="submit" class="btn btn-primary">Tambah</button>
            </div>
        </form>
    </dialog>

    {{-- ───────────────────────── Edit device modal ──────────────────────── --}}
    <dialog x-ref="editModal" x-cloak @close="closeEdit"
            @click="$event.target === $refs.editModal && closeEdit()"
            class="w-[min(480px,92vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="edit-3" class="w-4 h-4 text-primary"></i> Edit Perangkat</h2>
            <button type="button" @click="closeEdit" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" :action="editActionUrl" x-ref="editForm">
            @csrf @method('PUT')
            <div class="modal-body flex flex-col gap-sm">
                <div>
                    <label for="edit_name" class="form-label">Nama Perangkat</label>
                    <input type="text" id="edit_name" name="name" class="form-input"
                           :value="editDevice ? editDevice.name : ''"
                           @input="if (editDevice) editDevice.name = $event.target.value" required>
                </div>
                <div>
                    <label for="edit_building" class="form-label">Gedung / Lokasi</label>
                    <select id="edit_building" name="building_id" class="form-select"
                            :value="editDevice ? editDevice.building_id : ''"
                            @change="if (editDevice) editDevice.building_id = $event.target.value" required>
                        @foreach($buildings as $building)
                            <option value="{{ $building->id }}">{{ $building->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="edit_ip" class="form-label">Alamat IP (LAN)</label>
                    <input type="text" id="edit_ip" name="ip_address" class="form-input"
                           :value="editDevice ? editDevice.ip_address : ''"
                           @input="if (editDevice) editDevice.ip_address = $event.target.value"
                           placeholder="192.168.1.50">
                    <span class="form-hint">Dipakai untuk menampilkan video streaming di halaman Live Kamera.</span>
                </div>
                <div class="rounded-md border border-hairline bg-canvas-soft p-sm">
                    <label class="flex items-start gap-xs cursor-pointer">
                        <input type="checkbox" name="liveness_enabled" value="1"
                               :checked="editDevice && !!editDevice.liveness_enabled"
                               @change="if (editDevice) editDevice.liveness_enabled = $event.target.checked"
                               class="mt-0.5 w-3.5 h-3.5 rounded border-hairline text-primary focus:ring-2 focus:ring-primary/30 cursor-pointer">
                        <span>
                            <span class="block text-[12.5px] font-medium text-ink">Aktifkan anti-spoofing (liveness)</span>
                            <span class="block text-[11px] text-ink-muted mt-0.5">Mencegah presensi memakai foto/video dari ponsel. Matikan untuk menghemat CPU edge engine.</span>
                        </span>
                    </label>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-secondary" @click="closeEdit">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </dialog>

    {{-- ───────────────────────── Config device modal ────────────────────── --}}
    <dialog x-ref="configModal" x-cloak @close="closeConfig"
            @click="$event.target === $refs.configModal && closeConfig()"
            class="w-[min(620px,94vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="key" class="w-4 h-4 text-primary"></i> Konfigurasi Perangkat</h2>
            <button type="button" @click="closeConfig" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <div>
            <div class="modal-body flex flex-col gap-sm">
                <p class="text-[12px] text-ink-muted m-0">Salin teks berikut ke berkas <code class="font-mono text-ink">.env</code> pada perangkat edge Anda.</p>
                <pre class="m-0 p-3 rounded-md bg-[#0d1117] text-[#c9d1d9] text-[11.5px] font-mono leading-relaxed whitespace-pre-wrap break-all select-all"
                     x-text="getConfigEnvText()"></pre>
                <p class="text-[11px] text-ink-faint m-0 flex items-start gap-1.5">
                    <i data-lucide="alert-triangle" class="w-3.5 h-3.5 text-warning shrink-0 mt-px"></i>
                    <span>Token hanya ditampilkan sekali saat perangkat dibuat atau di-generate ulang.</span>
                </p>
            </div>
            <div class="modal-foot justify-between">
                <form method="POST" :action="configResetUrl"
                      @submit="confirmSubmit"
                      data-confirm="Ganti (reset) token lama? Perangkat akan terputus sampai token baru dipasang.">
                    @csrf
                    <button type="submit" class="btn btn-danger-outline btn-sm">
                        <i data-lucide="refresh-cw" class="w-3 h-3"></i> Generate Ulang Token
                    </button>
                </form>
                <button type="button" class="btn btn-secondary" @click="closeConfig">Tutup</button>
            </div>
        </div>
    </dialog>

    {{-- ───────────────────────── Device control panel ───────────────────── --}}
    <dialog x-ref="controlModal" x-show="controlDevice" x-cloak @close="closeControl()"
            @click="$event.target === $el && closeControl()"
            class="w-[min(1560px,98vw)] h-[96vh] m-auto flex flex-col transition-all duration-150"
            :class="{ 'fixed inset-0 !w-screen !h-screen !max-w-none !max-h-none !m-0 !rounded-none !border-0 z-50': controlMaximized }">
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
                <button type="button" @click="toggleControlMaximize()" class="btn-icon"
                        :title="controlMaximized ? 'Perkecil' : 'Layar Penuh'"
                        :aria-label="controlMaximized ? 'Perkecil' : 'Layar Penuh'">
                    <i :data-lucide="controlMaximized ? 'minimize-2' : 'maximize-2'" class="w-4 h-4"></i>
                </button>
                <button type="button" @click="reloadControl()" class="btn-icon" aria-label="Muat ulang"
                        title="Muat ulang panel">
                    <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                </button>
                <button type="button" @click="closeControl()" aria-label="Tutup" class="btn-icon">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        </div>

        <div class="flex-1 min-h-0 relative bg-canvas-soft flex flex-col">
            {{-- No IP configured --}}
            <div x-show="controlDevice && !controlDevice.ip_address" class="flex-1 flex items-center justify-center p-md">
                <div class="empty-state">
                    <span class="empty-state-icon"><i data-lucide="wifi-off" class="w-4 h-4"></i></span>
                    <span class="empty-state-title">Alamat IP belum diatur</span>
                    <p class="empty-state-text">
                        Isi alamat IP perangkat pada dialog Edit agar panel kontrol dapat dimuat.
                    </p>
                </div>
            </div>

            {{-- Iframe container --}}
            <div x-show="controlDevice && controlDevice.ip_address" class="flex-1 min-h-0 relative bg-canvas-soft">
                <div x-show="controlLoading" x-cloak
                     class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2.5 bg-surface/90 backdrop-blur-xs text-ink-muted">
                    <div class="w-6 h-6 rounded-full border-2 border-hairline border-t-primary animate-spin"></div>
                    <span class="text-[12px] font-mono text-ink-secondary"
                          x-text="'Memuat ' + (controlDevice ? controlDevice.ip_address : '') + ':5000/control…'"></span>
                </div>
                <iframe id="edgeControlFrame"
                        src="about:blank"
                        title="Panel kontrol perangkat edge"
                        class="w-full h-full border-0 block bg-surface"
                        referrerpolicy="no-referrer"
                        allow="camera; microphone; fullscreen"></iframe>
            </div>
        </div>
    </dialog>

    {{-- ───────────────────── Device telemetry modal ───────────────────── --}}
    <dialog x-ref="telemetryModal" x-show="telemetryDevice" x-cloak @close="closeTelemetry()"
            @click="$event.target === $el && closeTelemetry()"
            class="w-[min(760px,94vw)] max-h-[min(880px,92vh)] m-auto flex flex-col">

        {{-- Header --}}
        <div class="modal-head">
            <div class="flex items-center gap-xs min-w-0">
                <i data-lucide="activity" class="w-4 h-4 text-primary shrink-0"></i>
                <div class="min-w-0">
                    <h2 class="modal-title truncate">
                        <span>Status Telemetri</span>
                        <span class="font-mono text-[11.5px] font-normal text-ink-muted"
                              x-text="telemetryDevice ? '· ' + telemetryDevice.name : ''"></span>
                    </h2>
                </div>
            </div>
            <div class="flex items-center gap-xs shrink-0">
                <span class="badge"
                      :class="telemetryError
                          ? 'badge-danger'
                          : (telemetryLoading ? 'badge-muted' : 'badge-success')">
                    <span class="w-1.5 h-1.5 rounded-full"
                          :class="telemetryError ? 'bg-danger' : (telemetryLoading ? 'bg-ink-muted animate-pulse' : 'bg-success')"></span>
                    <span x-text="telemetryError ? 'OFFLINE' : (telemetryLoading ? 'POLLING' : 'LIVE')"></span>
                </span>
                <button type="button" @click="refreshTelemetry()" class="btn-icon"
                        aria-label="Muat ulang" title="Muat ulang">
                    <i data-lucide="refresh-cw" class="w-4 h-4" :class="{ 'animate-spin': telemetryLoading }"></i>
                </button>
                <button type="button" @click="closeTelemetry()" aria-label="Tutup" class="btn-icon">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        </div>

        {{-- Body --}}
        <div class="modal-body custom-scroll space-y-4">

            {{-- No IP configured --}}
            <div x-show="telemetryDevice && !telemetryDevice.ip_address" class="py-10 text-center">
                <div class="empty-state p-0">
                    <span class="empty-state-icon"><i data-lucide="wifi-off" class="w-4 h-4"></i></span>
                    <span class="empty-state-title">Alamat IP belum diatur</span>
                    <p class="empty-state-text">
                        Atur alamat IP perangkat pada menu Edit untuk membaca data telemetri.
                    </p>
                </div>
            </div>

            {{-- Error state --}}
            <div x-show="telemetryError" x-cloak class="alert alert-error">
                <i data-lucide="alert-circle" class="w-4 h-4 text-danger shrink-0 mt-0.5"></i>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-danger text-[12px] m-0">Koneksi Terputus</p>
                    <p class="text-[11.5px] text-ink-secondary m-0 mt-0.5 leading-relaxed" x-text="telemetryError"></p>
                </div>
            </div>

            {{-- Telemetry Content --}}
            <div x-show="telemetryDevice && telemetryDevice.ip_address && telemetry" class="space-y-4">

                {{-- Identity --}}
                <section>
                    <h3 class="text-[10.5px] font-semibold uppercase tracking-wider text-ink-muted mb-2">Identitas Perangkat</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Hostname</span>
                            <span class="text-[12px] font-mono text-ink font-medium mt-0.5 block truncate" x-text="telemetry.hostname || '—'"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Device Code</span>
                            <span class="text-[12px] font-mono text-ink font-medium mt-0.5 block truncate" x-text="telemetry.device_code || '—'"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Firmware</span>
                            <span class="text-[12px] font-mono text-ink font-medium mt-0.5 block" x-text="'v' + (telemetry.firmware_version || '—')"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Model</span>
                            <span class="text-[12px] font-mono text-ink font-medium mt-0.5 block truncate" x-text="telemetry.model_version || '—'"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Local IP</span>
                            <span class="text-[12px] font-mono text-ink font-medium mt-0.5 block" x-text="telemetry.local_ip || '—'"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Status Pairing</span>
                            <span class="text-[12px] font-mono font-medium mt-0.5 block"
                                  :class="telemetry.is_paired ? 'text-success' : 'text-danger'"
                                  x-text="telemetry.is_paired ? 'TERPASANG' : 'BELUM'"></span>
                        </div>
                    </div>
                </section>

                {{-- CPU --}}
                <section>
                    <div class="flex items-baseline justify-between mb-1.5">
                        <h3 class="text-[10.5px] font-semibold uppercase tracking-wider text-ink-muted m-0">CPU</h3>
                        <span class="text-[10.5px] font-mono text-ink-muted truncate max-w-[60%]" x-text="telemetry.cpu_model"></span>
                    </div>
                    <div class="border border-hairline rounded-sm bg-canvas-soft p-3 space-y-2.5">
                        <div>
                            <div class="flex items-baseline justify-between mb-1">
                                <span class="text-[11.5px] text-ink-muted">Penggunaan CPU</span>
                                <span class="text-[12px] font-mono font-medium text-ink tabular-nums"
                                      x-text="fmtPct(telemetry.cpu_usage_pct)"></span>
                            </div>
                            <div class="h-1.5 bg-hairline rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-500"
                                     :class="barColor(telemetry.cpu_usage_pct)"
                                     :style="'width:' + clampPct(telemetry.cpu_usage_pct) + '%'"></div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1 border-t border-hairline/60">
                            <div>
                                <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Cores</span>
                                <span class="text-[12px] font-mono text-ink mt-0.5 block" x-text="telemetry.cpu_cores"></span>
                            </div>
                            <div>
                                <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Suhu</span>
                                <span class="text-[12px] font-mono font-medium mt-0.5 block"
                                      :class="telemetry.cpu_temp_c >= 75 ? 'text-danger' : (telemetry.cpu_temp_c >= 65 ? 'text-warning' : 'text-ink')"
                                      x-text="telemetry.cpu_temp_c ? telemetry.cpu_temp_c.toFixed(1) + '°C' : '—'"></span>
                            </div>
                            <div>
                                <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Load 1m</span>
                                <span class="text-[12px] font-mono text-ink mt-0.5 block" x-text="telemetry.load_avg_1 ? telemetry.load_avg_1.toFixed(2) : '—'"></span>
                            </div>
                            <div>
                                <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Load 5/15m</span>
                                <span class="text-[12px] font-mono text-ink mt-0.5 block"
                                      x-text="(telemetry.load_avg_5 ? telemetry.load_avg_5.toFixed(2) : '—') + ' / ' + (telemetry.load_avg_15 ? telemetry.load_avg_15.toFixed(2) : '—')"></span>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- Memory --}}
                <section>
                    <h3 class="text-[10.5px] font-semibold uppercase tracking-wider text-ink-muted mb-1.5">Memori (RAM)</h3>
                    <div class="border border-hairline rounded-sm bg-canvas-soft p-3 space-y-2.5">
                        <div>
                            <div class="flex items-baseline justify-between mb-1">
                                <span class="text-[11.5px] text-ink-muted">RAM Sistem</span>
                                <span class="text-[12px] font-mono font-medium text-ink tabular-nums"
                                      x-text="telemetry.ram_used_mb + ' / ' + telemetry.ram_total_mb + ' MB (' + fmtPct(telemetry.ram_usage_pct) + ')'"></span>
                            </div>
                            <div class="h-1.5 bg-hairline rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-500"
                                     :class="barColor(telemetry.ram_usage_pct)"
                                     :style="'width:' + clampPct(telemetry.ram_usage_pct) + '%'"></div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2 pt-1 border-t border-hairline/60">
                            <div>
                                <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Engine RSS</span>
                                <span class="text-[12px] font-mono text-ink mt-0.5 block" x-text="telemetry.process_ram_mb + ' MB'"></span>
                            </div>
                            <div>
                                <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Engine CPU</span>
                                <span class="text-[12px] font-mono text-ink mt-0.5 block" x-text="fmtPct(telemetry.process_cpu_pct)"></span>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- Storage --}}
                <section>
                    <h3 class="text-[10.5px] font-semibold uppercase tracking-wider text-ink-muted mb-1.5">Penyimpanan</h3>
                    <div class="border border-hairline rounded-sm bg-canvas-soft p-3 space-y-2.5">
                        <div>
                            <div class="flex items-baseline justify-between mb-1">
                                <span class="text-[11.5px] text-ink-muted">Disk Sistem</span>
                                <span class="text-[12px] font-mono font-medium text-ink tabular-nums"
                                      x-text="telemetry.disk_used_mb + ' / ' + telemetry.disk_total_mb + ' MB (' + fmtPct(telemetry.disk_usage_pct) + ')'"></span>
                            </div>
                            <div class="h-1.5 bg-hairline rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-500"
                                     :class="barColor(telemetry.disk_usage_pct)"
                                     :style="'width:' + clampPct(telemetry.disk_usage_pct) + '%'"></div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1 border-t border-hairline/60">
                            <div>
                                <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Database</span>
                                <span class="text-[12px] font-mono text-ink mt-0.5 block" x-text="telemetry.db_size_mb + ' MB'"></span>
                            </div>
                            <div>
                                <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Templates</span>
                                <span class="text-[12px] font-mono text-ink mt-0.5 block" x-text="telemetry.template_count"></span>
                            </div>
                            <div>
                                <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Outbox</span>
                                <span class="text-[12px] font-mono font-medium mt-0.5 block"
                                      :class="telemetry.outbox_count > 0 ? 'text-warning' : 'text-success'"
                                      x-text="telemetry.outbox_count + ' pending'"></span>
                            </div>
                            <div>
                                <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Last Sync</span>
                                <span class="text-[12px] font-mono text-ink mt-0.5 block truncate"
                                      x-text="telemetry.last_sync_at ? telemetry.last_sync_at : '—'"></span>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- Network --}}
                <section>
                    <h3 class="text-[10.5px] font-semibold uppercase tracking-wider text-ink-muted mb-2">Jaringan</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Interface</span>
                            <span class="text-[12px] font-mono text-ink mt-0.5 block truncate" x-text="telemetry.network_interface || '—'"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">IP Jaringan</span>
                            <span class="text-[12px] font-mono text-ink mt-0.5 block truncate" x-text="telemetry.network_ip || '—'"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">RX (Diterima)</span>
                            <span class="text-[12px] font-mono text-ink mt-0.5 block" x-text="fmtBytes(telemetry.network_rx_bytes)"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">TX (Terkirim)</span>
                            <span class="text-[12px] font-mono text-ink mt-0.5 block" x-text="fmtBytes(telemetry.network_tx_bytes)"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft col-span-2">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">MAC Address</span>
                            <span class="text-[12px] font-mono text-ink mt-0.5 block truncate" x-text="telemetry.network_mac || '—'"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Wi-Fi SSID</span>
                            <span class="text-[12px] font-mono text-ink mt-0.5 block truncate"
                                  x-text="telemetry.wifi_ssid ? telemetry.wifi_ssid : '—'"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Sinyal Wi-Fi</span>
                            <span class="text-[12px] font-mono text-ink mt-0.5 block"
                                  x-text="telemetry.wifi_signal_dbm !== null ? telemetry.wifi_signal_dbm + ' dBm' : '—'"></span>
                        </div>
                    </div>
                </section>

                {{-- Hardware / System --}}
                <section>
                    <h3 class="text-[10.5px] font-semibold uppercase tracking-wider text-ink-muted mb-2">Sistem & Perangkat Keras</h3>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Sistem Operasi</span>
                            <span class="text-[12px] font-mono text-ink mt-0.5 block truncate" x-text="telemetry.os_name || '—'"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Kernel</span>
                            <span class="text-[12px] font-mono text-ink mt-0.5 block truncate" x-text="telemetry.kernel_version || '—'"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Arsitektur</span>
                            <span class="text-[12px] font-mono text-ink mt-0.5 block" x-text="telemetry.arch || '—'"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Uptime Sistem</span>
                            <span class="text-[12px] font-mono text-ink mt-0.5 block" x-text="fmtUptime(telemetry.uptime_system_sec)"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Uptime Engine</span>
                            <span class="text-[12px] font-mono text-ink mt-0.5 block" x-text="fmtUptime(telemetry.uptime_sec)"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Kamera</span>
                            <span class="text-[12px] font-mono mt-0.5 block truncate"
                                  :class="telemetry.is_streaming ? 'text-success font-medium' : 'text-ink'"
                                  x-text="telemetry.camera_device + ' · ' + telemetry.camera_resolution"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Serial</span>
                            <span class="text-[12px] font-mono font-medium mt-0.5 block"
                                  :class="telemetry.serial_connected ? 'text-success' : 'text-ink-muted'"
                                  x-text="telemetry.serial_connected ? 'CONNECTED' : 'NONE'"></span>
                        </div>
                        <div class="p-2.5 rounded-sm border border-hairline bg-canvas-soft">
                            <span class="text-[10px] uppercase tracking-wider font-semibold text-ink-muted block">Daya / Tegangan</span>
                            <span class="text-[12px] font-mono text-ink mt-0.5 block"
                                  x-text="telemetry.power_volts !== null ? telemetry.power_volts.toFixed(3) + ' V' : '—'"></span>
                        </div>
                    </div>

                    {{-- Throttle warning --}}
                    <div x-show="telemetry.throttled && telemetry.throttled !== 'OK'" x-cloak
                         class="mt-2.5 alert alert-warning">
                        <i data-lucide="alert-triangle" class="w-4 h-4 text-warning shrink-0 mt-0.5"></i>
                        <div>
                            <p class="font-semibold text-warning text-[12px] m-0">Throttling Terdeteksi</p>
                            <p class="text-[11.5px] font-mono text-ink-secondary m-0 mt-0.5" x-text="telemetry.throttled"></p>
                        </div>
                    </div>
                </section>
            </div>

            {{-- Loading skeleton --}}
            <div x-show="telemetryLoading && !telemetry && !telemetryError" x-cloak class="space-y-3">
                <div class="h-16 bg-canvas-soft rounded-sm border border-hairline animate-pulse"></div>
                <div class="h-24 bg-canvas-soft rounded-sm border border-hairline animate-pulse"></div>
                <div class="h-24 bg-canvas-soft rounded-sm border border-hairline animate-pulse"></div>
            </div>
        </div>

        {{-- Footer --}}
        <div class="modal-foot justify-between">
            <span class="text-[11px] font-mono text-ink-muted"
                  x-text="telemetryUpdatedAt ? 'Diperbarui pukul ' + telemetryUpdatedAt : 'Pembaruan otomatis tiap 5 dtk'"></span>
            <button type="button" @click="closeTelemetry()" class="btn btn-secondary">
                Tutup
            </button>
        </div>
    </dialog>
</div>
@endsection
