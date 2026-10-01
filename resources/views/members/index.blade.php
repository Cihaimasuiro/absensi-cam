@extends('layouts.app')

@section('title', 'Anggota')
@section('page-title', 'Anggota')

@section('header-actions')
    <button @click="$dispatch('open-import-modal')" class="btn btn-secondary btn-sm" id="btn-import-member">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
        </svg>
        Import Data
    </button>
    <button @click="$dispatch('open-modal', { member: null })" class="btn btn-primary btn-sm" id="btn-add-member">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Tambah Anggota
    </button>
@endsection

@section('content')
{{-- ════════════════════════════════════════
     Search + filter bar
     ════════════════════════════════════════ --}}
<div class="card p-3 mb-3">
    <form id="member-filter-form" method="GET" action="{{ route('members.index') }}"
          x-data="{ search: '{{ request('search') }}', filter: '{{ request('filter', 'all') }}', groupId: '{{ request('group_id') }}' }">

        <div class="flex flex-wrap gap-2 items-center">
            <div class="relative flex-1 min-w-[180px]">
                <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5" style="color: var(--text-muted);"
                     fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0016.803 15.803z"/>
                </svg>
                <input id="member-search" type="text" name="search" x-model="search"
                       placeholder="Cari nama atau kode…" class="search-input"
                       @keydown.enter="$el.form.submit()">
            </div>

            <select name="group_id" x-model="groupId" @change="$el.form.submit()"
                    class="form-input" style="width: auto; min-width: 140px;" id="member-group-filter">
                <option value="">Semua Grup</option>
                @foreach($groups as $group)
                    <option value="{{ $group->id }}" {{ request('group_id') == $group->id ? 'selected' : '' }}>
                        {{ $group->name }}
                    </option>
                @endforeach
            </select>

            @foreach(['all' => 'Semua', 'enrolled' => 'Terdaftar', 'non-enrolled' => 'Belum Daftar', 'inactive' => 'Nonaktif'] as $val => $label)
                <button type="submit" name="filter" value="{{ $val }}"
                        class="btn btn-sm {{ request('filter', 'all') === $val ? 'btn-primary' : 'btn-secondary' }}"
                        id="filter-{{ $val }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </form>
</div>

{{-- ════════════════════════════════════════
     Members table
     ════════════════════════════════════════ --}}
<div class="card">
    @if($members->isEmpty())
        <div style="text-align: center; padding: 3rem 0; color: var(--text-muted); font-size: 0.8125rem;">
            Tidak ada anggota yang sesuai filter.
        </div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 36px;"></th>
                    <th>Nama</th>
                    <th>Kode</th>
                    <th>Grup</th>
                    <th>Peran</th>
                    <th>Wajah</th>
                    <th>Status</th>
                    <th style="width: 100px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($members as $member)
                    <tr id="member-row-{{ $member->id }}">
                        <td>
                            <div style="width: 28px; height: 28px; border-radius: 9999px;
                                        background: var(--bg-tertiary); border: 1px solid var(--border-primary);
                                        display: flex; align-items: center; justify-content: center;
                                        font-size: 0.625rem; font-weight: 700; color: var(--accent);">
                                {{ strtoupper(substr($member->name, 0, 1)) }}
                            </div>
                        </td>
                        <td style="color: var(--text-primary); font-weight: 500;">{{ $member->name }}</td>
                        <td style="font-family: monospace; font-size: 0.75rem; color: var(--text-tertiary);">
                            {{ $member->code }}
                        </td>
                        <td style="color: var(--text-muted); font-size: 0.75rem;">
                            {{ $member->group?->name ?? '—' }}
                        </td>
                        <td>
                            @if($member->role)
                                <span class="badge badge-muted">{{ $member->role }}</span>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td>
                            @if($member->faceTemplate)
                                <span class="badge badge-success">
                                    <span class="dot-online"></span> Terdaftar
                                </span>
                            @else
                                <span class="badge badge-muted">Belum</span>
                            @endif
                        </td>
                        <td>
                            @if($member->is_active)
                                <span class="badge badge-accent">Aktif</span>
                            @else
                                <span class="badge badge-danger">Nonaktif</span>
                            @endif
                        </td>
                        <td>
                            <div class="flex gap-1">
                                <button type="button" @click="$dispatch('open-enroll-modal', { member: {{ json_encode($member->only('id', 'name', 'code')) }}, group_name: '{{ $member->group?->name ?? '' }}', hasFaceTemplate: {{ $member->faceTemplate ? 'true' : 'false' }}, hasConsent: {{ $member->hasActiveConsent() ? 'true' : 'false' }} })" class="btn btn-xs btn-secondary" title="Enroll Wajah">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                                </button>
                                <button type="button" @click="$dispatch('open-modal', { member: {{ json_encode($member->only('id', 'name', 'code', 'role', 'email', 'phone', 'group_id', 'organization_id', 'is_active')) }}, has_consent: {{ $member->hasActiveConsent() ? 'true' : 'false' }} })" class="btn btn-xs btn-secondary" title="Edit">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/></svg>
                                </button>
                                <form method="POST" action="{{ route('members.destroy', $member) }}" onsubmit="return confirm('Hapus anggota {{ addslashes($member->name) }}?')" style="display:inline-block;">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-xs btn-danger" style="padding: 0 4px;" title="Hapus">×</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($members->hasPages())
            <div class="px-4 py-3" style="border-top: 1px solid var(--border-primary);">
                {{ $members->links() }}
            </div>
        @endif
    @endif
</div>

{{-- ════════════════════════════════════════
     Create/Edit Modal
     ════════════════════════════════════════ --}}
<div x-data="{ 
        show: {{ $errors->any() ? 'true' : 'false' }}, 
        isEdit: {{ old('id') ? 'true' : 'false' }}, 
        member: {
            id: '{{ old('id') }}', code: '{{ old('code') }}', name: '{{ old('name') }}',
            role: '{{ old('role') }}', organization_id: '{{ old('organization_id') }}',
            group_id: '{{ old('group_id') }}', email: '{{ old('email') }}', 
            phone: '{{ old('phone') }}', is_active: '{{ old('is_active', 1) }}',
            has_consent: false
        }
     }" 
     @open-modal.window="show = true; isEdit = !!$event.detail.member; member = $event.detail.member || {is_active: 1, has_consent: false}; if($event.detail.member) { member.has_consent = $event.detail.member.has_consent }"
     x-show="show" 
     style="display: none;" 
     class="modal-overlay">
    
    <div class="modal-panel" @click.outside="show = false">
        <h3 class="font-semibold mb-4" x-text="isEdit ? 'Edit Anggota' : 'Tambah Anggota'"></h3>
        
        <form method="POST" :action="isEdit ? '{{ url('members') }}/' + member.id : '{{ route('members.store') }}'">
            @csrf
            <template x-if="isEdit"><input type="hidden" name="_method" value="PUT"></template>
            <input type="hidden" name="id" x-model="member.id">

            <div class="flex flex-col gap-3">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="form-label">Kode / NIK / NIS *</label>
                        <input name="code" type="text" class="form-input" x-model="member.code" required>
                        @error('code')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label">Peran</label>
                        <select name="role" class="form-input" x-model="member.role">
                            <option value="">— Pilih —</option>
                            <option value="employee">Karyawan</option>
                            <option value="student">Siswa</option>
                            <option value="teacher">Guru</option>
                            <option value="other">Lainnya</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="form-label">Nama Lengkap *</label>
                    <input name="name" type="text" class="form-input" x-model="member.name" required>
                    @error('name')<p class="form-error">{{ $message }}</p>@enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="form-label">Organisasi</label>
                        <select name="organization_id" class="form-input" x-model="member.organization_id">
                            <option value="">— Tidak ada —</option>
                            @foreach($organizations as $org)
                                <option value="{{ $org->id }}">{{ $org->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Grup / Kelas</label>
                        <select name="group_id" class="form-input" x-model="member.group_id">
                            <option value="">— Tidak ada —</option>
                            @foreach($groups as $group)
                                <option value="{{ $group->id }}">{{ $group->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="form-label">Email</label>
                        <input name="email" type="email" class="form-input" x-model="member.email">
                        @error('email')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label">No. Telepon</label>
                        <input name="phone" type="tel" class="form-input" x-model="member.phone">
                    </div>
                </div>

                <div x-show="isEdit">
                    <label class="form-label">Status Akun</label>
                    <select name="is_active" class="form-input" x-model="member.is_active">
                        <option value="1">Aktif</option>
                        <option value="0">Nonaktif</option>
                    </select>
                </div>

                <div class="mt-2" style="display: flex; align-items: center; gap: 0.5rem;">
                    <input type="hidden" name="has_consent" value="0">
                    <input type="checkbox" name="has_consent" value="1" id="has_consent_checkbox" x-model="member.has_consent" style="accent-color: var(--accent); width: 1rem; height: 1rem;">
                    <label for="has_consent_checkbox" style="font-size: 0.8125rem; font-weight: 500; cursor: pointer;">
                        Saya mengonfirmasi bahwa anggota ini telah menyetujui data biometrik wajahnya diproses.
                    </label>
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="submit" class="btn btn-primary" x-text="isEdit ? 'Simpan' : 'Tambah'"></button>
                    <button type="button" class="btn btn-secondary" @click="show = false">Batal</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- ════════════════════════════════════════
     Enrollment Modal
     ════════════════════════════════════════ --}}
<div x-data="{
        show: false,
        member: null,
        group_name: '',
        hasFaceTemplate: false,
        hasConsent: false,
        source: 'upload',
        preview: null,
        uploading: false,
        result: null,
        
        handleFile(event) {
            const file = event.target.files[0]
            if (!file) return
            const reader = new FileReader()
            reader.onload = e => this.preview = e.target.result
            reader.readAsDataURL(file)
        },
        
        async submit() {
            this.uploading = true
            this.result = null
            const form = document.getElementById('enroll-form')
            const data = new FormData(form)
            
            const res = await fetch(form.action, {
                method: 'POST',
                body: data,
                headers: { 
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json'
                }
            })
            
            let json;
            try {
                json = await res.json()
                if (!res.ok) {
                    json = { success: false, message: json.message || 'Terjadi kesalahan' }
                }
            } catch (e) {
                json = { success: false, message: 'Server error' }
            }
            
            this.uploading = false
            this.result = json
            if(json.success) {
                this.hasFaceTemplate = true
                setTimeout(() => window.location.reload(), 1500)
            }
        },
        
        closeModal() {
            this.show = false
            this.preview = null
            this.result = null
            if(document.getElementById('face-file-input')) {
                document.getElementById('face-file-input').value = ''
            }
        }
     }"
     @open-enroll-modal.window="
        show = true; 
        member = $event.detail.member;
        group_name = $event.detail.group_name;
        hasFaceTemplate = $event.detail.hasFaceTemplate;
        hasConsent = $event.detail.hasConsent;
        preview = null;
        result = null;
     "
     x-show="show" 
     style="display: none;" 
     class="modal-overlay">
    
    <div class="modal-panel" @click.outside="closeModal" style="max-width: 600px; width: 100%;">
        <div class="flex justify-between items-center mb-4">
            <h3 class="font-semibold">Enrollment Wajah</h3>
            <button type="button" class="btn btn-xs btn-secondary" style="padding: 0 4px;" @click="closeModal">×</button>
        </div>
        
        <div class="flex flex-col md:flex-row gap-4" x-show="member">
            {{-- Member info card --}}
            <div style="width: 220px; flex-shrink: 0;">
                <div class="p-4 flex flex-col gap-3" style="background: var(--bg-secondary); border-radius: 8px;">
                    {{-- Avatar --}}
                    <div style="width: 64px; height: 64px; border-radius: 9999px;
                                background: var(--bg-tertiary); border: 1px solid var(--border-primary);
                                display: flex; align-items: center; justify-content: center;
                                font-size: 1.5rem; font-weight: 700; color: var(--accent);">
                        <span x-text="member ? member.name.substring(0,1).toUpperCase() : ''"></span>
                    </div>

                    <div>
                        <p style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);" x-text="member?.name"></p>
                        <p style="font-size: 0.75rem; color: var(--text-muted);" x-text="member?.code"></p>
                        <p style="font-size: 0.75rem; color: var(--text-tertiary); margin-top: 0.25rem;" x-text="group_name" x-show="group_name"></p>
                    </div>

                    {{-- Current enrollment status --}}
                    <div>
                        <template x-if="hasFaceTemplate">
                            <div>
                                <span class="badge badge-success"><span class="dot-online"></span> Sudah Terdaftar</span>
                                <p style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 0.25rem;">
                                    Upload baru akan mengganti template.
                                </p>
                            </div>
                        </template>
                        <template x-if="!hasFaceTemplate">
                            <span class="badge badge-muted">Belum Terdaftar</span>
                        </template>
                    </div>

                    {{-- Consent warning --}}
                    <template x-if="!hasConsent">
                        <div class="alert alert-warning" style="font-size: 0.6875rem; padding: 0.5rem;">
                            ⚠️ Persetujuan biometrik belum tercatat.
                        </div>
                    </template>
                </div>
            </div>

            {{-- Upload panel --}}
            <div class="flex-1">
                <div class="page-header" style="margin-bottom: 1rem; border: none; padding: 0;">
                    <span style="font-size: 0.8125rem; font-weight: 600;">Upload Foto Wajah</span>
                    <span style="font-size: 0.6875rem; color: var(--text-muted); display: block;">Format: JPG/PNG/WEBP · Maks 5MB</span>
                </div>

                <form id="enroll-form" :action="'{{ url('members') }}/' + (member?.id || '') + '/enroll'" method="POST" enctype="multipart/form-data">
                    @csrf
                    
                    {{-- Upload drop area --}}
                    <label for="face-file-input" id="upload-area"
                           style="display: flex; flex-direction: column; align-items: center; justify-content: center;
                                  gap: 0.75rem; height: 180px; border: 2px dashed var(--border-primary); border-radius: 8px;
                                  cursor: pointer; transition: border-color 0.15s;"
                           @dragover.prevent="$el.style.borderColor='var(--accent)'"
                           @dragleave="$el.style.borderColor='var(--border-primary)'"
                           @drop.prevent="handleFile({ target: { files: $event.dataTransfer.files } })">

                        <template x-if="!preview">
                            <div style="text-align: center;">
                                <svg class="h-8 w-8 mx-auto mb-2" style="color: var(--text-muted);"
                                     fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                                </svg>
                                <p style="font-size: 0.8125rem; color: var(--text-tertiary);">Klik atau seret foto</p>
                            </div>
                        </template>
                        <template x-if="preview">
                            <img :src="preview" style="max-height: 160px; max-width: 100%; border-radius: 6px; object-fit: contain;">
                        </template>
                    </label>

                    <input id="face-file-input" name="photo" type="file" accept="image/*" class="sr-only" @change="handleFile">

                    <div class="flex gap-2 mt-3">
                        <button type="button" class="btn btn-primary flex-1"
                                :disabled="!preview || uploading"
                                @click="submit">
                            <span x-text="uploading ? 'Memproses…' : 'Simpan Wajah'"></span>
                        </button>
                        <button type="button" class="btn btn-secondary" @click="preview = null" :disabled="!preview">
                            Hapus
                        </button>
                    </div>
                </form>

                {{-- Result feedback --}}
                <template x-if="result">
                    <div class="mt-3" style="padding: 0.5rem; font-size: 0.75rem;" :class="result.success ? 'alert alert-success' : 'alert alert-error'" x-text="result.message"></div>
                </template>
            </div>
        </div>
    </div>
</div>

{{-- ════════════════════════════════════════
     Import Modal
     ════════════════════════════════════════ --}}
<div x-data="{ show: false, uploading: false }"
     @open-import-modal.window="show = true"
     x-show="show" 
     style="display: none;" 
     class="modal-overlay">
    
    <div class="modal-panel" @click.outside="if(!uploading) show = false" style="max-width: 400px; width: 100%;">
        <div class="flex justify-between items-center mb-4">
            <h3 class="font-semibold">Import Anggota</h3>
            <button type="button" class="btn btn-xs btn-secondary" style="padding: 0 4px;" @click="if(!uploading) show = false">×</button>
        </div>
        
        <form method="POST" action="{{ route('members.import') }}" enctype="multipart/form-data" @submit="uploading = true">
            @csrf
            <div class="flex flex-col gap-3">
                <div>
                    <label class="form-label">File Excel/CSV</label>
                    <input name="file" type="file" class="form-input" accept=".csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/vnd.ms-excel" required>
                    <p style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 0.25rem;">
                        Kolom yang didukung: code (wajib), name (wajib), role, email, phone.
                    </p>
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="submit" class="btn btn-primary" :disabled="uploading">
                        <span x-text="uploading ? 'Mengimpor...' : 'Mulai Import'"></span>
                    </button>
                    <button type="button" class="btn btn-secondary" @click="show = false" :disabled="uploading">Batal</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
