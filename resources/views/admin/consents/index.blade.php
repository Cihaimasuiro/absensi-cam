@extends('layouts.app')
@section('title', 'Persetujuan Biometrik (UU PDP)')

@section('header-actions')
    <div class="flex items-center gap-xs">
        <a href="{{ route('admin.consents.export', request()->query()) }}" class="btn btn-secondary text-xs">
            <i data-lucide="download" class="w-3.5 h-3.5"></i> Ekspor CSV
        </a>
        <button type="button" @click="consentModal = true" class="btn btn-primary text-xs">
            <i data-lucide="file-plus" class="w-3.5 h-3.5"></i> Catat Persetujuan
        </button>
    </div>
@endsection

@section('content')
<div x-data="{ consentModal: false }" class="space-y-sm">
    {{-- Compliance Summary --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-xs">
        <div class="card p-xs flex items-center justify-between">
            <div>
                <span class="text-xs text-ink-muted block">Persetujuan Aktif</span>
                <span class="text-lg font-semibold text-success">{{ $totalActive }}</span>
            </div>
            <i data-lucide="shield-check" class="w-5 h-5 text-success opacity-80"></i>
        </div>
        <div class="card p-xs flex items-center justify-between">
            <div>
                <span class="text-xs text-ink-muted block">Persetujuan Ditarik</span>
                <span class="text-lg font-semibold text-danger">{{ $totalWithdrawn }}</span>
            </div>
            <i data-lucide="shield-alert" class="w-5 h-5 text-danger opacity-80"></i>
        </div>
        <div class="card p-xs flex items-center justify-between">
            <div>
                <span class="text-xs text-ink-muted block">Total Anggota Terdaftar</span>
                <span class="text-lg font-semibold text-ink">{{ $totalStudents }}</span>
            </div>
            <i data-lucide="users" class="w-5 h-5 text-ink-muted opacity-80"></i>
        </div>
    </div>

    {{-- Filter bar --}}
    <div class="card p-xs flex flex-wrap items-center justify-between gap-xs">
        <form method="GET" action="{{ route('admin.consents.index') }}" class="flex flex-wrap items-center gap-xs">
            <input type="text" name="search" value="{{ $search }}" placeholder="Cari anggota atau nama wali..."
                   class="form-input text-xs py-1 w-48 md:w-64">

            <select name="status" class="form-select text-xs py-1 w-36">
                <option value="all" {{ $status === 'all' ? 'selected' : '' }}>Semua Status</option>
                <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Aktif</option>
                <option value="withdrawn" {{ $status === 'withdrawn' ? 'selected' : '' }}>Ditarik</option>
            </select>

            <button type="submit" class="btn btn-secondary text-xs py-1">Filter</button>
            @if($search || $status !== 'all')
                <a href="{{ route('admin.consents.index') }}" class="btn btn-ghost text-xs py-1">Reset</a>
            @endif
        </form>
        <span class="text-xs text-ink-muted">Total: {{ $consents->total() }} persetujuan</span>
    </div>

    {{-- Consents table --}}
    <div class="card p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Anggota</th>
                        <th>Grup / Kelas</th>
                        <th>Wali / Hubungan</th>
                        <th>Versi Kebijakan</th>
                        <th>Diberikan</th>
                        <th>Status</th>
                        <th>Dicatat Oleh</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($consents as $consent)
                        <tr>
                            <td>
                                @if($consent->student)
                                    <div class="font-medium text-ink">{{ $consent->student->name }}</div>
                                    <div class="font-mono text-[11px] text-ink-muted">{{ $consent->student->code }}</div>
                                @else
                                    <span class="text-ink-muted text-xs">Siswa ID: {{ $consent->student_id }}</span>
                                @endif
                            </td>
                            <td class="text-xs text-ink-muted">
                                {{ $consent->student?->classroom?->name ?? '—' }}
                            </td>
                            <td class="text-xs text-ink">
                                @if($consent->guardian_name)
                                    {{ $consent->guardian_name }}
                                    @if($consent->guardian_relation)
                                        <span class="text-ink-muted">({{ $consent->guardian_relation }})</span>
                                    @endif
                                @else
                                    <span class="text-ink-muted">Mandiri / Dewasa</span>
                                @endif
                            </td>
                            <td class="font-mono text-xs text-ink-muted">
                                {{ $consent->text_version ?? 'v1.0' }}
                            </td>
                            <td class="text-xs text-ink whitespace-nowrap">
                                {{ $consent->given_at ? $consent->given_at->format('Y-m-d H:i') : '—' }}
                            </td>
                            <td>
                                @if($consent->isActive())
                                    <span class="text-xs font-semibold text-success">Aktif</span>
                                @else
                                    <span class="text-xs font-semibold text-danger">
                                        Ditarik ({{ $consent->withdrawn_at->format('Y-m-d') }})
                                    </span>
                                @endif
                            </td>
                            <td class="text-xs text-ink-muted">
                                {{ $consent->recorder?->name ?? 'Sistem' }}
                            </td>
                            <td class="text-right whitespace-nowrap">
                                @if($consent->isActive())
                                    <form method="POST" action="{{ route('admin.consents.withdraw', $consent) }}" class="inline"
                                          onsubmit="return confirm('Peringatan: Penarikan persetujuan akan otomatis menghapus (men-tombstone) template biometrik anggota dari seluruh terminal sesuai UU PDP No. 27/2022. Lanjutkan penarikan untuk {{ $consent->student?->name }}?');">
                                        @csrf
                                        <button type="submit" class="btn btn-danger text-xs py-0.5 px-2">
                                            Tarik Persetujuan
                                        </button>
                                    </form>
                                @else
                                    <span class="text-[11px] text-ink-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-md text-ink-muted text-xs">
                                Belum ada data persetujuan biometrik yang tercatat.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($consents->hasPages())
            <div class="p-xs border-t border-hairline">
                {{ $consents->links() }}
            </div>
        @endif
    </div>

    {{-- Record Consent Modal --}}
    <div x-show="consentModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-sm bg-ink/40">
        <div @click.away="consentModal = false" class="card w-full max-w-md p-md space-y-sm bg-canvas">
            <div class="flex items-center justify-between border-b border-hairline pb-xs">
                <h3 class="text-sm font-semibold text-ink">Catat Persetujuan Biometrik</h3>
                <button type="button" @click="consentModal = false" class="text-ink-muted hover:text-ink text-sm">&times;</button>
            </div>

            <form action="{{ route('admin.consents.store') }}" method="POST" class="space-y-xs">
                @csrf
                <div>
                    <label class="block text-xs font-medium text-ink mb-1">Pilih Anggota</label>
                    <select name="student_id" required class="form-select text-xs w-full">
                        <option value="">Pilih anggota tanpa persetujuan aktif...</option>
                        @foreach($studentsWithoutConsent as $s)
                            <option value="{{ $s->id }}">{{ $s->name }} ({{ $s->code }})</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-ink mb-1">Nama Wali / Orang Tua <span class="text-ink-muted text-[11px]">(opsional)</span></label>
                    <input type="text" name="guardian_name" class="form-input text-xs w-full" placeholder="Contoh: Bpk. Hariyadi">
                </div>

                <div>
                    <label class="block text-xs font-medium text-ink mb-1">Hubungan Wali <span class="text-ink-muted text-[11px]">(opsional)</span></label>
                    <input type="text" name="guardian_relation" class="form-input text-xs w-full" placeholder="Ayah / Ibu / Wali">
                </div>

                <div>
                    <label class="block text-xs font-medium text-ink mb-1">Versi Dokumen Kebijakan</label>
                    <input type="text" name="text_version" value="v1.0" required class="form-input text-xs w-full font-mono">
                </div>

                <div>
                    <label class="block text-xs font-medium text-ink mb-1">Catatan Tambahan <span class="text-ink-muted text-[11px]">(opsional)</span></label>
                    <textarea name="notes" rows="2" class="form-input text-xs w-full" placeholder="Ditandatangani formulir fisik no. ..."></textarea>
                </div>

                <div class="flex justify-end gap-xs pt-xs border-t border-hairline">
                    <button type="button" @click="consentModal = false" class="btn btn-secondary text-xs">Batal</button>
                    <button type="submit" class="btn btn-primary text-xs">Simpan Persetujuan</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
