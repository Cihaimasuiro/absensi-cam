@extends('layouts.app')

@section('title', 'Anggota')
@section('page-title', 'Anggota')

@section('header-actions')
    <a href="{{ route('members.create') }}" class="btn btn-primary btn-sm" id="btn-add-member">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        Tambah Anggota
    </a>
@endsection

@section('content')
{{-- ════════════════════════════════════════
     Search + filter bar — ported from Members.tsx
     memberSearch, enrollmentFilter states → Alpine.js + URL params
     ════════════════════════════════════════ --}}
<div class="card p-3 mb-3">
    <form id="member-filter-form" method="GET" action="{{ route('members.index') }}"
          x-data="{ search: '{{ request('search') }}', filter: '{{ request('filter', 'all') }}', groupId: '{{ request('group_id') }}' }">

        <div class="flex flex-wrap gap-2 items-center">
            {{-- Search input --}}
            <div class="relative flex-1 min-w-[180px]">
                <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5" style="color: var(--text-muted);"
                     fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0016.803 15.803z"/>
                </svg>
                <input id="member-search" type="text" name="search" x-model="search"
                       placeholder="Cari nama atau kode…" class="search-input"
                       @keydown.enter="$el.form.submit()">
            </div>

            {{-- Group filter --}}
            <select name="group_id" x-model="groupId" @change="$el.form.submit()"
                    class="form-input" style="width: auto; min-width: 140px;" id="member-group-filter">
                <option value="">Semua Grup</option>
                @foreach($groups as $group)
                    <option value="{{ $group->id }}" {{ request('group_id') == $group->id ? 'selected' : '' }}>
                        {{ $group->name }}
                    </option>
                @endforeach
            </select>

            {{-- Enrollment filter pills — mirrors Members.tsx enrollmentFilter --}}
            @foreach(['all' => 'Semua', 'enrolled' => 'Terdaftar', 'non-enrolled' => 'Belum Daftar', 'inactive' => 'Nonaktif'] as $val => $label)
                <button type="submit" name="filter" value="{{ $val }}"
                        class="btn btn-sm {{ request('filter', 'all') === $val ? 'btn-primary' : 'btn-secondary' }}"
                        id="filter-{{ $val }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </form>
</div>

{{-- ════════════════════════════════════════
     Members table — ported from Members.tsx MemberRow
     ════════════════════════════════════════ --}}
<div class="card">
    @if($members->isEmpty())
        <div style="text-align: center; padding: 3rem 0; color: var(--text-muted); font-size: 0.8125rem;">
            Tidak ada anggota yang sesuai filter.
        </div>
    @else
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 36px;"></th>
                    <th>Nama</th>
                    <th>Kode</th>
                    <th>Grup</th>
                    <th>Peran</th>
                    <th>Wajah</th>
                    <th>Status</th>
                    <th style="width: 80px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($members as $member)
                    <tr id="member-row-{{ $member->id }}">
                        {{-- Avatar initials — same visual pattern as MemberRow.tsx --}}
                        <td>
                            <div style="width: 28px; height: 28px; border-radius: 9999px;
                                        background: var(--bg-tertiary); border: 1px solid var(--border-primary);
                                        display: flex; align-items: center; justify-content: center;
                                        font-size: 0.625rem; font-weight: 700; color: var(--accent);">
                                {{ strtoupper(substr($member->name, 0, 1)) }}
                            </div>
                        </td>
                        <td style="color: var(--text-primary); font-weight: 500;">{{ $member->name }}</td>
                        <td style="font-family: monospace; font-size: 0.75rem; color: var(--text-tertiary);">
                            {{ $member->code }}
                        </td>
                        <td style="color: var(--text-muted); font-size: 0.75rem;">
                            {{ $member->group?->name ?? '—' }}
                        </td>
                        <td>
                            @if($member->role)
                                <span class="badge badge-muted">{{ $member->role }}</span>
                            @else
                                <span style="color: var(--text-muted);">—</span>
                            @endif
                        </td>
                        <td>
                            @if($member->faceTemplate)
                                <span class="badge badge-success">
                                    <span class="dot-online"></span> Terdaftar
                                </span>
                            @else
                                <span class="badge badge-muted">Belum</span>
                            @endif
                        </td>
                        <td>
                            @if($member->is_active)
                                <span class="badge badge-accent">Aktif</span>
                            @else
                                <span class="badge badge-danger">Nonaktif</span>
                            @endif
                        </td>
                        <td>
                            <div class="flex gap-1">
                                <a href="{{ route('members.enroll', $member) }}"
                                   class="btn btn-xs btn-secondary" title="Enroll Wajah"
                                   id="btn-enroll-{{ $member->id }}">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
                                    </svg>
                                </a>
                                <a href="{{ route('members.edit', $member) }}"
                                   class="btn btn-xs btn-secondary" title="Edit"
                                   id="btn-edit-{{ $member->id }}">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125"/>
                                    </svg>
                                </a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($members->hasPages())
            <div class="px-4 py-3" style="border-top: 1px solid var(--border-primary);">
                {{ $members->links() }}
            </div>
        @endif
    @endif
</div>
@endsection
