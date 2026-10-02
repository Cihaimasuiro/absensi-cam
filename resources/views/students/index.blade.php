@extends('layouts.app')
@section('title', 'Manajemen Anggota')

@section('header-actions')
    <button class="btn btn-primary" style="font-size:13px; padding:6px 16px;">Tambah Anggota</button>
@endsection

@section('content')

{{-- Search --}}
<div class="card" style="padding:var(--space-sm) var(--space-md); margin-bottom:var(--space-md);">
    <form method="GET" action="{{ route('students.index') }}" style="display:flex; gap:var(--space-sm); align-items:center;">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Cari NIS atau Nama…" class="form-input" style="max-width:320px;">
        <button type="submit" class="btn btn-utility">Cari</button>
    </form>
</div>

{{-- Table --}}
<div class="card" style="padding:0; overflow:hidden;">
    <table class="data-table">
        <thead>
            <tr>
                <th>NIS</th>
                <th>Nama Lengkap</th>
                <th>Sekolah</th>
                <th>Template Wajah</th>
                <th style="text-align:right;">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($students ?? [] as $student)
                <tr>
                    <td style="font-family:monospace; font-size:12px;">{{ $student->nis }}</td>
                    <td style="font-weight:500; color:var(--color-ink);">{{ $student->full_name }}</td>
                    <td>{{ $student->school->name ?? '-' }}</td>
                    <td>
                        @if($student->face_embedding)
                            <span class="badge badge-success">Terdaftar</span>
                        @else
                            <span class="badge badge-muted">Kosong</span>
                        @endif
                    </td>
                    <td style="text-align:right;">
                        <a href="#" style="font-size:12px; color:var(--color-primary); font-weight:500; text-decoration:none; margin-right:var(--space-sm);">Edit</a>
                        <button class="btn-danger" style="font-size:12px;">Hapus</button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align:center; color:var(--color-ink-faint); padding:var(--space-xxl);">
                        Tidak ada anggota terdaftar.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if(isset($students) && $students->hasPages())
        <div style="padding:var(--space-sm) var(--space-md); border-top:1px solid var(--color-hairline); background:var(--color-canvas-soft);">
            {{ $students->links('pagination::tailwind') }}
        </div>
    @endif
</div>
@endsection
