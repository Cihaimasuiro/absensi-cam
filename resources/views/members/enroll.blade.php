@extends('layouts.app')

@section('title', 'Enrollment Wajah — '.$member->name)
@section('page-title', 'Enrollment Wajah')

@section('header-actions')
    <a href="{{ route('members.index') }}" class="btn btn-secondary btn-sm">← Anggota</a>
@endsection

@section('content')
{{-- ════════════════════════════════════════
     Enrollment page — ported from Facenox FaceCapture.tsx (upload mode only)
     Camera live capture is handled by the edge terminal, not the admin panel.
     Upload a photo → POST → queued face-embed job generates embedding.
     ════════════════════════════════════════ --}}

<div class="flex gap-4" x-data="{
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
    }
}">

    {{-- Member info card --}}
    <div style="width: 220px; flex-shrink: 0;">
        <div class="card p-4 flex flex-col gap-3">
            {{-- Avatar --}}
            <div style="width: 64px; height: 64px; border-radius: 9999px;
                        background: var(--bg-tertiary); border: 1px solid var(--border-primary);
                        display: flex; align-items: center; justify-content: center;
                        font-size: 1.5rem; font-weight: 700; color: var(--accent);">
                {{ strtoupper(substr($member->name, 0, 1)) }}
            </div>

            <div>
                <p style="font-weight: 600; font-size: 0.875rem; color: var(--text-primary);">{{ $member->name }}</p>
                <p style="font-size: 0.75rem; color: var(--text-muted);">{{ $member->code }}</p>
                @if($member->group)
                    <p style="font-size: 0.75rem; color: var(--text-tertiary); margin-top: 0.25rem;">{{ $member->group->name }}</p>
                @endif
            </div>

            {{-- Current enrollment status --}}
            <div>
                @if($member->faceTemplate)
                    <span class="badge badge-success">
                        <span class="dot-online"></span> Sudah Terdaftar
                    </span>
                    <p style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 0.25rem;">
                        Upload baru akan menggantikan template lama.
                    </p>
                @else
                    <span class="badge badge-muted">Belum Terdaftar</span>
                @endif
            </div>

            {{-- Consent warning --}}
            @if(! $member->hasActiveConsent())
                <div class="alert alert-warning" style="font-size: 0.6875rem;">
                    ⚠️ Persetujuan biometrik belum tercatat. Pastikan consent sudah diberikan sebelum enrollment (UU PDP).
                </div>
            @endif
        </div>
    </div>

    {{-- Upload panel — ported from FaceCapture.tsx UploadArea --}}
    <div class="flex-1">
        <div class="card p-4">
            <div class="page-header">
                <span style="font-size: 0.8125rem; font-weight: 600;">Upload Foto Wajah</span>
                <span style="font-size: 0.6875rem; color: var(--text-muted);">Format: JPG / PNG / WEBP · Maks 5MB</span>
            </div>

            <form id="enroll-form" action="{{ route('members.enroll.store', $member) }}" method="POST" enctype="multipart/form-data">
                @csrf

                {{-- Upload drop area --}}
                <label for="face-file-input" id="upload-area"
                       style="display: flex; flex-direction: column; align-items: center; justify-content: center;
                              gap: 0.75rem; height: 200px; border: 2px dashed var(--border-primary); border-radius: 8px;
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
                            <p style="font-size: 0.8125rem; color: var(--text-tertiary);">Klik atau seret foto ke sini</p>
                        </div>
                    </template>
                    <template x-if="preview">
                        <img :src="preview" style="max-height: 180px; max-width: 100%; border-radius: 6px; object-fit: contain;">
                    </template>
                </label>

                <input id="face-file-input" name="photo" type="file" accept="image/*"
                       class="sr-only" @change="handleFile">

                <div class="flex gap-2 mt-3">
                    <button type="button" class="btn btn-primary flex-1" id="btn-enroll-upload"
                            :disabled="!preview || uploading"
                            @click="submit">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span x-text="uploading ? 'Memproses…' : 'Daftarkan Wajah'"></span>
                    </button>
                    <button type="button" class="btn btn-secondary" @click="preview = null" :disabled="!preview">
                        Hapus
                    </button>
                </div>
            </form>

            {{-- Result feedback --}}
            <template x-if="result">
                <div class="mt-3" :class="result.success ? 'alert alert-success' : 'alert alert-error'"
                     x-text="result.message"></div>
            </template>

            {{-- Info note --}}
            <p style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 0.875rem; line-height: 1.6;">
                Foto akan diproses oleh <code style="color: var(--accent); font-size: 0.625rem;">face-embed</code> (antrian background).
                Foto asli tidak disimpan — hanya embedding terenkripsi yang dipertahankan.
                Template akan tersebar ke perangkat terkait pada sinkronisasi berikutnya.
            </p>
        </div>
    </div>
</div>
@endsection
