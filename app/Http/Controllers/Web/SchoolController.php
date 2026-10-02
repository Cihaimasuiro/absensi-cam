<?php

namespace App\Http\Controllers\Web;

use App\Domain\School\Models\Building;
use App\Domain\School\Models\Classroom;
use App\Domain\School\Models\School;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SchoolController extends Controller
{
    public function index()
    {
        $schools = School::withCount(['students', 'classrooms', 'buildings'])
            ->orderBy('name')
            ->get();

        return view('schools.index', compact('schools'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:150'],
            'code'        => ['nullable', 'string', 'max:50', 'unique:schools,code'],
            'description' => ['nullable', 'string'],
        ]);

        School::create(array_merge($validated, ['is_active' => true]));

        return back()->with('success', 'Organisasi berhasil ditambahkan.');
    }

    public function storeGroup(Request $request)
    {
        $validated = $request->validate([
            'school_id'  => ['required', 'exists:schools,id'],
            'name'             => ['required', 'string', 'max:150'],
            'code'             => ['nullable', 'string', 'max:50'],
            'type'             => ['required', 'in:department,class,division'],
            'class_start_time' => ['nullable', 'date_format:H:i'],
        ]);

        Classroom::create(array_merge($validated, ['is_active' => true]));

        return back()->with('success', 'Grup berhasil ditambahkan.');
    }

    public function storeBuilding(Request $request)
    {
        $validated = $request->validate([
            'school_id' => ['nullable', 'exists:schools,id'],
            'name'            => ['required', 'string', 'max:150'],
            'code'            => ['required', 'string', 'max:50', 'unique:buildings,code'],
            'address'         => ['nullable', 'string'],
            'timezone'        => ['required', 'string', 'max:50'],
        ]);

        Building::create(array_merge($validated, ['is_active' => true]));

        return back()->with('success', 'Cabang berhasil ditambahkan.');
    }
}
