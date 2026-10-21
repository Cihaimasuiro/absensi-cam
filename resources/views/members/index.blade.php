@extends('layouts.app')
@section('title', $isRoster ? 'Data Anggota' : 'Pendaftaran Wajah')

@section('header-actions')
    <button type="button"
            id="btnBulkEnrollMember"
            data-modal-id="bulkEnrollMemberModal"
            @click="openModalByData"
            onclick="window.openModal('bulkEnrollMemberModal')"
            class="btn btn-secondary btn-sm">
        <i data-lucide="scan-face" class="w-3.5 h-3.5"></i> Daftarkan Massal
    </button>
    <button type="button"
            id="btnImportMember"
            data-modal-id="importMemberModal"
            @click="openModalByData"
            onclick="window.openModal('importMemberModal')"
            class="btn btn-secondary btn-sm">
        <i data-lucide="upload" class="w-3.5 h-3.5"></i> Impor
    </button>
    <button type="button"
            id="btnCreateMember"
            data-modal-id="createMemberModal"
            @click="openModalByData"
            onclick="window.openModal('createMemberModal')"
            class="btn btn-primary btn-sm">
        <i data-lucide="user-plus" class="w-3.5 h-3.5"></i> Tambah
    </button>
@endsection

@section('content')
<div x-data="membersData">

    @if(! $isRoster)
    {{-- ───────────────────────────── Stat tiles ─────────────────────────── --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-sm mb-md">
        <div class="card stat-tile">
            <span class="stat-tile-label"><i data-lucide="users" class="w-3 h-3"></i> Total Anggota</span>
            <span class="stat-tile-value">{{ number_format($totalMembers ?? 0, 0, ',', '.') }}</span>
            <span class="stat-tile-hint">Seluruh data terdaftar</span>
        </div>
        <div class="card stat-tile">
            <span class="stat-tile-label"><i data-lucide="scan-face" class="w-3 h-3"></i> Wajah Terdaftar</span>
            <span class="stat-tile-value text-success">{{ number_format($totalEnrolled ?? 0, 0, ',', '.') }}</span>
            <span class="stat-tile-hint">Siap dikenali mesin</span>
        </div>
        <div class="card stat-tile">
            <span class="stat-tile-label"><i data-lucide="user-minus" class="w-3 h-3"></i> Belum Ada Wajah</span>
            <span class="stat-tile-value text-warning">{{ number_format($unassignedEnrolled ?? 0, 0, ',', '.') }}</span>
            <span class="stat-tile-hint">Perlu pendaftaran biometrik</span>
        </div>
        <div class="card stat-tile">
            <span class="stat-tile-label"><i data-lucide="users-round" class="w-3 h-3"></i> Tanpa Kelas</span>
            <span class="stat-tile-value">{{ number_format($unassignedCount ?? 0, 0, ',', '.') }}</span>
            <span class="stat-tile-hint">Belum masuk rombel</span>
        </div>
    </div>
    @endif

    @if(! $isRoster)
    {{-- ═══════════════════════ Classroom directory ═════════════════════════ --}}
    <section class="card p-0 overflow-hidden">
        <div class="px-md py-sm border-b border-hairline flex flex-wrap items-center justify-between gap-xs">
            <div class="flex items-center gap-xs">
                <i data-lucide="layers" class="w-3.5 h-3.5 text-primary"></i>
                <h2 class="text-[12.5px] font-semibold text-ink m-0">Daftar Ruang / Kelas</h2>
            </div>
            <a href="{{ route('students.index', ['view_all' => 1]) }}" class="btn-link">
                Lihat semua anggota
                <i data-lucide="arrow-right" class="w-3 h-3"></i>
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Ruang / Kelas</th>
                        <th>Organisasi</th>
                        <th>Gedung</th>
                        <th>Jam Masuk</th>
                        <th class="text-center">Anggota</th>
                        <th class="text-center">Wajah Terdaftar</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($classroomsWithStats ?? [] as $room)
                        <tr>
                            <td class="font-medium text-ink">{{ $room->name }}</td>
                            <td class="text-ink-muted">{{ $room->school->name ?? '—' }}</td>
                            <td class="text-ink-muted">{{ $room->building->name ?? '—' }}</td>
                            <td class="font-mono">{{ $room->start_time ? substr($room->start_time, 0, 5) : '—' }}</td>
                            <td class="text-center tabular-nums">{{ $room->students_count }}</td>
                            <td class="text-center">
                                @php($pct = $room->students_count > 0 ? round($room->enrolled_count / $room->students_count * 100) : 0)
                                <span class="badge {{ $pct >= 90 ? 'badge-success' : ($pct >= 50 ? 'badge-warning' : 'badge-muted') }}">
                                    {{ $room->enrolled_count }}/{{ $room->students_count }}
                                </span>
                            </td>
                            <td class="text-right">
                                <a href="{{ route('students.index', ['classroom_id' => $room->id]) }}" class="btn btn-secondary btn-sm">
                                    Buka <i data-lucide="chevron-right" class="w-3 h-3"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <span class="empty-state-icon"><i data-lucide="layers" class="w-4 h-4"></i></span>
                                    <span class="empty-state-title">Belum ada ruang / kelas</span>
                                    <p class="empty-state-text">Tambahkan kelas pada menu Organisasi untuk mulai mengelompokkan anggota.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse

                    @if(($unassignedCount ?? 0) > 0)
                        <tr class="bg-canvas-soft">
                            <td class="font-medium text-ink">Tanpa Kelas</td>
                            <td class="text-ink-muted">—</td>
                            <td class="text-ink-muted">—</td>
                            <td class="text-ink-muted">—</td>
                            <td class="text-center tabular-nums">{{ $unassignedCount }}</td>
                            <td class="text-center">
                                <span class="badge badge-muted">{{ $unassignedEnrolled ?? 0 }}/{{ $unassignedCount }}</span>
                            </td>
                            <td class="text-right">
                                <a href="{{ route('students.index', ['classroom_id' => 'unassigned']) }}" class="btn btn-secondary btn-sm">
                                    Buka <i data-lucide="chevron-right" class="w-3 h-3"></i>
                                </a>
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </section>
    @else
    {{-- ═══════════════════════ Roster header ═══════════════════════════════ --}}
    <div class="flex flex-wrap items-center justify-between gap-xs mb-sm">
        <div class="flex items-center gap-xs min-w-0">
            <a href="{{ route('students.index') }}" class="btn-icon" aria-label="Kembali ke daftar kelas">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
            </a>
            <div class="min-w-0">
                <p class="text-[10.5px] font-semibold uppercase tracking-wide text-ink-faint m-0">Daftar Ruang / Kelas</p>
                <p class="text-[13px] font-semibold text-ink truncate m-0">
                    {{ $activeClassroom->name ?? (request('classroom_id') === 'unassigned' ? 'Tanpa Kelas' : 'Semua Anggota') }}
                </p>
            </div>
        </div>
        @if($activeClassroom)
            <p class="text-[11.5px] text-ink-muted m-0">
                {{ $activeClassroom->school->name ?? '—' }}@if($activeClassroom->building) · {{ $activeClassroom->building->name }}@endif
            </p>
        @endif
    </div>

    {{-- ───────────────────────────── Filter bar ─────────────────────────── --}}
    <section class="card p-sm mb-md">
        <form method="GET" action="{{ route('students.index') }}" class="flex flex-wrap items-end gap-sm">
            <div class="flex-1 min-w-[180px]">
                <label for="member_search" class="form-label">Cari Anggota</label>
                <div class="relative">
                    <i data-lucide="search" class="w-3.5 h-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-ink-faint pointer-events-none"></i>
                    <input type="text" id="member_search" name="search" value="{{ request('search') }}"
                           placeholder="Nama, NIS, atau email…" class="form-input pl-8 w-full">
                </div>
            </div>
            <div class="min-w-[160px]">
                <label for="member_classroom" class="form-label">Kelas / Grup</label>
                <select id="member_classroom" name="classroom_id" class="form-select">
                    <option value="">Semua kelas</option>
                    @foreach($classrooms ?? [] as $cr)
                        <option value="{{ $cr->id }}" @selected(request('classroom_id') == $cr->id)>{{ $cr->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="min-w-[150px]">
                <label for="member_filter" class="form-label">Status Wajah</label>
                <select id="member_filter" name="filter" class="form-select">
                    <option value="">Semua</option>
                    <option value="enrolled" @selected(request('filter') === 'enrolled')>Sudah terdaftar</option>
                    <option value="unenrolled" @selected(request('filter') === 'unenrolled')>Belum terdaftar</option>
                </select>
            </div>
            <div class="flex items-center gap-xs">
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="filter" class="w-3.5 h-3.5"></i> Terapkan
                </button>
                @if(request()->hasAny(['search', 'classroom_id', 'filter']))
                    <a href="{{ route('students.index') }}" class="btn-link text-ink-muted">Reset</a>
                @endif
            </div>
        </form>
    </section>

    {{-- ───────────────────────────── Member table ───────────────────────── --}}
    <section class="card p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>NIS / NIP</th>
                        <th>Kelas / Grup</th>
                        <th>Organisasi</th>
                        <th class="text-center">Wajah</th>
                        <th class="text-center">Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($students ?? [] as $student)
                        <tr>
                            <td>
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="w-6 h-6 rounded-full bg-canvas-soft border border-hairline text-ink-muted
                                                 text-[10px] font-bold flex items-center justify-center shrink-0">
                                        {{ mb_strtoupper(mb_substr($student->name, 0, 1)) }}
                                    </span>
                                    <span class="font-medium text-ink truncate">{{ $student->name }}</span>
                                </div>
                            </td>
                            <td class="font-mono">{{ $student->code }}</td>
                            <td>{{ $student->classroom->name ?? '—' }}</td>
                            <td class="text-ink-muted">{{ $student->school->name ?? '—' }}</td>
                            <td class="text-center">
                                @if($student->face_template)
                                    <span class="badge badge-success">Terdaftar</span>
                                @else
                                    <span class="badge badge-muted">Kosong</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($student->is_active)
                                    <span class="badge badge-primary">Aktif</span>
                                @else
                                    <span class="badge badge-neutral">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button" data-student="{{ json_encode($student) }}" @click="openEnroll" class="btn btn-secondary btn-sm">
                                        <i data-lucide="scan-face" class="w-3 h-3"></i>
                                        {{ $student->face_template ? 'Kelola Wajah' : 'Daftarkan' }}
                                    </button>
                                    <button type="button" data-student="{{ json_encode($student) }}" @click="openEdit" class="btn btn-secondary btn-sm">
                                        <i data-lucide="edit" class="w-3 h-3"></i> Edit
                                    </button>
                                    <form method="POST" action="{{ route('students.destroy', $student) }}" class="inline"
                                          @submit="confirmSubmit"
                                          data-confirm="Hapus {{ $student->name }} beserta seluruh riwayat absensi dan data wajahnya?">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-danger-outline btn-sm">
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
                                    <span class="empty-state-icon"><i data-lucide="users" class="w-4 h-4"></i></span>
                                    <span class="empty-state-title">Belum ada anggota</span>
                                    <p class="empty-state-text">Tambahkan anggota satu per satu atau impor dari berkas Excel.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(isset($students) && $students->hasPages())
            <div class="px-md py-sm border-t border-hairline bg-canvas-soft">
                {{ $students->appends(request()->query())->links('pagination::tailwind') }}
            </div>
        @endif
    </section>
    @endif

    {{-- ═══════════════════════════ Create member ═══════════════════════════ --}}
    <dialog id="createMemberModal" x-ref="createModal" @click="$event.target === $el && $el.close()"
            class="w-[min(560px,94vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="user-plus" class="w-4 h-4 text-primary"></i> Tambah Anggota</h2>
            <button type="button" @click="$refs.createModal.close()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" action="{{ route('students.store') }}">
            @csrf
            <div class="modal-body grid grid-cols-1 sm:grid-cols-2 gap-sm">
                <div>
                    <label for="create_name" class="form-label">Nama Lengkap <span class="req">*</span></label>
                    <input type="text" id="create_name" name="name" value="{{ old('name') }}"
                           class="form-input @error('name') is-invalid @enderror" required>
                    @error('name')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="create_code" class="form-label">NIS / NIP <span class="req">*</span></label>
                    <input type="text" id="create_code" name="code" value="{{ old('code') }}"
                           class="form-input @error('code') is-invalid @enderror" required>
                    @error('code')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="create_role" class="form-label">Peran <span class="req">*</span></label>
                    <select id="create_role" name="role" required class="form-select @error('role') is-invalid @enderror">
                        <option value="student" @selected(old('role') === 'student')>Siswa</option>
                        <option value="staff" @selected(old('role') === 'staff')>Guru / Staf</option>
                    </select>
                    @error('role')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="create_school" class="form-label">Organisasi <span class="req">*</span></label>
                    <select id="create_school" name="school_id" required
                            x-on:change="filterClassrooms('create')"
                            class="form-select @error('school_id') is-invalid @enderror">
                        <option value="">Pilih organisasi…</option>
                        @foreach($schools ?? [] as $school)
                            <option value="{{ $school->id }}" @selected(old('school_id') == $school->id)>{{ $school->name }}</option>
                        @endforeach
                    </select>
                    @error('school_id')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="create_classroom" class="form-label">Kelas / Grup</label>
                    <select id="create_classroom" name="classroom_id"
                            class="form-select @error('classroom_id') is-invalid @enderror">
                        <option value="">— Belum ditentukan —</option>
                        @foreach($classrooms ?? [] as $cr)
                            <option value="{{ $cr->id }}" data-school="{{ $cr->school_id }}" @selected(old('classroom_id') == $cr->id)>
                                {{ $cr->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('classroom_id')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="create_email" class="form-label">Email</label>
                    <input type="email" id="create_email" name="email" value="{{ old('email') }}"
                           class="form-input @error('email') is-invalid @enderror" placeholder="opsional">
                    @error('email')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="create_phone" class="form-label">No. Telepon</label>
                    <input type="text" id="create_phone" name="phone" value="{{ old('phone') }}"
                           class="form-input @error('phone') is-invalid @enderror" placeholder="opsional">
                    @error('phone')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div>
                    <label for="create_active" class="form-label">Status</label>
                    <select id="create_active" name="is_active" class="form-select">
                        <option value="1" @selected(old('is_active', '1') === '1')>Aktif</option>
                        <option value="0" @selected(old('is_active') === '0')>Nonaktif</option>
                    </select>
                </div>
                <div class="sm:col-span-2 rounded-md border border-hairline bg-canvas-soft p-sm">
                    <label class="flex items-start gap-xs cursor-pointer">
                        <input type="checkbox" name="has_consent" value="1" required
                               class="mt-0.5 w-3.5 h-3.5 rounded border-hairline text-primary focus:ring-2 focus:ring-primary/30 cursor-pointer shrink-0">
                        <span class="text-[11.5px] text-ink-secondary leading-relaxed">
                            Yang bersangkutan telah memberikan <strong class="font-semibold text-ink">persetujuan tertulis</strong>
                            untuk pemrosesan data biometrik wajah sesuai kebijakan privasi instansi.
                        </span>
                    </label>
                    @error('has_consent')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" @click="$refs.createModal.close()" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Anggota</button>
            </div>
        </form>
    </dialog>

    {{-- ═══════════════════════════ Edit member ═════════════════════════════ --}}
    <dialog x-ref="editModal" @click="$event.target === $refs.editModal && $refs.editModal.close()"
            class="w-[min(560px,94vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="edit-3" class="w-4 h-4 text-primary"></i> Edit Anggota</h2>
            <button type="button" @click="$refs.editModal.close()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" x-ref="editForm" action="">
            @csrf @method('PUT')
            <div class="modal-body grid grid-cols-1 sm:grid-cols-2 gap-sm">
                <div>
                    <label for="edit_name" class="form-label">Nama Lengkap <span class="req">*</span></label>
                    <input type="text" id="edit_name" name="name" required class="form-input">
                </div>
                <div>
                    <label for="edit_code" class="form-label">NIS / NIP <span class="req">*</span></label>
                    <input type="text" id="edit_code" name="code" required class="form-input">
                </div>
                <div>
                    <label for="edit_role" class="form-label">Peran <span class="req">*</span></label>
                    <select id="edit_role" name="role" required class="form-select">
                        <option value="student">Siswa</option>
                        <option value="staff">Guru / Staf</option>
                    </select>
                </div>
                <div>
                    <label for="edit_school" class="form-label">Organisasi <span class="req">*</span></label>
                    <select id="edit_school" name="school_id" required x-on:change="filterClassrooms('edit')" class="form-select">
                        <option value="">Pilih organisasi…</option>
                        @foreach($schools ?? [] as $school)
                            <option value="{{ $school->id }}">{{ $school->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label for="edit_classroom" class="form-label">Kelas / Grup</label>
                    <select id="edit_classroom" name="classroom_id" class="form-select">
                        <option value="">— Belum ditentukan —</option>
                        @foreach($classrooms ?? [] as $cr)
                            <option value="{{ $cr->id }}" data-school="{{ $cr->school_id }}">{{ $cr->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="edit_email" class="form-label">Email</label>
                    <input type="email" id="edit_email" name="email" class="form-input">
                </div>
                <div>
                    <label for="edit_phone" class="form-label">No. Telepon</label>
                    <input type="text" id="edit_phone" name="phone" class="form-input">
                </div>
                <div>
                    <label for="edit_active" class="form-label">Status</label>
                    <select id="edit_active" name="is_active" class="form-select">
                        <option value="1">Aktif</option>
                        <option value="0">Nonaktif</option>
                    </select>
                </div>
                <div class="sm:col-span-2 rounded-md border border-hairline bg-canvas-soft p-sm">
                    <label class="flex items-start gap-xs cursor-pointer">
                        <input type="checkbox" name="has_consent" id="edit_consent" value="1"
                               class="mt-0.5 w-3.5 h-3.5 rounded border-hairline text-primary focus:ring-2 focus:ring-primary/30 cursor-pointer shrink-0">
                        <span class="text-[11.5px] text-ink-secondary leading-relaxed">
                            Persetujuan biometrik tetap berlaku untuk anggota ini.
                        </span>
                    </label>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" @click="$refs.editModal.close()" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
            </div>
        </form>
    </dialog>

    {{-- ═══════════════════════════ Import members ══════════════════════════ --}}
    <dialog id="importMemberModal" x-ref="importModal" @click="$event.target === $el && $el.close()"
            class="w-[min(480px,94vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="upload" class="w-4 h-4 text-primary"></i> Impor Anggota</h2>
            <button type="button" @click="$refs.importModal.close()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" action="{{ route('students.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-body flex flex-col gap-sm">
                <div>
                    <label for="import_file" class="form-label">Berkas Excel <span class="req">*</span></label>
                    <input type="file" id="import_file" name="file" accept=".xlsx,.xls,.csv" required
                           class="form-input @error('file') is-invalid @enderror">
                    <span class="form-hint">Format .xlsx, .xls, atau .csv — maksimal 5 MB.</span>
                    @error('file')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="alert alert-info">
                    <i data-lucide="info" class="w-4 h-4 shrink-0 mt-px"></i>
                    <span>
                        Gunakan templat resmi agar kolom sesuai.
                        <a href="{{ route('students.template') }}" class="font-semibold underline">Unduh templat</a>.
                    </span>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" @click="$refs.importModal.close()" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-primary">Mulai Impor</button>
            </div>
        </form>
    </dialog>

    {{-- ═══════════════════════════ Bulk enroll ═════════════════════════════ --}}
    <dialog id="bulkEnrollMemberModal" x-ref="bulkEnrollModal" @click="$event.target === $el && $el.close()"
            class="w-[min(480px,94vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="scan-face" class="w-4 h-4 text-primary"></i> Daftarkan Wajah Massal</h2>
            <button type="button" @click="$refs.bulkEnrollModal.close()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
        <form method="POST" action="{{ route('students.bulk-enroll') }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-body flex flex-col gap-sm">
                <p class="text-[12px] text-ink-muted m-0">
                    Unggah arsip ZIP berisi foto wajah. Nama berkas harus sama dengan NIS anggota
                    (contoh: <code class="font-mono text-ink">00829102.jpg</code>).
                </p>
                <div>
                    <label for="bulk_zip" class="form-label">Berkas ZIP <span class="req">*</span></label>
                    <input type="file" id="bulk_zip" name="zip_file" accept=".zip" required
                           class="form-input @error('zip_file') is-invalid @enderror">
                    @error('zip_file')<span class="form-error">{{ $message }}</span>@enderror
                </div>
                <div class="alert alert-warning">
                    <i data-lucide="alert-triangle" class="w-4 h-4 shrink-0 mt-px"></i>
                    <span>Proses berjalan di latar belakang. Anda dapat menutup halaman ini setelah unggahan selesai.</span>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" @click="$refs.bulkEnrollModal.close()" class="btn btn-secondary">Batal</button>
                <button type="submit" class="btn btn-primary">Unggah & Proses</button>
            </div>
        </form>
    </dialog>

    {{-- ═══════════════════════════ Enroll face ═════════════════════════════ --}}
    <dialog x-ref="enrollModal" @close="closeEnrollModal()"
            @click="$event.target === $el && closeEnrollModal()"
            class="w-[min(520px,94vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title">
                <i data-lucide="scan-face" class="w-4 h-4 text-primary"></i>
                <span x-text="enrollStudent && enrollStudent.face_template ? 'Kelola Wajah' : 'Daftarkan Wajah'">Daftarkan Wajah</span>
            </h2>
            <button type="button" @click="closeEnrollModal()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <div class="modal-body flex flex-col gap-sm">
            <div class="flex items-center justify-between gap-sm pb-sm border-b border-hairline">
                <div class="min-w-0">
                    <p class="text-[10.5px] font-semibold uppercase tracking-wide text-ink-faint m-0">Anggota</p>
                    <p class="text-[13px] font-semibold text-ink truncate m-0 mt-0.5" x-text="enrollStudent ? enrollStudent.name : '—'"></p>
                </div>
                <span class="badge" :class="enrollStudent && enrollStudent.face_template ? 'badge-success' : 'badge-muted'"
                      x-text="enrollStudent && enrollStudent.face_template ? 'Terdaftar' : 'Kosong'"></span>
            </div>

            <div class="flex gap-0 border-b border-hairline" role="tablist">
                <button type="button" role="tab" x-show="enrollStudent && enrollStudent.face_template"
                        @click="switchEnrollTab('current')"
                        class="flex-1 pb-1.5 text-[12px] font-medium border-b-2 -mb-px transition-colors cursor-pointer bg-transparent"
                        :class="enrollTab === 'current' ? 'border-primary text-primary' : 'border-transparent text-ink-faint hover:text-ink'">
                    Foto Terdaftar
                </button>
                <button type="button" role="tab" @click="switchEnrollTab('camera')"
                        class="flex-1 pb-1.5 text-[12px] font-medium border-b-2 -mb-px transition-colors cursor-pointer bg-transparent"
                        :class="enrollTab === 'camera' ? 'border-primary text-primary' : 'border-transparent text-ink-faint hover:text-ink'">
                    Kamera Web
                </button>
                <button type="button" role="tab" @click="switchEnrollTab('upload')"
                        class="flex-1 pb-1.5 text-[12px] font-medium border-b-2 -mb-px transition-colors cursor-pointer bg-transparent"
                        :class="enrollTab === 'upload' ? 'border-primary text-primary' : 'border-transparent text-ink-faint hover:text-ink'">
                    Unggah Berkas
                </button>
            </div>

            <div x-show="enrollTab === 'current'" x-cloak
                 class="relative bg-canvas-soft rounded-lg p-md flex flex-col items-center justify-center border border-hairline min-h-[260px]">
                <template x-if="enrollStudent && enrollStudent.face_template && enrollStudent.face_template.photo_path">
                    <img :src="'/students/' + enrollStudent.id + '/photo?t=' + Date.now()"
                         alt="Foto wajah terdaftar"
                         class="max-h-[220px] max-w-full rounded-md border border-hairline object-cover mb-xs">
                </template>
                <template x-if="enrollStudent && enrollStudent.face_template && !enrollStudent.face_template.photo_path">
                    <div class="p-lg bg-surface border border-hairline rounded-lg text-ink-muted text-[12px] flex flex-col items-center gap-xs text-center">
                        <i data-lucide="user-check" class="w-7 h-7 text-success"></i>
                        <p class="m-0">Data biometrik aktif pada mesin absensi.</p>
                        <p class="text-[11px] text-ink-faint m-0">File foto mentah sebelumnya tidak diarsipkan di server.</p>
                    </div>
                </template>
                <p class="text-[11.5px] text-ink-muted mt-xs mb-0"
                   x-text="enrollStudent && enrollStudent.face_template && enrollStudent.face_template.created_at
                       ? 'Terdaftar sejak: ' + new Date(enrollStudent.face_template.created_at).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
                       : ''"></p>
            </div>

            <div x-show="enrollTab === 'camera'" x-cloak
                 class="relative bg-black rounded-lg overflow-hidden h-[300px] flex items-center justify-center border border-hairline">
                <video x-ref="enrollVideo" autoplay playsinline muted class="w-full h-full object-cover"></video>
                <div x-show="cameraLoading" x-cloak
                     class="absolute inset-0 bg-surface/85 flex items-center justify-center text-ink-faint text-[12px]">
                    <span x-text="cameraError || 'Membuka kamera…'"></span>
                </div>
                <canvas x-ref="enrollCanvas" class="hidden"></canvas>
            </div>

            <div x-show="enrollTab === 'upload'" x-cloak
                 class="relative bg-canvas-soft rounded-lg h-[300px] flex flex-col items-center justify-center border border-hairline border-dashed p-md gap-xs">
                <i data-lucide="upload-cloud" class="w-8 h-8 text-ink-faint"></i>
                <p class="text-[12px] text-ink-faint m-0">Pilih foto wajah (.jpg, .png) maksimal 5 MB.</p>
                <input type="file" x-ref="enrollFileInput" accept="image/jpeg,image/png,image/webp"
                       @change="previewUpload($event)"
                       class="form-input max-w-[260px] text-[11.5px]">
                <template x-if="uploadPreview">
                    <img :src="uploadPreview" alt="Pratinjau foto"
                         class="mt-sm max-h-[150px] rounded border border-hairline object-cover">
                </template>
            </div>

            <template x-if="enrollStatus">
                <div class="alert" :class="enrollStatusType === 'success' ? 'alert-success' : (enrollStatusType === 'error' ? 'alert-error' : 'alert-info')"
                     role="status">
                    <i data-lucide="info" class="w-4 h-4 shrink-0 mt-px"></i>
                    <span x-text="enrollStatus"></span>
                </div>
            </template>
        </div>

        <div class="modal-foot justify-between">
            <div>
                <button type="button" x-show="enrollStudent && enrollStudent.face_template" x-cloak
                        @click="deleteFaceTemplate()" :disabled="enrollBusy"
                        class="btn btn-danger-outline btn-sm">
                    <i data-lucide="trash-2" class="w-3 h-3"></i> Hapus Wajah
                </button>
            </div>
            <div class="flex items-center gap-xs">
                <button type="button" @click="closeEnrollModal()" class="btn btn-secondary">Tutup</button>
                <button type="button" x-show="enrollTab === 'current'" x-cloak
                        @click="switchEnrollTab('camera')" class="btn btn-secondary btn-sm">
                    <i data-lucide="refresh-cw" class="w-3 h-3"></i> Ganti Wajah
                </button>
                <button type="button" x-show="enrollTab === 'camera'" x-cloak
                        @click="captureAndEnroll()" :disabled="enrollBusy"
                        class="btn btn-primary">
                    <i data-lucide="camera" class="w-3.5 h-3.5"></i>
                    <span x-text="enrollBusy ? 'Memproses…' : 'Ambil & Simpan'">Ambil & Simpan</span>
                </button>
                <button type="button" x-show="enrollTab === 'upload'" x-cloak
                        @click="submitUploadAndEnroll()" :disabled="enrollBusy"
                        class="btn btn-primary">
                    <i data-lucide="upload" class="w-3.5 h-3.5"></i>
                    <span x-text="enrollBusy ? 'Memproses…' : 'Simpan Wajah'">Simpan Wajah</span>
                </button>
            </div>
        </div>
    </dialog>
</div>
@endsection

@push('scripts')
<script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    function filterClassrooms(prefix) {
        const schoolSelect = document.getElementById(prefix + '_school');
        const classroomSelect = document.getElementById(prefix + '_classroom');
        if (!schoolSelect || !classroomSelect) return;
        const selectedSchool = schoolSelect.value;
        const currentValue = classroomSelect.value;

        Array.from(classroomSelect.options).forEach(opt => {
            if (!opt.value) return;
            const schoolId = opt.getAttribute('data-school');
            const visible = !selectedSchool || !schoolId || schoolId === selectedSchool;
            opt.hidden = !visible;
            if (!visible && opt.value === currentValue) classroomSelect.value = '';
        });
    }
</script>
@endpush
