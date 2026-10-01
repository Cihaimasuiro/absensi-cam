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

        $groups = Group::active()->select(['id', 'name', 'organization_id'])->orderBy('name')->get();
        $organizations = Organization::active()->select(['id', 'name'])->orderBy('name')->get();

        return view('members.index', compact('members', 'groups', 'organizations'));
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
            'has_consent'     => ['nullable', 'boolean'],
        ]);

        $member = Member::create(array_merge(
            \Illuminate\Support\Arr::except($validated, ['has_consent']),
            ['is_active' => true]
        ));

        if ($request->boolean('has_consent')) {
            $member->consents()->create([
                'given_at'     => now(),
                'text_version' => 'v1.0',
                'recorded_by'  => auth()->id() ?? \App\Domain\User\Models\User::first()?->id ?? 1,
            ]);
        }

        return redirect()->route('members.index')->with('success', 'Anggota berhasil ditambahkan.');
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
            'has_consent'     => ['nullable', 'boolean'],
        ]);

        $member->update(\Illuminate\Support\Arr::except($validated, ['has_consent']));

        if ($request->boolean('has_consent') && !$member->hasActiveConsent()) {
            $member->consents()->create([
                'given_at'     => now(),
                'text_version' => 'v1.0',
                'recorded_by'  => auth()->id() ?? \App\Domain\User\Models\User::first()?->id ?? 1,
            ]);
        } elseif (!$request->boolean('has_consent') && $member->hasActiveConsent()) {
            $member->activeConsent()->update(['withdrawn_at' => now()]);
            // Also delete face template if consent is withdrawn
            if ($member->faceTemplate) {
                $member->faceTemplate->delete();
            }
        }

        return redirect()->route('members.index')->with('success', 'Anggota berhasil diperbarui.');
    }

    public function destroy(Member $member)
    {
        $member->delete(); // soft-delete triggers tombstone sync

        return redirect()->route('members.index')->with('success', 'Anggota dihapus dan akan dikeluarkan dari perangkat.');
    }
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,xls', 'max:10240'],
        ]);

        try {
            \Maatwebsite\Excel\Facades\Excel::import(
                new \App\Domain\Member\Imports\MembersImport, 
                $request->file('file')
            );
            return redirect()->route('members.index')->with('success', 'Data anggota berhasil diimpor.');
        } catch (\Exception $e) {
            return redirect()->route('members.index')->with('error', 'Gagal mengimpor data: ' . $e->getMessage());
        }
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
