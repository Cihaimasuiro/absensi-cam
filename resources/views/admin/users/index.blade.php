@extends('layouts.app')
@section('title', 'Manajemen Pengguna')

@section('header-actions')
    <button type="button"
            id="btnCreateUser"
            data-modal-id="createUserModal"
            @click="openModalByData"
            onclick="window.openModal('createUserModal')"
            class="btn btn-primary text-xs">
        <i data-lucide="user-plus" class="w-3.5 h-3.5"></i> Tambah Pengguna
    </button>
@endsection

@section('content')
<div x-data="usersData" class="space-y-sm">
    {{-- Filter bar --}}
    <div class="card p-xs flex flex-wrap items-center justify-between gap-xs">
        <form method="GET" action="{{ route('admin.users.index') }}" class="flex flex-wrap items-center gap-xs">
            <input type="text" name="search" value="{{ $search }}" placeholder="Cari nama atau email..."
                   class="form-input text-xs py-1 w-48 md:w-64">
            <select name="role" class="form-select text-xs py-1 w-36">
                <option value="">Semua Peran</option>
                @foreach($roles as $role)
                    <option value="{{ $role->name }}" {{ $roleFilter === $role->name ? 'selected' : '' }}>
                        {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                    </option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-secondary text-xs py-1">Filter</button>
            @if($search || $roleFilter)
                <a href="{{ route('admin.users.index') }}" class="btn btn-ghost text-xs py-1">Reset</a>
            @endif
        </form>
        <span class="text-xs text-ink-muted">Total: {{ $users->total() }} pengguna</span>
    </div>

    {{-- Users table --}}
    <div class="card p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Email</th>
                        <th>Peran</th>
                        <th>Dibuat</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        <tr>
                            <td class="font-medium text-ink">{{ $user->name }}</td>
                            <td class="font-mono text-xs text-ink-muted">{{ $user->email }}</td>
                            <td>
                                @foreach($user->roles as $role)
                                    <span class="text-xs font-mono font-medium">{{ $role->name }}</span>
                                @endforeach
                            </td>
                            <td class="text-xs text-ink-muted whitespace-nowrap">{{ $user->created_at->format('Y-m-d H:i') }}</td>
                            <td class="text-right whitespace-nowrap">
                                <button type="button"
                                        data-user="{{ json_encode(['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->roles->first()?->name ?? 'user']) }}"
                                        @click="openEdit"
                                        class="btn btn-secondary text-xs py-0.5 px-2">
                                    Edit
                                </button>
                                @if($user->id !== auth()->id())
                                    <form method="POST" action="{{ route('admin.users.destroy', $user) }}" class="inline"
                                          @submit="confirmSubmit"
                                          data-confirm="Hapus pengguna {{ $user->name }}?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-danger text-xs py-0.5 px-2">Hapus</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-md text-ink-muted text-xs">Tidak ada data pengguna.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($users->hasPages())
            <div class="p-xs border-t border-hairline">
                {{ $users->links() }}
            </div>
        @endif
    </div>

    {{-- ───────────────────────── Create User Modal ──────────────────────── --}}
    <dialog id="createUserModal" x-ref="createUserModal" @click="$event.target === $el && $el.close()"
            class="w-[min(460px,94vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="user-plus" class="w-4 h-4 text-primary"></i> Tambah Pengguna</h2>
            <button type="button" @click="$refs.createUserModal.close()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form method="POST" action="{{ route('admin.users.store') }}">
            @csrf
            <div class="modal-body flex flex-col gap-sm">
                <div>
                    <label for="create_user_name" class="form-label">Nama Lengkap <span class="req">*</span></label>
                    <input type="text" id="create_user_name" name="name" value="{{ old('name') }}" required
                           class="form-input text-xs w-full @error('name') is-invalid @enderror"
                           placeholder="Nama lengkap pengguna">
                    @error('name')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div>
                    <label for="create_user_email" class="form-label">Alamat Email <span class="req">*</span></label>
                    <input type="email" id="create_user_email" name="email" value="{{ old('email') }}" required
                           class="form-input text-xs w-full @error('email') is-invalid @enderror"
                           placeholder="email@domain.com">
                    @error('email')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div>
                    <label for="create_user_password" class="form-label">Kata Sandi <span class="req">*</span></label>
                    <input type="password" id="create_user_password" name="password" required minlength="8"
                           class="form-input text-xs w-full @error('password') is-invalid @enderror"
                           placeholder="Minimal 8 karakter">
                    @error('password')<span class="form-error">{{ $message }}</span>@enderror
                </div>

                <div>
                    <label for="create_user_role" class="form-label">Peran Akses <span class="req">*</span></label>
                    <select id="create_user_role" name="role" required
                            class="form-select text-xs w-full @error('role') is-invalid @enderror">
                        @foreach($roles as $role)
                            <option value="{{ $role->name }}" {{ old('role') === $role->name ? 'selected' : '' }}>
                                {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                            </option>
                        @endforeach
                    </select>
                    @error('role')<span class="form-error">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" @click="$refs.createUserModal.close()" class="btn btn-secondary text-xs">Batal</button>
                <button type="submit" class="btn btn-primary text-xs">Tambah Pengguna</button>
            </div>
        </form>
    </dialog>

    {{-- ───────────────────────── Edit User Modal ────────────────────────── --}}
    <dialog id="editUserModal" x-ref="editUserModal" @click="$event.target === $el && $el.close()"
            class="w-[min(460px,94vw)] m-auto">
        <div class="modal-head">
            <h2 class="modal-title"><i data-lucide="edit-3" class="w-4 h-4 text-primary"></i> Edit Pengguna</h2>
            <button type="button" @click="$refs.editUserModal.close()" aria-label="Tutup" class="btn-icon">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>

        <form id="editUserForm" x-ref="editUserForm" method="POST" action="">
            @csrf
            @method('PUT')
            <div class="modal-body flex flex-col gap-sm">
                <div>
                    <label for="edit_user_name" class="form-label">Nama Lengkap <span class="req">*</span></label>
                    <input type="text" id="edit_user_name" name="name" required
                           class="form-input text-xs w-full"
                           placeholder="Nama lengkap pengguna">
                </div>

                <div>
                    <label for="edit_user_email" class="form-label">Alamat Email <span class="req">*</span></label>
                    <input type="email" id="edit_user_email" name="email" required
                           class="form-input text-xs w-full"
                           placeholder="email@domain.com">
                </div>

                <div>
                    <label for="edit_user_password" class="form-label">
                        Kata Sandi <span class="text-[11px] text-ink-muted font-normal">(Kosongkan jika tidak diubah)</span>
                    </label>
                    <input type="password" id="edit_user_password" name="password" minlength="8"
                           class="form-input text-xs w-full"
                           placeholder="Minimal 8 karakter (opsional)">
                </div>

                <div>
                    <label for="edit_user_role" class="form-label">Peran Akses <span class="req">*</span></label>
                    <select id="edit_user_role" name="role" required class="form-select text-xs w-full">
                        @foreach($roles as $role)
                            <option value="{{ $role->name }}">
                                {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" @click="$refs.editUserModal.close()" class="btn btn-secondary text-xs">Batal</button>
                <button type="submit" class="btn btn-primary text-xs">Simpan Perubahan</button>
            </div>
        </form>
    </dialog>
</div>
@endsection
