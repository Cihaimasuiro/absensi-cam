@extends('layouts.app')
@section('title', 'Manajemen Organisasi')

@section('header-actions')
    <button type="button"
            id="btnCreateSchool"
            data-modal-id="createSchoolModal"
            @click="openModalByData"
            onclick="window.openModal('createSchoolModal')"
            class="btn btn-primary btn-sm">
        <i data-lucide="plus" class="w-3.5 h-3.5"></i> Tambah
    </button>
@endsection

@section('content')
<div x-data="schoolsData">

    <section class="card p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th class="w-14">ID</th>
                        <th>Kode</th>
                        <th>Nama Organisasi</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($schools ?? [] as $school)
                        <tr>
                            <td class="text-ink-faint">{{ $school->id }}</td>
                            <td class="font-mono text-ink-muted">{{ $school->code }}</td>
                            <td class="font-medium text-ink">{{ $school->name }}</td>
                            <td class="text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button" data-school="{{ json_encode($school) }}" @click="openEditSchool"
                                            class="btn btn-secondary btn-sm" aria-label="Edit organisasi {{ $school->name }}">
                                        <i data-lucide="edit" class="w-3 h-3"></i> Edit
                                    </button>
                                    <form action="{{ route('schools.destroy', $school) }}" method="POST" class="inline"
                                          @submit="confirmSubmit" data-confirm="Hapus organisasi {{ $school->name }}?">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-danger-outline btn-sm" aria-label="Hapus organisasi {{ $school->name }}">
                                            <i data-lucide="trash-2" class="w-3 h-3"></i> Hapus
                                        </button>
                                    </form>
                                    <button type="button" @click="toggleRow('classrooms-{{ $school->id }}')"
                                            class="btn btn-secondary btn-sm" aria-label="Tampilkan daftar kelas">
                                        <i data-lucide="chevron-down" class="w-3 h-3"
                                           :class="{ 'rotate-180': openRows.includes('classrooms-{{ $school->id }}') }"></i>
                                        Kelas ({{ $school->classrooms_count }})
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr id="classrooms-{{ $school->id }}" x-show="openRows.includes('classrooms-{{ $school->id }}')"
                            x-cloak class="bg-canvas-soft">
                            <td colspan="4" class="p-md">
                                <div class="flex flex-wrap justify-between items-center gap-xs mb-sm">
                                    <h3 class="font-semibold text-[12.5px] text-ink m-0">Daftar Kelas / Grup</h3>
                                    <div class="flex gap-xs">
                                        <button @click="openBulkEdit({{ $school->id }})" class="btn btn-secondary btn-sm">
                                            <i data-lucide="clock" class="w-3 h-3"></i> Update Waktu Massal
                                        </button>
                                        <button @click="openCreateClassroom({{ $school->id }})" class="btn btn-secondary btn-sm">
                                            <i data-lucide="plus" class="w-3 h-3"></i> Tambah Kelas
                                        </button>
                                    </div>
                                </div>

                                <div class="overflow-x-auto rounded-md border border-hairline bg-canvas">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Nama</th>
                                                <th>Kode</th>
                                                <th>Tipe</th>
                                                <th>Masuk</th>
                                                <th>Toleransi</th>
                                                <th class="text-right">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse($school->classrooms as $classroom)
                                                <tr>
                                                    <td class="font-medium text-ink">{{ $classroom->name }}</td>
                                                    <td class="font-mono text-ink-muted">{{ $classroom->code ?? '—' }}</td>
                                                    <td>
                                                        @if($classroom->type === 'staff')
                                                            <span class="badge badge-primary">Staf</span>
                                                        @else
                                                            <span class="badge badge-success">Siswa</span>
                                                        @endif
                                                    </td>
                                                    <td class="font-mono">{{ substr($classroom->start_time, 0, 5) }}</td>
                                                    <td class="font-mono">{{ $classroom->late_tolerance_minutes ?? 'Off' }}</td>
                                                    <td class="text-right">
                                                        <button type="button" data-classroom="{{ json_encode($classroom) }}"
                                                                @click="openEditClassroom" class="btn btn-secondary btn-sm">
                                                            <i data-lucide="edit" class="w-3 h-3"></i> Edit
                                                        </button>
                                                    </td>
                                                </tr>
                                            @empty
                                                <tr>
                                                    <td colspan="6" class="text-center text-ink-faint py-md">
                                                        Belum ada kelas pada organisasi ini.
                                                    </td>
                                                </tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4">
                                <div class="empty-state">
                                    <span class="empty-state-icon"><i data-lucide="building" class="w-4 h-4"></i></span>
                                    <span class="empty-state-title">Belum ada organisasi</span>
                                    <p class="empty-state-text">Tambahkan organisasi untuk mulai mengelompokkan kelas dan anggota.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ───────────────────────── Create organization ────────────────────── --}}
    <dialog id="createSchoolModal" x-ref="createModal" @click="$event.target === $el && $el.close()"
            class="w-[min(420px,92vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="plus" class="w-4 h-4 text-primary"></i> Tambah Organisasi</h2>
            <button type="button" @click="$refs.createModal.close()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" action="{{ route('schools.store') }}">
            @csrf
            <div class="modal-body flex flex-col gap-sm">
                <div>
                    <label for="create_code" class="form-label">Kode <span class="req">*</span></label>
                    <input type="text" id="create_code" name="code" value="{{ old('code') }}"
                           class="form-input @error('code') is-invalid @enderror" required>
                    @error('code')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="create_name" class="form-label">Nama Organisasi <span class="req">*</span></label>
                    <input type="text" id="create_name" name="name" value="{{ old('name') }}"
                           class="form-input @error('name') is-invalid @enderror" required>
                    @error('name')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="create_description" class="form-label">Deskripsi</label>
                    <textarea id="create_description" name="description" rows="3"
                              class="form-textarea @error('description') is-invalid @enderror">{{ old('description') }}</textarea>
                    @error('description')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" @click="$refs.createModal.close()" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </dialog>

    {{-- ───────────────────────── Edit organization ──────────────────────── --}}
    <dialog x-ref="editModal" @click="$event.target === $refs.editModal && $refs.editModal.close()"
            class="w-[min(420px,92vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="edit-3" class="w-4 h-4 text-primary"></i> Edit Organisasi</h2>
            <button type="button" @click="$refs.editModal.close()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" x-ref="editForm" action="">
            @csrf @method('PUT')
            <div class="modal-body flex flex-col gap-sm">
                <div>
                    <label for="edit_code" class="form-label">Kode <span class="req">*</span></label>
                    <input type="text" id="edit_code" name="code" x-model="editSchoolData.code"
                           class="form-input @error('code') is-invalid @enderror" required>
                    @error('code')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="edit_name" class="form-label">Nama Organisasi <span class="req">*</span></label>
                    <input type="text" id="edit_name" name="name" x-model="editSchoolData.name"
                           class="form-input @error('name') is-invalid @enderror" required>
                    @error('name')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="edit_description" class="form-label">Deskripsi</label>
                    <textarea id="edit_description" name="description" x-model="editSchoolData.description"
                              rows="3" class="form-textarea @error('description') is-invalid @enderror"></textarea>
                    @error('description')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="edit_is_active" class="form-label">Status Aktif</label>
                    <select id="edit_is_active" name="is_active" x-model="editSchoolData.is_active"
                            class="form-select @error('is_active') is-invalid @enderror">
                        <option value="1">Aktif</option>
                        <option value="0">Tidak aktif</option>
                    </select>
                    @error('is_active')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" @click="$refs.editModal.close()" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </dialog>

    {{-- ───────────────────────── Create classroom ───────────────────────── --}}
    <dialog x-ref="createClassroomModal" @click="$event.target === $refs.createClassroomModal && $refs.createClassroomModal.close()"
            class="w-[min(420px,92vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="plus" class="w-4 h-4 text-primary"></i> Tambah Kelas</h2>
            <button type="button" @click="$refs.createClassroomModal.close()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" action="{{ route('schools.classrooms.store') }}">
            @csrf
            <input type="hidden" name="school_id" x-model="createClassroomSchoolId">
            <div class="modal-body flex flex-col gap-sm">
                <div>
                    <label for="create_classroom_type" class="form-label">Tipe Grup <span class="req">*</span></label>
                    <select id="create_classroom_type" name="type" required class="form-select">
                        <option value="class" @selected(old('type') == 'class')>Siswa (Kelas)</option>
                        <option value="staff" @selected(old('type') == 'staff')>Staf / Guru</option>
                    </select>
                    @error('type')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="create_classroom_name" class="form-label">Nama Kelas / Grup <span class="req">*</span></label>
                    <input type="text" id="create_classroom_name" name="name" value="{{ old('name') }}" required class="form-input">
                    @error('name')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="create_classroom_code" class="form-label">Kode</label>
                    <input type="text" id="create_classroom_code" name="code" value="{{ old('code') }}" class="form-input">
                    @error('code')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="grid grid-cols-2 gap-sm">
                    <div>
                        <label for="create_classroom_start_time" class="form-label">Jam Masuk</label>
                        <input type="time" id="create_classroom_start_time" name="start_time"
                               value="{{ old('start_time', '07:00') }}" required class="form-input">
                        @error('start_time')<span class="form-error">{{ $message }}</span>@enderror
                    </div>
                    <div>
                        <label for="create_classroom_late" class="form-label">Toleransi (menit)</label>
                        <input type="number" id="create_classroom_late" name="late_tolerance_minutes"
                               min="0" max="120" value="{{ old('late_tolerance_minutes', 15) }}"
                               class="form-input" placeholder="Kosong = Off">
                        @error('late_tolerance_minutes')<span class="form-error">{{ $message }}</span>@enderror
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" @click="$refs.createClassroomModal.close()" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </dialog>

    {{-- ───────────────────────── Edit classroom ─────────────────────────── --}}
    <dialog x-ref="editClassroomModal" @click="$event.target === $refs.editClassroomModal && $refs.editClassroomModal.close()"
            class="w-[min(420px,92vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="edit-2" class="w-4 h-4 text-primary"></i> Edit Kelas</h2>
            <button type="button" @click="$refs.editClassroomModal.close()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" x-ref="editClassroomForm" action="">
            @csrf @method('PUT')
            <div class="modal-body flex flex-col gap-sm">
                <div>
                    <label for="edit_classroom_type" class="form-label">Tipe Grup <span class="req">*</span></label>
                    <select id="edit_classroom_type" name="type" x-model="editClassroomData.type" required class="form-select">
                        <option value="class">Siswa (Kelas)</option>
                        <option value="staff">Staf / Guru</option>
                    </select>
                    @error('type')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="edit_classroom_name" class="form-label">Nama Kelas / Grup <span class="req">*</span></label>
                    <input type="text" id="edit_classroom_name" name="name" x-model="editClassroomData.name" required class="form-input">
                    @error('name')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="edit_classroom_code" class="form-label">Kode</label>
                    <input type="text" id="edit_classroom_code" name="code" x-model="editClassroomData.code" class="form-input">
                    @error('code')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="grid grid-cols-2 gap-sm">
                    <div>
                        <label for="edit_classroom_start_time" class="form-label">Jam Masuk</label>
                        <input type="time" id="edit_classroom_start_time" name="start_time"
                               x-model="editClassroomData.start_time" required class="form-input">
                        @error('start_time')<span class="form-error">{{ $message }}</span>@enderror
                    </div>
                    <div>
                        <label for="edit_classroom_late" class="form-label">Toleransi (menit)</label>
                        <input type="number" id="edit_classroom_late" name="late_tolerance_minutes"
                               x-model="editClassroomData.late_tolerance_minutes" min="0" max="120"
                               class="form-input" placeholder="Kosong = Off">
                        @error('late_tolerance_minutes')<span class="form-error">{{ $message }}</span>@enderror
                    </div>
                </div>
                <div class="alert alert-warning">
                    <i data-lucide="alert-triangle" class="w-4 h-4 shrink-0 mt-px"></i>
                    <span><strong class="font-semibold">Peringatan retroaktif:</strong> perubahan jam masuk atau toleransi berlaku surut pada perhitungan status DTR masa lalu.</span>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" @click="$refs.editClassroomModal.close()" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </dialog>

    {{-- ───────────────────────── Bulk timing update ─────────────────────── --}}
    <dialog x-ref="bulkEditClassroomModal" @click="$event.target === $refs.bulkEditClassroomModal && $refs.bulkEditClassroomModal.close()"
            class="w-[min(420px,92vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="clock" class="w-4 h-4 text-primary"></i> Update Waktu Massal</h2>
            <button type="button" @click="$refs.bulkEditClassroomModal.close()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" x-ref="bulkEditClassroomForm" action="">
            @csrf @method('PUT')
            <div class="modal-body flex flex-col gap-sm">
                <p class="text-[12px] text-ink-muted m-0">
                    Pengaturan ini diterapkan ke kelas/grup sesuai filter di organisasi ini.
                </p>
                <div>
                    <label for="bulk_type_filter" class="form-label">Terapkan pada</label>
                    <select id="bulk_type_filter" name="type_filter" required class="form-select">
                        <option value="class">Hanya kelas siswa</option>
                        <option value="staff">Hanya guru / staf</option>
                        <option value="all">Semua tipe grup</option>
                    </select>
                    @error('type_filter')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="grid grid-cols-2 gap-sm">
                    <div>
                        <label for="bulk_start_time" class="form-label">Jam Masuk Serentak</label>
                        <input type="time" id="bulk_start_time" name="start_time"
                               value="{{ old('start_time') }}" required class="form-input">
                        @error('start_time')<span class="form-error">{{ $message }}</span>@enderror
                    </div>
                    <div>
                        <label for="bulk_late_tolerance" class="form-label">Toleransi (menit)</label>
                        <input type="number" id="bulk_late_tolerance" name="late_tolerance_minutes"
                               min="0" max="120" value="{{ old('late_tolerance_minutes') }}"
                               class="form-input" placeholder="Kosong = Off">
                        @error('late_tolerance_minutes')<span class="form-error">{{ $message }}</span>@enderror
                    </div>
                </div>
                <div class="alert alert-error">
                    <input type="checkbox" name="confirm_retroactive" id="confirmRetroactive"
                           x-model="confirmRetroactive" required
                           class="mt-0.5 w-3.5 h-3.5 rounded border-danger/40 text-danger focus:ring-2 focus:ring-danger/30 cursor-pointer shrink-0">
                    <label for="confirmRetroactive" class="cursor-pointer">
                        <strong class="font-semibold">Saya mengerti</strong> bahwa ini akan mengubah perhitungan DTR sebelumnya untuk seluruh kelas di organisasi ini.
                    </label>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" @click="$refs.bulkEditClassroomModal.close()" class="btn btn-secondary">Batal</button>
                <button type="submit" :disabled="!confirmRetroactive" class="btn btn-danger">Terapkan Massal</button>
            </div>
        </form>
    </dialog>
</div>
@endsection
