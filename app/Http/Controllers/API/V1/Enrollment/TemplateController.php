<?php

namespace App\Http\Controllers\API\V1\Enrollment;

use App\Domain\Device\Models\Device;
use App\Domain\Enrollment\Models\FaceTemplate;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/templates?cursor=<N>&limit=200
 *
 * Sinkronisasi delta template wajah ke edge
 * Mengembalikan template dengan version_cursor > cursor yang diberikan, termasuk tombstone (op=delete).
 * Edge memfilter berdasarkan model_version dan menolak yang tidak kompatibel.
 */

class TemplateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $cursor = (int) $request->query('cursor', 0);
        $limit  = min((int) $request->query('limit', 200), 200);

        // Ambil template aktif + tombstone yang ter-soft-delete
        // Dibatasi per sekolah sesuai permintaan
        $schoolId = $device->school_id ?? $device->building?->school_id;
        $buildingId = $device->building_id;

        // Fallback untuk perangkat gerbang/global tanpa building_id jika hanya ada 1 sekolah aktif
        if (!$schoolId && !$buildingId && \App\Domain\School\Models\School::count() === 1) {
            $schoolId = \App\Domain\School\Models\School::value('id');
        }

        // Cegah kebocoran data: Jika perangkat belum terikat ke sekolah manapun, jangan kirim template
        if (!$schoolId) {
            \Illuminate\Support\Facades\Log::warning('template_sync_missing_school_scope', [
                'device_id'   => $device->id,
                'device_code' => $device->device_code,
            ]);

            return response()->json([
                'model_version' => $device->model_version,
                'items'         => [],
                'next_cursor'   => $cursor,
            ]);
        }

        // Jika perangkat punya gedung, periksa apakah gedung itu memiliki kelas.
        // Jika punya kelas, hanya template siswa di kelas tersebut yang dianggap 'in-scope'.
        // Jika TIDAK punya kelas (perangkat gerbang) atau TIDAK punya gedung, SEMUA template sekolah 'in-scope'.
        $buildingHasClassrooms = false;
        if ($buildingId) {
            $buildingHasClassrooms = \App\Domain\School\Models\Classroom::where('building_id', $buildingId)->exists();
        }

        $templates = FaceTemplate::withTrashed()
            ->with('student:id,name,school_id,classroom_id')
            ->whereHas('student', function ($query) use ($schoolId) {
                $query->withTrashed()->where('school_id', $schoolId);
            })
            ->where('version_cursor', '>', $cursor)
            ->orderBy('version_cursor')
            ->limit($limit)
            ->get(['id', 'student_id', 'embedding_enc', 'model_version', 'version_cursor', 'deleted_at', 'updated_at']);

        // Eager load building_id dari kelas siswa agar evaluasi scope berjalan efisien
        $classroomIds = $templates->pluck('student.classroom_id')->filter()->unique();
        $classroomBuildings = \App\Domain\School\Models\Classroom::whereIn('id', $classroomIds)->pluck('building_id', 'id');

        $items = $templates->map(function (FaceTemplate $t) use ($buildingHasClassrooms, $buildingId, $classroomBuildings) {
            $student = $t->student;
            $studentBuildingId = $student ? $classroomBuildings->get($student->classroom_id) : null;
            
            $inScope = true;
            if ($buildingHasClassrooms && $buildingId) {
                $inScope = ($studentBuildingId === $buildingId);
            }

            // Jika template ter-soft-delete, ATAU di luar scope gedung ini, perintahkan perangkat menghapusnya.
            $op = ($t->deleted_at || !$inScope) ? 'delete' : 'upsert';

            return [
                'student_id'     => $t->student_id,
                'name'           => $op === 'delete' ? null : ($student?->name ?? 'Anggota'),
                'embedding_b64'  => $op === 'delete' ? null : $t->embedding_enc,
                'model_version'  => $t->model_version,
                'version_cursor' => $t->version_cursor,
                'op'             => $op,
                'updated_at'     => $t->updated_at->toISOString(),
            ];
        });

        $nextCursor = $templates->isNotEmpty()
            ? $templates->last()->version_cursor
            : $cursor;

        return response()->json([
            'model_version' => $device->model_version,
            'items'         => $items,
            'next_cursor'   => $nextCursor,
        ]);
    }

    /**
     * GET /api/v1/templates/snapshot
     *
     * Snapshot rekonsiliasi state biometrik penuh (Langkah 4).
     * Mengembalikan seluruh student_id aktif yang sah berada dalam scope perangkat saat ini.
     */
    public function snapshot(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $schoolId = $device->school_id ?? $device->building?->school_id;
        $buildingId = $device->building_id;

        // Fallback untuk perangkat gerbang/global tanpa building_id jika hanya ada 1 sekolah aktif
        if (!$schoolId && !$buildingId && \App\Domain\School\Models\School::count() === 1) {
            $schoolId = \App\Domain\School\Models\School::value('id');
        }

        if (!$schoolId) {
            return response()->json([
                'count'             => 0,
                'valid_student_ids' => [],
            ]);
        }

        $buildingHasClassrooms = false;
        if ($buildingId) {
            $buildingHasClassrooms = \App\Domain\School\Models\Classroom::where('building_id', $buildingId)->exists();
        }

        $templates = FaceTemplate::whereNull('deleted_at')
            ->with('student:id,name,school_id,classroom_id')
            ->whereHas('student', function ($query) use ($schoolId) {
                $query->whereNull('deleted_at')->where('school_id', $schoolId);
            })
            ->get(['id', 'student_id']);

        $classroomBuildings = \App\Domain\School\Models\Classroom::whereNotNull('building_id')->pluck('building_id', 'id');

        $validIds = $templates->filter(function (FaceTemplate $t) use ($buildingHasClassrooms, $buildingId, $classroomBuildings) {
            if (!$buildingHasClassrooms || !$buildingId) {
                return true;
            }
            $student = $t->student;
            $studentBuildingId = $student ? $classroomBuildings->get($student->classroom_id) : null;
            return $studentBuildingId === $buildingId;
        })->pluck('student_id')->unique()->values();

        return response()->json([
            'count' => $validIds->count(),
            'valid_student_ids' => $validIds,
        ]);
    }
}
