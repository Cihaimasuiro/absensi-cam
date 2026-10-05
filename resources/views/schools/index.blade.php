@extends('layouts.app')
@section('title', 'Manajemen Organisasi')

@section('header-actions')
    <button onclick="document.getElementById('createModal').showModal()" class="btn btn-primary">
        <i data-lucide="plus" class="w-4 h-4"></i> Tambah Organisasi
    </button>
@endsection

@section('content')
<div class="card p-0 overflow-hidden">
    <table class="data-table">
        <thead>
            <tr>
                <th class="w-12">ID</th>
                <th>Kode</th>
                <th>Nama Organisasi</th>
                <th class="text-right">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($schools ?? [] as $school)
                <tr>
                    <td class="text-ink-faint">{{ $school->id }}</td>
                    <td class="font-mono text-[12px] text-ink-muted">{{ $school->code }}</td>
                    <td class="font-medium text-ink">{{ $school->name }}</td>
                    <td class="text-right">
                        <button onclick='editSchool(@json($school))' class="text-[13px] text-primary font-medium border-none bg-transparent cursor-pointer mr-sm hover:underline inline-flex items-center gap-[4px]">
                            <i data-lucide="edit" class="w-3 h-3"></i> Edit
                        </button>
                        <form action="{{ route('schools.destroy', $school) }}" method="POST" class="inline" onsubmit="return confirm('Hapus organisasi ini?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-danger hover:underline inline-flex items-center gap-[4px]">
                                <i data-lucide="trash-2" class="w-3 h-3"></i> Hapus
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="text-center text-ink-faint p-xxl">
                        <div class="flex flex-col items-center justify-center gap-sm">
                            <i data-lucide="building" class="w-8 h-8 opacity-50"></i>
                            Belum ada organisasi terdaftar.
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Create Modal --}}
<dialog id="createModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[400px] w-full backdrop:bg-black/20 shadow-xl m-auto">
    <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
        <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
            <i data-lucide="plus" class="w-4 h-4 text-primary"></i> Tambah Organisasi
        </h3>
        <button onclick="document.getElementById('createModal').close()" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <form method="POST" action="{{ route('schools.store') }}">
        @csrf
        <div class="mb-sm">
            <label class="form-label">Kode *</label>
            <input type="text" name="code" required class="form-input">
        </div>
        <div class="mb-sm">
            <label class="form-label">Nama Organisasi *</label>
            <input type="text" name="name" required class="form-input">
        </div>
        <div class="mb-sm">
            <label class="form-label">Deskripsi</label>
            <textarea name="description" class="form-input" rows="3"></textarea>
        </div>
        <div class="flex justify-end gap-xs mt-md">
            <button type="button" onclick="document.getElementById('createModal').close()" class="btn btn-utility">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
    </form>
</dialog>

{{-- Edit Modal --}}
<dialog id="editModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[400px] w-full backdrop:bg-black/20 shadow-xl m-auto">
    <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
        <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
            <i data-lucide="edit-3" class="w-4 h-4 text-primary"></i> Edit Organisasi
        </h3>
        <button onclick="document.getElementById('editModal').close()" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <form method="POST" id="editForm" action="">
        @csrf
        @method('PUT')
        <div class="mb-sm">
            <label class="form-label">Kode *</label>
            <input type="text" id="editCode" name="code" required class="form-input">
        </div>
        <div class="mb-sm">
            <label class="form-label">Nama Organisasi *</label>
            <input type="text" id="editName" name="name" required class="form-input">
        </div>
        <div class="mb-sm">
            <label class="form-label">Deskripsi</label>
            <textarea id="editDesc" name="description" class="form-input" rows="3"></textarea>
        </div>
        <div class="mb-sm">
            <label class="form-label">Status Aktif</label>
            <select id="editActive" name="is_active" class="form-select">
                <option value="1">Aktif</option>
                <option value="0">Tidak Aktif</option>
            </select>
        </div>
        <div class="flex justify-end gap-xs mt-md">
            <button type="button" onclick="document.getElementById('editModal').close()" class="btn btn-utility">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
        </div>
    </form>
</dialog>

<script>
    function editSchool(school) {
        document.getElementById('editForm').action = '/schools/' + school.id;
        document.getElementById('editCode').value = school.code;
        document.getElementById('editName').value = school.name;
        document.getElementById('editDesc').value = school.description || '';
        document.getElementById('editActive').value = school.is_active ? '1' : '0';
        document.getElementById('editModal').showModal();
    }
</script>

@endsection
