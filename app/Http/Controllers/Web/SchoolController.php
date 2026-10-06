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
        $schools = School::with(['classrooms'])->withCount(['students', 'classrooms', 'buildings'])
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
            'type'             => ['required', 'in:class,staff'],
            'start_time'       => ['required', 'date_format:H:i'],
            'late_tolerance_minutes' => ['required', 'integer', 'min:0', 'max:120'],
        ]);

        Classroom::create(array_merge($validated, ['is_active' => true]));

        return back()->with('success', 'Grup berhasil ditambahkan.');
    }

    public function updateGroup(Request $request, Classroom $classroom)
    {
        $validated = $request->validate([
            'name'             => ['required', 'string', 'max:150'],
            'code'             => ['nullable', 'string', 'max:50'],
            'type'             => ['required', 'in:class,staff'],
            'start_time'       => ['required', 'date_format:H:i'],
            'late_tolerance_minutes' => ['required', 'integer', 'min:0', 'max:120'],
        ]);

        $oldData = $classroom->only(['start_time', 'late_tolerance_minutes', 'type']);
        $classroom->update($validated);
        
        if ($classroom->wasChanged(['start_time', 'late_tolerance_minutes', 'type'])) {
            activity()
                ->performedOn($classroom)
                ->withProperties([
                    'old' => $oldData,
                    'new' => $classroom->only(['start_time', 'late_tolerance_minutes', 'type'])
                ])
                ->log('Updated classroom timing/type');
        }

        return back()->with('success', 'Pengaturan kelas berhasil diperbarui.');
    }

    public function bulkUpdateTiming(Request $request, School $school)
    {
        $validated = $request->validate([
            'start_time'       => ['required', 'date_format:H:i'],
            'late_tolerance_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'confirm_retroactive' => ['accepted'],
        ]);

        $classrooms = $school->classrooms;
        
        foreach ($classrooms as $classroom) {
            $oldData = $classroom->only(['start_time', 'late_tolerance_minutes']);
            $classroom->update([
                'start_time' => $validated['start_time'],
                'late_tolerance_minutes' => $validated['late_tolerance_minutes'],
            ]);
            
            if ($classroom->wasChanged(['start_time', 'late_tolerance_minutes'])) {
                activity()
                    ->performedOn($classroom)
                    ->withProperties([
                        'old' => $oldData,
                        'new' => $classroom->only(['start_time', 'late_tolerance_minutes'])
                    ])
                    ->log('Bulk updated classroom timings');
            }
        }

        return back()->with('success', 'Waktu massal berhasil diterapkan untuk semua kelas di sekolah ini.');
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

    public function update(Request $request, School $school)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:150'],
            'code'        => ['nullable', 'string', 'max:50', "unique:schools,code,{$school->id}"],
            'description' => ['nullable', 'string'],
            'is_active'   => ['boolean'],
        ]);

        $school->update($validated);

        return back()->with('success', 'Organisasi berhasil diperbarui.');
    }

    public function destroy(School $school)
    {
        $school->delete();
        return back()->with('success', 'Organisasi berhasil dihapus.');
    }
}
