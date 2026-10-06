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
                        <button onclick='editSchool(@json($school))' class="text-[13px] text-primary font-medium border-none bg-transparent cursor-pointer mr-sm hover:underline inline-flex items-center gap-[4px]">
                            <i data-lucide="edit" class="w-3 h-3"></i> Edit
                        </button>
                        <form action="{{ route('schools.destroy', $school) }}" method="POST" class="inline" onsubmit="return confirm('Hapus organisasi ini?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-danger hover:underline inline-flex items-center gap-[4px] mr-sm">
                                <i data-lucide="trash-2" class="w-3 h-3"></i> Hapus
                            </button>
                        </form>
                        <button onclick="toggleRow('classrooms-{{$school->id}}')" class="btn btn-utility py-1 px-2 text-xs">
                            <i data-lucide="chevron-down" class="w-3 h-3"></i> Kelas ({{ $school->classrooms_count }})
                        </button>
                    </td>
                </tr>
                <tr id="classrooms-{{$school->id}}" class="hidden bg-surface-muted">
                    <td colspan="4" class="p-4 border-b border-hairline">
                        <div class="flex justify-between items-center mb-sm">
                            <h4 class="font-semibold text-sm">Daftar Kelas / Grup</h4>
                            <div class="flex gap-2">
                                <button onclick="bulkEditClassrooms({{$school->id}})" class="btn btn-primary py-1 px-3 text-xs">
                                    <i data-lucide="clock" class="w-3 h-3"></i> Update Waktu Massal
                                </button>
                                <button onclick="createClassroom({{$school->id}})" class="btn btn-utility py-1 px-3 text-xs">
                                    <i data-lucide="plus" class="w-3 h-3"></i> Tambah Kelas
                                </button>
                            </div>
                        </div>
                        <table class="w-full text-left text-sm">
                            <thead>
                                <tr class="text-ink-muted">
                                    <th class="py-1">Nama</th>
                                    <th>Kode</th>
                                    <th>Tipe</th>
                                    <th>Masuk</th>
                                    <th>Toleransi (m)</th>
                                    <th class="text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($school->classrooms as $classroom)
                                    <tr class="border-t border-hairline border-dashed">
                                        <td class="py-2">{{ $classroom->name }}</td>
                                        <td>{{ $classroom->code ?? '-' }}</td>
                                        <td>
                                            @if($classroom->type === 'staff')
                                                <span class="bg-blue-100 text-blue-800 text-xs px-2 py-0.5 rounded">Staf</span>
                                            @else
                                                <span class="bg-green-100 text-green-800 text-xs px-2 py-0.5 rounded">Siswa</span>
                                            @endif
                                        </td>
                                        <td>{{ substr($classroom->start_time, 0, 5) }}</td>
                                        <td>{{ $classroom->late_tolerance_minutes }}</td>
                                        <td class="text-right">
                                            <button onclick='editClassroom(@json($classroom))' class="text-primary hover:underline">Edit</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
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

{{-- Classroom Create Modal --}}
<dialog id="createClassroomModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[400px] w-full backdrop:bg-black/20 shadow-xl m-auto">
    <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
        <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
            <i data-lucide="plus" class="w-4 h-4 text-primary"></i> Tambah Kelas
        </h3>
        <button onclick="document.getElementById('createClassroomModal').close()" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <form method="POST" action="{{ route('schools.classrooms.store') }}">
        @csrf
        <input type="hidden" name="school_id" id="createClassroomSchoolId">
        <div class="mb-sm">
            <label class="form-label">Tipe Grup *</label>
            <select name="type" required class="form-select">
                <option value="class">Siswa (Kelas)</option>
                <option value="staff">Staf/Guru</option>
            </select>
        </div>
        <div class="mb-sm">
            <label class="form-label">Nama Kelas/Grup *</label>
            <input type="text" name="name" required class="form-input">
        </div>
        <div class="mb-sm">
            <label class="form-label">Kode (Opsional)</label>
            <input type="text" name="code" class="form-input">
        </div>
        <div class="grid grid-cols-2 gap-sm mb-sm">
            <div>
                <label class="form-label">Jam Masuk</label>
                <input type="time" name="start_time" value="07:00" required class="form-input">
            </div>
            <div>
                <label class="form-label">Toleransi (Menit)</label>
                <input type="number" name="late_tolerance_minutes" min="0" max="120" value="15" required class="form-input">
            </div>
        </div>
        <div class="flex justify-end gap-xs mt-md">
            <button type="button" onclick="document.getElementById('createClassroomModal').close()" class="btn btn-utility">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </div>
    </form>
</dialog>

{{-- Classroom Edit Modal --}}
<dialog id="editClassroomModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[400px] w-full backdrop:bg-black/20 shadow-xl m-auto">
    <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
        <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
            <i data-lucide="edit-2" class="w-4 h-4 text-primary"></i> Edit Kelas
        </h3>
        <button onclick="document.getElementById('editClassroomModal').close()" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <form method="POST" id="editClassroomForm" action="">
        @csrf
        @method('PUT')
        
        <div class="mb-sm">
            <label class="form-label">Tipe Grup *</label>
            <select name="type" id="editClassroomType" required class="form-select">
                <option value="class">Siswa (Kelas)</option>
                <option value="staff">Staf/Guru</option>
            </select>
        </div>
        <div class="mb-sm">
            <label class="form-label">Nama Kelas/Grup *</label>
            <input type="text" name="name" id="editClassroomName" required class="form-input">
        </div>
        <div class="mb-sm">
            <label class="form-label">Kode</label>
            <input type="text" name="code" id="editClassroomCode" class="form-input">
        </div>
        <div class="grid grid-cols-2 gap-sm mb-sm">
            <div>
                <label class="form-label">Jam Masuk</label>
                <input type="time" name="start_time" id="editClassroomStartTime" required class="form-input">
            </div>
            <div>
                <label class="form-label">Toleransi (Menit)</label>
                <input type="number" name="late_tolerance_minutes" id="editClassroomLate" min="0" max="120" required class="form-input">
            </div>
        </div>
        
        <div class="p-sm bg-red-50 text-red-700 text-xs rounded border border-red-200 mb-md mt-sm flex items-start gap-2">
            <i data-lucide="alert-triangle" class="w-4 h-4 mt-0.5 shrink-0"></i>
            <div>
                <strong>⚠️ Peringatan Retroaktif:</strong>
                Perubahan waktu masuk atau toleransi akan berlaku surut pada perhitungan status DTR masa lalu. Pastikan perubahan disengaja.
            </div>
        </div>

        <div class="flex justify-end gap-xs">
            <button type="button" onclick="document.getElementById('editClassroomModal').close()" class="btn btn-utility">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
        </div>
    </form>
</dialog>

{{-- Classroom Bulk Edit Modal --}}
<dialog id="bulkEditClassroomModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[400px] w-full backdrop:bg-black/20 shadow-xl m-auto">
    <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
        <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
            <i data-lucide="clock" class="w-4 h-4 text-primary"></i> Update Waktu Massal
        </h3>
        <button onclick="document.getElementById('bulkEditClassroomModal').close()" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
            <i data-lucide="x" class="w-4 h-4"></i>
        </button>
    </div>
    <form method="POST" id="bulkEditClassroomForm" action="">
        @csrf
        @method('PUT')
        
        <p class="text-sm text-ink-muted mb-md">
            Pengaturan ini akan diterapkan ke kelas/grup sesuai filter di organisasi ini.
        </p>

        <div class="mb-sm">
            <label class="form-label">Terapkan pada</label>
            <select name="type_filter" required class="form-select">
                <option value="class">Hanya Kelas Siswa</option>
                <option value="staff">Hanya Guru/Staf</option>
                <option value="all">Semua Tipe Grup</option>
            </select>
        </div>

        <div class="grid grid-cols-2 gap-sm mb-md">
            <div>
                <label class="form-label">Jam Masuk Serentak</label>
                <input type="time" name="start_time" required class="form-input">
            </div>
            <div>
                <label class="form-label">Toleransi (Menit)</label>
                <input type="number" name="late_tolerance_minutes" min="0" max="120" required class="form-input">
            </div>
        </div>
        
        <div class="p-sm bg-red-50 text-red-700 text-xs rounded border border-red-200 mb-md flex items-start gap-2">
            <input type="checkbox" name="confirm_retroactive" id="confirmRetroactive" required class="mt-0.5 cursor-pointer">
            <label for="confirmRetroactive" class="cursor-pointer">
                <strong>Saya mengerti</strong> bahwa ini akan mengubah perhitungan DTR sebelumnya untuk seluruh kelas di organisasi ini.
            </label>
        </div>

        <div class="flex justify-end gap-xs">
            <button type="button" onclick="document.getElementById('bulkEditClassroomModal').close()" class="btn btn-utility">Batal</button>
            <button type="submit" id="bulkSubmitBtn" class="btn btn-danger" disabled>Terapkan Massal</button>
        </div>
    </form>
</dialog>

<script>
    function toggleRow(id) {
        const row = document.getElementById(id);
        if (row.classList.contains('hidden')) {
            row.classList.remove('hidden');
        } else {
            row.classList.add('hidden');
        }
    }

    function editSchool(school) {
        document.getElementById('editForm').action = '/schools/' + school.id;
        document.getElementById('editCode').value = school.code;
        document.getElementById('editName').value = school.name;
        document.getElementById('editDesc').value = school.description || '';
        document.getElementById('editActive').value = school.is_active ? '1' : '0';
        document.getElementById('editModal').showModal();
    }

    function createClassroom(schoolId) {
        document.getElementById('createClassroomSchoolId').value = schoolId;
        document.getElementById('createClassroomModal').showModal();
    }

    function editClassroom(classroom) {
        document.getElementById('editClassroomForm').action = '/schools/classrooms/' + classroom.id;
        document.getElementById('editClassroomType').value = classroom.type;
        document.getElementById('editClassroomName').value = classroom.name;
        document.getElementById('editClassroomCode').value = classroom.code || '';
        // Extract HH:mm from HH:mm:ss if needed
        document.getElementById('editClassroomStartTime').value = classroom.start_time.substring(0, 5);
        document.getElementById('editClassroomLate').value = classroom.late_tolerance_minutes;
        document.getElementById('editClassroomModal').showModal();
    }

    function bulkEditClassrooms(schoolId) {
        document.getElementById('bulkEditClassroomForm').action = '/schools/' + schoolId + '/classrooms/timing';
        document.getElementById('confirmRetroactive').checked = false;
        document.getElementById('bulkSubmitBtn').disabled = true;
        document.getElementById('bulkEditClassroomModal').showModal();
    }

    document.getElementById('confirmRetroactive').addEventListener('change', function() {
        document.getElementById('bulkSubmitBtn').disabled = !this.checked;
    });
</script>

@endsection
