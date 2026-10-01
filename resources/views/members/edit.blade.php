@extends('layouts.app')

@section('title', 'Edit — '.$member->name)
@section('page-title', 'Edit Anggota')

@section('header-actions')
    <a href="{{ route('members.show', $member) }}" class="btn btn-secondary btn-sm">← Detail</a>
@endsection

@section('content')
<div class="flex gap-4" style="align-items: flex-start;">

    {{-- ── Edit form ── --}}
    <div class="card p-5" style="max-width: 540px; flex: 1;">
        <form method="POST" action="{{ route('members.update', $member) }}" id="edit-member-form">
            @csrf
            @method('PUT')
            <div class="flex flex-col gap-4">

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="form-label" for="input-code">Kode / NIK / NIS *</label>
                        <input id="input-code" name="code" type="text" class="form-input"
                               value="{{ old('code', $member->code) }}" required placeholder="mis. EMP-001">
                        @error('code')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="input-role">Peran</label>
                        <select id="input-role" name="role" class="form-input">
                            <option value="">— Pilih —</option>
                            @foreach(['employee' => 'Karyawan', 'student' => 'Siswa', 'teacher' => 'Guru', 'other' => 'Lainnya'] as $val => $lbl)
                                <option value="{{ $val }}" {{ old('role', $member->role) === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label class="form-label" for="input-name">Nama Lengkap *</label>
                    <input id="input-name" name="name" type="text" class="form-input"
                           value="{{ old('name', $member->name) }}" required placeholder="mis. Budi Santoso">
                    @error('name')<p class="form-error">{{ $message }}</p>@enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="form-label" for="input-organization">Organisasi</label>
                        <select id="input-organization" name="organization_id" class="form-input">
                            <option value="">— Tidak ada —</option>
                            @foreach($organizations as $org)
                                <option value="{{ $org->id }}" {{ old('organization_id', $member->organization_id) == $org->id ? 'selected' : '' }}>
                                    {{ $org->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="input-group">Grup / Kelas</label>
                        <select id="input-group" name="group_id" class="form-input">
                            <option value="">— Tidak ada —</option>
                            @foreach($groups as $group)
                                <option value="{{ $group->id }}" {{ old('group_id', $member->group_id) == $group->id ? 'selected' : '' }}>
                                    {{ $group->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="form-label" for="input-email">Email</label>
                        <input id="input-email" name="email" type="email" class="form-input"
                               value="{{ old('email', $member->email) }}" placeholder="opsional">
                        @error('email')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="form-label" for="input-phone">No. Telepon</label>
                        <input id="input-phone" name="phone" type="tel" class="form-input"
                               value="{{ old('phone', $member->phone) }}" placeholder="opsional">
                    </div>
                </div>

                {{-- Active toggle — not shown on create, but critical on edit --}}
                <div>
                    <label class="form-label" for="input-active">Status Akun</label>
                    <select id="input-active" name="is_active" class="form-input">
                        <option value="1" {{ old('is_active', $member->is_active) == 1 ? 'selected' : '' }}>Aktif</option>
                        <option value="0" {{ old('is_active', $member->is_active) == 0 ? 'selected' : '' }}>Nonaktif</option>
                    </select>
                    <p style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 0.25rem;">
                        Anggota nonaktif tidak akan disertakan dalam sinkronisasi perangkat.
                    </p>
                </div>

                <div class="flex gap-2 pt-1">
                    <button type="submit" class="btn btn-primary" id="btn-save-member">Simpan Perubahan</button>
                    <a href="{{ route('members.show', $member) }}" class="btn btn-secondary">Batal</a>
                </div>
            </div>
        </form>
    </div>

    {{-- ── Danger zone ── --}}
    <div class="card p-4" style="width: 240px; flex-shrink: 0;">
        <p style="font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.75rem;">Zona Berbahaya</p>

        <p style="font-size: 0.6875rem; color: var(--text-muted); margin-bottom: 0.75rem; line-height: 1.5;">
            Menghapus anggota akan menghapus template wajah mereka dan mengirimkan tombstone ke semua perangkat terdaftar.
        </p>

        <form method="POST" action="{{ route('members.destroy', $member) }}" id="delete-member-form"
              onsubmit="return confirm('Hapus anggota {{ addslashes($member->name) }}? Tindakan ini tidak dapat dibatalkan.')">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger w-full" id="btn-delete-member">
                Hapus Anggota
            </button>
        </form>
    </div>
</div>
@endsection
