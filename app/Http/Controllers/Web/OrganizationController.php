<?php

namespace App\Http\Controllers\Web;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Group;
use App\Domain\Organization\Models\Organization;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function index()
    {
        $organizations = Organization::withCount(['members', 'groups', 'branches'])
            ->orderBy('name')
            ->get();

        return view('organizations.index', compact('organizations'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:150'],
            'code'        => ['nullable', 'string', 'max:50', 'unique:organizations,code'],
            'description' => ['nullable', 'string'],
        ]);

        Organization::create(array_merge($validated, ['is_active' => true]));

        return back()->with('success', 'Organisasi berhasil ditambahkan.');
    }

    public function storeGroup(Request $request)
    {
        $validated = $request->validate([
            'organization_id'  => ['required', 'exists:organizations,id'],
            'name'             => ['required', 'string', 'max:150'],
            'code'             => ['nullable', 'string', 'max:50'],
            'type'             => ['required', 'in:department,class,division'],
            'class_start_time' => ['nullable', 'date_format:H:i'],
        ]);

        Group::create(array_merge($validated, ['is_active' => true]));

        return back()->with('success', 'Grup berhasil ditambahkan.');
    }

    public function storeBranch(Request $request)
    {
        $validated = $request->validate([
            'organization_id' => ['nullable', 'exists:organizations,id'],
            'name'            => ['required', 'string', 'max:150'],
            'code'            => ['required', 'string', 'max:50', 'unique:branches,code'],
            'address'         => ['nullable', 'string'],
            'timezone'        => ['required', 'string', 'max:50'],
        ]);

        Branch::create(array_merge($validated, ['is_active' => true]));

        return back()->with('success', 'Cabang berhasil ditambahkan.');
    }
}
