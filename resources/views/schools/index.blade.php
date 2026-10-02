@extends('layouts.app')
@section('title', 'Manajemen Sekolah')

@section('header-actions')
    <button class="btn btn-primary" style="font-size:13px; padding:6px 16px;">Tambah Sekolah</button>
@endsection

@section('content')
<div class="card" style="padding:0; overflow:hidden;">
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:48px;">ID</th>
                <th>Kode</th>
                <th>Nama Sekolah</th>
                <th style="text-align:right;">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($schools ?? [] as $school)
                <tr>
                    <td style="color:var(--color-ink-faint);">{{ $school->id }}</td>
                    <td style="font-family:monospace; font-size:12px; color:var(--color-ink-secondary);">{{ $school->code }}</td>
                    <td style="font-weight:500; color:var(--color-ink);">{{ $school->name }}</td>
                    <td style="text-align:right;">
                        <a href="#" style="font-size:12px; color:var(--color-primary); font-weight:500; text-decoration:none; margin-right:var(--space-sm);">Edit</a>
                        <button class="btn-danger" style="font-size:12px;">Hapus</button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="text-align:center; color:var(--color-ink-faint); padding:var(--space-xxl);">
                        Belum ada sekolah terdaftar.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
