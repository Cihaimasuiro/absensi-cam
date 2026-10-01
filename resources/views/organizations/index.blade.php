@extends('layouts.app')

@section('title', 'Organisasi')
@section('page-title', 'Organisasi, Grup & Cabang')

@section('header-actions')
    <button class="btn btn-primary btn-sm" id="btn-add-org"
            @click="document.getElementById('org-modal').style.display='flex'">
        + Organisasi
    </button>
@endsection

@section('content')

{{-- ════════════════════════════════════════
     Organizations table
     ════════════════════════════════════════ --}}
<div class="card mb-4">
    <div class="px-4 py-3" style="border-bottom: 1px solid var(--border-primary);">
        <span style="font-size: 0.8125rem; font-weight: 600; color: var(--text-secondary);">Organisasi</span>
    </div>
    @if($organizations->isEmpty())
        <div style="text-align: center; padding: 2rem; color: var(--text-muted); font-size: 0.8125rem;">
            Belum ada organisasi. Tambahkan satu untuk memulai.
        </div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Kode</th>
                    <th>Anggota</th>
                    <th>Grup</th>
                    <th>Cabang</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($organizations as $org)
                    <tr id="org-row-{{ $org->id }}">
                        <td style="font-weight: 500; color: var(--text-primary);">{{ $org->name }}</td>
                        <td style="font-family: monospace; font-size: 0.75rem; color: var(--text-tertiary);">{{ $org->code ?? '—' }}</td>
                        <td>{{ $org->members_count }}</td>
                        <td>{{ $org->groups_count }}</td>
                        <td>{{ $org->branches_count }}</td>
                        <td>
                            @if($org->is_active)
                                <span class="badge badge-success">Aktif</span>
                            @else
                                <span class="badge badge-muted">Nonaktif</span>
                            @endif
                        </td>
                        <td>
                            <button class="btn btn-xs btn-secondary" id="btn-add-group-{{ $org->id }}"
                                    @click="document.getElementById('group-modal-org').value='{{ $org->id }}'; document.getElementById('group-modal').style.display='flex'">
                                + Grup
                            </button>
                            <button class="btn btn-xs btn-secondary" id="btn-add-branch-{{ $org->id }}"
                                    @click="document.getElementById('branch-modal-org').value='{{ $org->id }}'; document.getElementById('branch-modal').style.display='flex'">
                                + Cabang
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

{{-- ════════════════════════════════════════
     Modals
     ════════════════════════════════════════ --}}

{{-- Add Organization --}}
<div id="org-modal" style="display: none;" class="modal-overlay" @click.self="$el.style.display='none'">
    <div class="modal-panel">
        <h2 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 1rem;">Tambah Organisasi</h2>
        <form method="POST" action="{{ route('organizations.store') }}" id="org-form">
            @csrf
            <div class="flex flex-col gap-3">
                <div>
                    <label class="form-label" for="org-name">Nama *</label>
                    <input id="org-name" name="name" type="text" class="form-input" required placeholder="mis. SMA Negeri 1">
                </div>
                <div>
                    <label class="form-label" for="org-code">Kode (opsional)</label>
                    <input id="org-code" name="code" type="text" class="form-input" placeholder="mis. SMAN1">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary flex-1" id="btn-save-org">Simpan</button>
                    <button type="button" class="btn btn-secondary" @click="document.getElementById('org-modal').style.display='none'">Batal</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Add Group --}}
<div id="group-modal" style="display: none;" class="modal-overlay" @click.self="$el.style.display='none'">
    <div class="modal-panel">
        <h2 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 1rem;">Tambah Grup / Kelas</h2>
        <form method="POST" action="{{ route('organizations.groups.store') }}" id="group-form">
            @csrf
            <input type="hidden" name="organization_id" id="group-modal-org">
            <div class="flex flex-col gap-3">
                <div>
                    <label class="form-label" for="group-name">Nama *</label>
                    <input id="group-name" name="name" type="text" class="form-input" required placeholder="mis. Kelas 12-A">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="form-label" for="group-code">Kode</label>
                        <input id="group-code" name="code" type="text" class="form-input" placeholder="mis. 12A">
                    </div>
                    <div>
                        <label class="form-label" for="group-type">Tipe</label>
                        <select id="group-type" name="type" class="form-input">
                            <option value="class">Kelas</option>
                            <option value="department">Departemen</option>
                            <option value="division">Divisi</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="form-label" for="group-start-time">Jam Masuk</label>
                    <input id="group-start-time" name="class_start_time" type="time" class="form-input" value="08:00">
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary flex-1" id="btn-save-group">Simpan</button>
                    <button type="button" class="btn btn-secondary" @click="document.getElementById('group-modal').style.display='none'">Batal</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Add Branch --}}
<div id="branch-modal" style="display: none;" class="modal-overlay" @click.self="$el.style.display='none'">
    <div class="modal-panel">
        <h2 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 1rem;">Tambah Cabang / Lokasi</h2>
        <form method="POST" action="{{ route('organizations.branches.store') }}" id="branch-form">
            @csrf
            <input type="hidden" name="organization_id" id="branch-modal-org">
            <div class="flex flex-col gap-3">
                <div>
                    <label class="form-label" for="branch-name">Nama *</label>
                    <input id="branch-name" name="name" type="text" class="form-input" required placeholder="mis. Gedung Utama">
                </div>
                <div>
                    <label class="form-label" for="branch-code">Kode * <span style="color: var(--text-muted); font-weight: 400;">(unik)</span></label>
                    <input id="branch-code" name="code" type="text" class="form-input" required placeholder="mis. JKT-01">
                </div>
                <div>
                    <label class="form-label" for="branch-timezone">Zona Waktu</label>
                    <select id="branch-timezone" name="timezone" class="form-input">
                        <option value="Asia/Jakarta">WIB (Asia/Jakarta)</option>
                        <option value="Asia/Makassar">WITA (Asia/Makassar)</option>
                        <option value="Asia/Jayapura">WIT (Asia/Jayapura)</option>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="btn btn-primary flex-1" id="btn-save-branch">Simpan</button>
                    <button type="button" class="btn btn-secondary" @click="document.getElementById('branch-modal').style.display='none'">Batal</button>
                </div>
            </div>
        </form>
    </div>
</div>

@endsection
