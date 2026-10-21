<?php

namespace App\Domain\Student\Queries;

use App\Domain\Student\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

final class StudentRosterQuery
{
    /**
     * Build and paginate the student roster based on incoming filters and search criteria.
     */
    public function paginate(Request $request, int $perPage = 50): LengthAwarePaginator
    {
        return Student::with(['classroom:id,name', 'school:id,name', 'faceTemplate:id,student_id,photo_path,created_at,updated_at'])
            ->when($request->search, fn ($q) =>
                $q->where(fn ($q) =>
                    $q->where('name', 'like', '%' . $request->search . '%')
                      ->orWhere('code', 'like', '%' . $request->search . '%')
                ))
            ->when($request->filter === 'enrolled',     fn ($q) => $q->whereHas('faceTemplate'))
            ->when($request->filter === 'non-enrolled', fn ($q) => $q->whereDoesntHave('faceTemplate'))
            ->when($request->filter === 'inactive',     fn ($q) => $q->where('is_active', false))
            ->when($request->filter !== 'inactive',     fn ($q) => $q->where('is_active', true))
            ->when($request->classroom_id === 'unassigned', fn ($q) => $q->whereNull('classroom_id'))
            ->when($request->classroom_id && $request->classroom_id !== 'unassigned', fn ($q) => $q->where('classroom_id', $request->classroom_id))
            ->select(['id', 'code', 'name', 'email', 'phone', 'role', 'classroom_id', 'school_id', 'is_active', 'created_at'])
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();
    }
}
