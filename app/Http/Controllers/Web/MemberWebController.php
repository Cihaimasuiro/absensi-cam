<?php

namespace App\Http\Controllers\Web;

use App\Domain\Member\Models\Member;
use App\Domain\Organization\Models\Group;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MemberWebController extends Controller
{
    public function index(Request $request)
    {
        $members = Member::with(['group:id,name', 'organization:id,name', 'faceTemplate:id,member_id'])
            ->when($request->search, fn ($q) =>
                $q->where(fn ($q) =>
                    $q->where('name', 'like', '%'.$request->search.'%')
                      ->orWhere('code', 'like', '%'.$request->search.'%')
                ))
            ->when($request->filter === 'enrolled',     fn ($q) => $q->whereHas('faceTemplate'))
            ->when($request->filter === 'non-enrolled', fn ($q) => $q->whereDoesntHave('faceTemplate'))
            ->when($request->filter === 'inactive',     fn ($q) => $q->where('is_active', false))
            ->when($request->filter !== 'inactive',     fn ($q) => $q->where('is_active', true))
            ->when($request->group_id, fn ($q) => $q->where('group_id', $request->group_id))
            ->select(['id', 'code', 'name', 'email', 'role', 'group_id', 'organization_id', 'is_active', 'created_at'])
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        $groups = Group::active()->select(['id', 'name'])->orderBy('name')->get();

        return view('members.index', compact('members', 'groups'));
    }

    public function create()
    {
        $organizations = Organization::active()->select(['id', 'name'])->orderBy('name')->get();
        $groups        = Group::active()->select(['id', 'name', 'organization_id'])->orderBy('name')->get();

        return view('members.create', compact('organizations', 'groups'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'group_id'        => ['nullable', 'exists:groups,id'],
            'code'            => ['required', 'string', 'max:50', 'unique:members,code'],
            'name'            => ['required', 'string', 'max:150'],
            'email'           => ['nullable', 'email', 'max:191', 'unique:members,email'],
            'phone'           => ['nullable', 'string', 'max:30'],
            'role'            => ['nullable', 'string', 'max:50'],
        ]);

        $member = Member::create(array_merge($validated, ['is_active' => true]));

        return redirect()->route('members.show', $member)->with('success', 'Anggota berhasil ditambahkan.');
    }

    public function show(Member $member)
    {
        $member->load(['group', 'organization', 'faceTemplate', 'activeConsent', 'attendanceLogs' => fn ($q) => $q->latest('captured_at')->limit(10)]);

        return view('members.show', compact('member'));
    }

    public function edit(Member $member)
    {
        $organizations = Organization::active()->select(['id', 'name'])->orderBy('name')->get();
        $groups        = Group::active()->select(['id', 'name', 'organization_id'])->orderBy('name')->get();

        return view('members.edit', compact('member', 'organizations', 'groups'));
    }

    public function update(Request $request, Member $member)
    {
        $validated = $request->validate([
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'group_id'        => ['nullable', 'exists:groups,id'],
            'code'            => ['required', 'string', 'max:50', "unique:members,code,{$member->id}"],
            'name'            => ['required', 'string', 'max:150'],
            'email'           => ['nullable', 'email', 'max:191', "unique:members,email,{$member->id}"],
            'phone'           => ['nullable', 'string', 'max:30'],
            'role'            => ['nullable', 'string', 'max:50'],
            'is_active'       => ['boolean'],
        ]);

        $member->update($validated);

        return redirect()->route('members.show', $member)->with('success', 'Anggota berhasil diperbarui.');
    }

    public function destroy(Member $member)
    {
        $member->delete(); // soft-delete triggers tombstone sync

        return redirect()->route('members.index')->with('success', 'Anggota dihapus dan akan dikeluarkan dari perangkat.');
    }

    public function enroll(Member $member)
    {
        return view('members.enroll', compact('member'));
    }

    public function enrollStore(\App\Http\Requests\Web\EnrollFaceRequest $request, Member $member)
    {
        (new \App\Domain\Enrollment\Actions\EnrollFace)->execute($member, $request->file('photo'));

        return response()->json([
            'success' => true,
            'message' => 'Enrollment dijadwalkan. Template akan tersedia dalam beberapa detik.'
        ]);
    }
}
