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
 * Delta sync of face templates to edge (FR-S07, FR-E11, PRD §11.2).
 * Returns templates with version_cursor > given cursor, including tombstones (op=delete).
 * Edge filters by model_version and rejects incompatible ones (AC-45).
 */
class TemplateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $cursor = (int) $request->query('cursor', 0);
        $limit  = min((int) $request->query('limit', 200), 200);

        // Fetch active templates + soft-deleted tombstones
        // TODO: In production with many classrooms, re-enable device group filtering (FR-E06)
        $templates = FaceTemplate::withTrashed()
            ->with('student:id,name')
            ->where('version_cursor', '>', $cursor)
            ->orderBy('version_cursor')
            ->limit($limit)
            ->get(['id', 'student_id', 'embedding_enc', 'model_version', 'version_cursor', 'deleted_at', 'updated_at']);

        $items = $templates->map(fn (FaceTemplate $t) => [
            'student_id'     => $t->student_id,
            'name'           => $t->student?->name ?? 'Anggota',
            'embedding_b64'  => $t->deleted_at ? null : $t->embedding_enc,
            'model_version'  => $t->model_version,
            'version_cursor' => $t->version_cursor,
            'op'             => $t->deleted_at ? 'delete' : 'upsert',
            'updated_at'     => $t->updated_at->toISOString(),
        ]);

        $nextCursor = $templates->isNotEmpty()
            ? $templates->last()->version_cursor
            : $cursor;

        return response()->json([
            'model_version' => $device->model_version,
            'items'         => $items,
            'next_cursor'   => $nextCursor,
        ]);
    }
}
