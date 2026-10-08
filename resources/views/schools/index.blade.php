@extends('layouts.app')
@section('title', 'Manajemen Organisasi')

@section('content')
<div x-data="schoolsData" @open-create-modal.window="$refs.createModal.showModal()">
    <div class="flex items-center justify-between mb-md">
        <h1 class="text-[18px] font-bold text-ink">Daftar Organisasi</h1>
        <button x-data @click="$dispatch('open-create-modal')" aria-label="Tambah Organisasi" class="btn btn-primary inline-flex items-center gap-xs">
            <i data-lucide="plus" class="w-4 h-4"></i> Tambah Organisasi
        </button>
    </div>

    <div class="card p-0 overflow-hidden">
        <div class="overflow-x-auto">
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
                            <td class="text-right whitespace-nowrap">
                                <button data-school="{{ json_encode($school) }}" @click="openEditSchool" aria-label="Edit Organisasi" class="text-[13px] text-primary font-medium border-none bg-transparent cursor-pointer mr-sm hover:underline inline-flex items-center gap-[4px]">
                                    <i data-lucide="edit" class="w-3 h-3"></i> Edit
                                </button>
                                <form action="{{ route('schools.destroy', $school) }}" method="POST" class="inline" @submit="confirmSubmit" data-confirm="Hapus organisasi ini?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" aria-label="Hapus Organisasi" class="btn-danger hover:underline inline-flex items-center gap-[4px] mr-sm">
                                        <i data-lucide="trash-2" class="w-3 h-3"></i> Hapus
                                    </button>
                                </form>
                                <button @click="toggleRow('classrooms-{{$school->id}}')" aria-label="Toggle Daftar Kelas" class="btn btn-utility py-1 px-2 text-xs">
                                    <i data-lucide="chevron-down" class="w-3 h-3"></i> Kelas ({{ $school->classrooms_count }})
                                </button>
                            </td>
                        </tr>
                        <tr id="classrooms-{{$school->id}}" x-show="openRows.includes('classrooms-{{$school->id}}')" x-cloak class="bg-surface-muted">
                            <td colspan="4" class="p-4 border-b border-hairline">
                                <div class="flex justify-between items-center mb-sm">
                                    <h4 class="font-semibold text-sm">Daftar Kelas / Grup</h4>
                                    <div class="flex gap-2">
                                        <button @click="openBulkEdit({{$school->id}})" class="btn btn-primary py-1 px-3 text-xs">
                                            <i data-lucide="clock" class="w-3 h-3"></i> Update Waktu Massal
                                        </button>
                                        <button @click="openCreateClassroom({{$school->id}})" class="btn btn-utility py-1 px-3 text-xs">
                                            <i data-lucide="plus" class="w-3 h-3"></i> Tambah Kelas
                                        </button>
                                    </div>
                                </div>
                                <div class="overflow-x-auto">
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
                                                    <td>{{ $classroom->late_tolerance_minutes ?? 'Off' }}</td>
                                                    <td class="text-right">
                                                        <button data-classroom="{{ json_encode($classroom) }}" @click="openEditClassroom" class="text-primary hover:underline">Edit</button>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
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
    </div>

    {{-- Create Modal --}}
    <dialog x-ref="createModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[400px] w-full backdrop:bg-black/20 shadow-xl m-auto">
        <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
            <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
                <i data-lucide="plus" class="w-4 h-4 text-primary"></i> Tambah Organisasi
            </h3>
            <button type="button" @click="$refs.createModal.close()" aria-label="Tutup Modal" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" action="{{ route('schools.store') }}">
            @csrf
            <div class="mb-sm">
                <label for="create_code" class="form-label">Kode *</label>
                <input type="text" id="create_code" name="code" value="{{ old('code') }}" required class="form-input">
                @error('code') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="mb-sm">
                <label for="create_name" class="form-label">Nama Organisasi *</label>
                <input type="text" id="create_name" name="name" value="{{ old('name') }}" required class="form-input">
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="mb-sm">
                <label for="create_description" class="form-label">Deskripsi</label>
                <textarea id="create_description" name="description" class="form-input" rows="3">{{ old('description') }}</textarea>
                @error('description') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-xs mt-md">
                <button type="button" @click="$refs.createModal.close()" class="btn btn-utility">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </dialog>

    {{-- Edit Modal --}}
    <dialog x-ref="editModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[400px] w-full backdrop:bg-black/20 shadow-xl m-auto">
        <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
            <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
                <i data-lucide="edit-3" class="w-4 h-4 text-primary"></i> Edit Organisasi
            </h3>
            <button type="button" @click="$refs.editModal.close()" aria-label="Tutup Modal" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" x-ref="editForm" action="">
            @csrf
            @method('PUT')
            <div class="mb-sm">
                <label for="edit_code" class="form-label">Kode *</label>
                <input type="text" id="edit_code" name="code" x-model="editSchoolData.code" required class="form-input">
                @error('code') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="mb-sm">
                <label for="edit_name" class="form-label">Nama Organisasi *</label>
                <input type="text" id="edit_name" name="name" x-model="editSchoolData.name" required class="form-input">
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="mb-sm">
                <label for="edit_description" class="form-label">Deskripsi</label>
                <textarea id="edit_description" name="description" x-model="editSchoolData.description" class="form-input" rows="3"></textarea>
                @error('description') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="mb-sm">
                <label for="edit_is_active" class="form-label">Status Aktif</label>
                <select id="edit_is_active" name="is_active" x-model="editSchoolData.is_active" class="form-select">
                    <option value="1">Aktif</option>
                    <option value="0">Tidak Aktif</option>
                </select>
                @error('is_active') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end gap-xs mt-md">
                <button type="button" @click="$refs.editModal.close()" class="btn btn-utility">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </dialog>

    {{-- Classroom Create Modal --}}
    <dialog x-ref="createClassroomModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[400px] w-full backdrop:bg-black/20 shadow-xl m-auto">
        <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
            <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
                <i data-lucide="plus" class="w-4 h-4 text-primary"></i> Tambah Kelas
            </h3>
            <button type="button" @click="$refs.createClassroomModal.close()" aria-label="Tutup Modal" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" action="{{ route('schools.classrooms.store') }}">
            @csrf
            <input type="hidden" name="school_id" x-model="createClassroomSchoolId">
            <div class="mb-sm">
                <label for="create_classroom_type" class="form-label">Tipe Grup *</label>
                <select id="create_classroom_type" name="type" required class="form-select">
                    <option value="class" @selected(old('type') == 'class')>Siswa (Kelas)</option>
                    <option value="staff" @selected(old('type') == 'staff')>Staf/Guru</option>
                </select>
                @error('type') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="mb-sm">
                <label for="create_classroom_name" class="form-label">Nama Kelas/Grup *</label>
                <input type="text" id="create_classroom_name" name="name" value="{{ old('name') }}" required class="form-input">
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="mb-sm">
                <label for="create_classroom_code" class="form-label">Kode (Opsional)</label>
                <input type="text" id="create_classroom_code" name="code" value="{{ old('code') }}" class="form-input">
                @error('code') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-sm mb-sm">
                <div>
                    <label for="create_classroom_start_time" class="form-label">Jam Masuk</label>
                    <input type="time" id="create_classroom_start_time" name="start_time" value="{{ old('start_time', '07:00') }}" required class="form-input">
                    @error('start_time') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="create_classroom_late" class="form-label">Toleransi (m)</label>
                    <input type="number" id="create_classroom_late" name="late_tolerance_minutes" min="0" max="120" value="{{ old('late_tolerance_minutes', 15) }}" class="form-input" placeholder="Kosong = Off">
                    @error('late_tolerance_minutes') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex justify-end gap-xs mt-md">
                <button type="button" @click="$refs.createClassroomModal.close()" class="btn btn-utility">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </dialog>

    {{-- Classroom Edit Modal --}}
    <dialog x-ref="editClassroomModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[400px] w-full backdrop:bg-black/20 shadow-xl m-auto">
        <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
            <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
                <i data-lucide="edit-2" class="w-4 h-4 text-primary"></i> Edit Kelas
            </h3>
            <button type="button" @click="$refs.editClassroomModal.close()" aria-label="Tutup Modal" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" x-ref="editClassroomForm" action="">
            @csrf
            @method('PUT')
            
            <div class="mb-sm">
                <label for="edit_classroom_type" class="form-label">Tipe Grup *</label>
                <select id="edit_classroom_type" name="type" x-model="editClassroomData.type" required class="form-select">
                    <option value="class">Siswa (Kelas)</option>
                    <option value="staff">Staf/Guru</option>
                </select>
                @error('type') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="mb-sm">
                <label for="edit_classroom_name" class="form-label">Nama Kelas/Grup *</label>
                <input type="text" id="edit_classroom_name" name="name" x-model="editClassroomData.name" required class="form-input">
                @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="mb-sm">
                <label for="edit_classroom_code" class="form-label">Kode</label>
                <input type="text" id="edit_classroom_code" name="code" x-model="editClassroomData.code" class="form-input">
                @error('code') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-sm mb-sm">
                <div>
                    <label for="edit_classroom_start_time" class="form-label">Jam Masuk</label>
                    <input type="time" id="edit_classroom_start_time" name="start_time" x-model="editClassroomData.start_time" required class="form-input">
                    @error('start_time') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="edit_classroom_late" class="form-label">Toleransi (m)</label>
                    <input type="number" id="edit_classroom_late" name="late_tolerance_minutes" x-model="editClassroomData.late_tolerance_minutes" min="0" max="120" class="form-input" placeholder="Kosong = Off">
                    @error('late_tolerance_minutes') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
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
                <button type="button" @click="$refs.editClassroomModal.close()" class="btn btn-utility">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </dialog>

    {{-- Classroom Bulk Edit Modal --}}
    <dialog x-ref="bulkEditClassroomModal" class="p-lg border border-hairline bg-surface rounded-lg max-w-[400px] w-full backdrop:bg-black/20 shadow-xl m-auto">
        <div class="flex justify-between items-center mb-md border-b border-hairline pb-xs">
            <h3 class="m-0 text-[16px] font-semibold flex items-center gap-xs">
                <i data-lucide="clock" class="w-4 h-4 text-primary"></i> Update Waktu Massal
            </h3>
            <button type="button" @click="$refs.bulkEditClassroomModal.close()" aria-label="Tutup Modal" class="border-none bg-transparent cursor-pointer text-ink-faint hover:text-ink">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" x-ref="bulkEditClassroomForm" action="">
            @csrf
            @method('PUT')
            
            <p class="text-sm text-ink-muted mb-md">
                Pengaturan ini akan diterapkan ke kelas/grup sesuai filter di organisasi ini.
            </p>

            <div class="mb-sm">
                <label for="bulk_type_filter" class="form-label">Terapkan pada</label>
                <select id="bulk_type_filter" name="type_filter" required class="form-select">
                    <option value="class">Hanya Kelas Siswa</option>
                    <option value="staff">Hanya Guru/Staf</option>
                    <option value="all">Semua Tipe Grup</option>
                </select>
                @error('type_filter') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-sm mb-md">
                <div>
                    <label for="bulk_start_time" class="form-label">Jam Masuk Serentak</label>
                    <input type="time" id="bulk_start_time" name="start_time" value="{{ old('start_time') }}" required class="form-input">
                    @error('start_time') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="bulk_late_tolerance" class="form-label">Toleransi (m)</label>
                    <input type="number" id="bulk_late_tolerance" name="late_tolerance_minutes" min="0" max="120" value="{{ old('late_tolerance_minutes') }}" class="form-input" placeholder="Kosong = Off">
                    @error('late_tolerance_minutes') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            
            <div class="p-sm bg-red-50 text-red-700 text-xs rounded border border-red-200 mb-md flex items-start gap-2">
                <input type="checkbox" name="confirm_retroactive" id="confirmRetroactive" x-model="confirmRetroactive" required class="mt-0.5 cursor-pointer">
                <label for="confirmRetroactive" class="cursor-pointer">
                    <strong>Saya mengerti</strong> bahwa ini akan mengubah perhitungan DTR sebelumnya untuk seluruh kelas di organisasi ini.
                </label>
            </div>

            <div class="flex justify-end gap-xs">
                <button type="button" @click="$refs.bulkEditClassroomModal.close()" class="btn btn-utility">Batal</button>
                <button type="submit" :disabled="!confirmRetroactive" class="btn btn-danger disabled:opacity-50 disabled:cursor-not-allowed">Terapkan Massal</button>
            </div>
        </form>
    </dialog>
</div>
@endsection
