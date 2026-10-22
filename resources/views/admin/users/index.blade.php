@extends('layouts.app')
@section('title', 'Manajemen Pengguna')

@section('header-actions')
    <button type="button" @click="openAddModal()" class="btn btn-primary text-xs">
        <i data-lucide="user-plus" class="w-3.5 h-3.5"></i> Tambah Pengguna
    </button>
@endsection

@section('content')
<div x-data="userAdminData()" class="space-y-sm">
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
                                        @click="openEditModal({{ json_encode(['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->roles->first()?->name ?? 'user']) }})"
                                        class="btn btn-secondary text-xs py-0.5 px-2">
                                    Edit
                                </button>
                                @if($user->id !== auth()->id())
                                    <form method="POST" action="{{ route('admin.users.destroy', $user) }}" class="inline"
                                          onsubmit="return confirm('Hapus pengguna {{ $user->name }}?');">
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

    {{-- Add/Edit User Modal --}}
    <div x-show="modalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-sm bg-ink/40">
        <div @click.away="modalOpen = false" class="card w-full max-w-md p-md space-y-sm bg-canvas">
            <div class="flex items-center justify-between border-b border-hairline pb-xs">
                <h3 class="text-sm font-semibold text-ink" x-text="isEdit ? 'Edit Pengguna' : 'Tambah Pengguna'"></h3>
                <button type="button" @click="modalOpen = false" class="text-ink-muted hover:text-ink text-sm">&times;</button>
            </div>

            <form :action="isEdit ? '/admin/users/' + form.id : '{{ route('admin.users.store') }}'" method="POST" class="space-y-xs">
                @csrf
                <template x-if="isEdit">
                    <input type="hidden" name="_method" value="PUT">
                </template>

                <div>
                    <label class="block text-xs font-medium text-ink mb-1">Nama Lengkap</label>
                    <input type="text" name="name" x-model="form.name" required class="form-input text-xs w-full">
                </div>

                <div>
                    <label class="block text-xs font-medium text-ink mb-1">Alamat Email</label>
                    <input type="email" name="email" x-model="form.email" required class="form-input text-xs w-full">
                </div>

                <div>
                    <label class="block text-xs font-medium text-ink mb-1">
                        Kata Sandi <span x-show="isEdit" class="text-[11px] text-ink-muted">(Kosongkan jika tidak diubah)</span>
                    </label>
                    <input type="password" name="password" :required="!isEdit" class="form-input text-xs w-full" minlength="8" placeholder="Minimal 8 karakter">
                </div>

                <div>
                    <label class="block text-xs font-medium text-ink mb-1">Peran Akses</label>
                    <select name="role" x-model="form.role" required class="form-select text-xs w-full">
                        @foreach($roles as $role)
                            <option value="{{ $role->name }}">
                                {{ ucfirst(str_replace('_', ' ', $role->name)) }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex justify-end gap-xs pt-xs border-t border-hairline">
                    <button type="button" @click="modalOpen = false" class="btn btn-secondary text-xs">Batal</button>
                    <button type="submit" class="btn btn-primary text-xs" x-text="isEdit ? 'Simpan Perubahan' : 'Tambah Pengguna'"></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function userAdminData() {
    return {
        modalOpen: false,
        isEdit: false,
        form: { id: null, name: '', email: '', role: 'user' },
        openAddModal() {
            this.isEdit = false;
            this.form = { id: null, name: '', email: '', role: 'user' };
            this.modalOpen = true;
        },
        openEditModal(userData) {
            this.isEdit = true;
            this.form = { ...userData };
            this.modalOpen = true;
        }
    };
}
</script>
@endsection
