@extends('layouts.app')

@section('title', 'Tambah Anggota')
@section('page-title', 'Tambah Anggota')

@section('header-actions')
    <a href="{{ route('members.index') }}" class="btn btn-secondary btn-sm">← Kembali</a>
@endsection

@section('content')
<div class="card p-5" style="max-width: 540px;">
    <form method="POST" action="{{ route('members.store') }}" id="create-member-form">
        @csrf
        <div class="flex flex-col gap-4">

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="form-label" for="input-code">Kode / NIK / NIS *</label>
                    <input id="input-code" name="code" type="text" class="form-input"
                           value="{{ old('code') }}" required placeholder="mis. EMP-001">
                    @error('code')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="form-label" for="input-role">Peran</label>
                    <select id="input-role" name="role" class="form-input">
                        <option value="">— Pilih —</option>
                        @foreach(['employee' => 'Karyawan', 'student' => 'Siswa', 'teacher' => 'Guru', 'other' => 'Lainnya'] as $val => $lbl)
                            <option value="{{ $val }}" {{ old('role') === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="form-label" for="input-name">Nama Lengkap *</label>
                <input id="input-name" name="name" type="text" class="form-input"
                       value="{{ old('name') }}" required placeholder="mis. Budi Santoso">
                @error('name')<p class="form-error">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="form-label" for="input-organization">Organisasi</label>
                    <select id="input-organization" name="organization_id" class="form-input">
                        <option value="">— Tidak ada —</option>
                        @foreach($organizations as $org)
                            <option value="{{ $org->id }}" {{ old('organization_id') == $org->id ? 'selected' : '' }}>
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
                            <option value="{{ $group->id }}" {{ old('group_id') == $group->id ? 'selected' : '' }}>
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
                           value="{{ old('email') }}" placeholder="opsional">
                    @error('email')<p class="form-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="form-label" for="input-phone">No. Telepon</label>
                    <input id="input-phone" name="phone" type="tel" class="form-input"
                           value="{{ old('phone') }}" placeholder="opsional">
                </div>
            </div>

            <div class="flex gap-2 pt-1">
                <button type="submit" class="btn btn-primary" id="btn-submit-member">Simpan Anggota</button>
                <a href="{{ route('members.index') }}" class="btn btn-secondary">Batal</a>
            </div>
        </div>
    </form>
</div>
@endsection
