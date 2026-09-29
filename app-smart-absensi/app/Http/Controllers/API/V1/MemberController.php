<?php

namespace App\Http\Controllers\API\V1;

use App\Domain\Member\Models\Member;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    /**
     * List all active members (used by admin dashboard & edge node template list).
     */
    public function index(Request $request): JsonResponse
    {
        $members = Member::query()
            ->when($request->department, fn ($q) => $q->where('department', $request->department))
            ->when($request->branch,     fn ($q) => $q->where('branch', $request->branch))
            ->when($request->active !== null, fn ($q) => $q->where('is_active', (bool) $request->active))
            ->select(['id', 'employee_number', 'name', 'department', 'branch', 'organization', 'is_active', 'embedding_updated_at'])
            ->paginate(50);

        return response()->json($members);
    }

    /**
     * Store a new member (face embedding akan diisi via sync dari edge node).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_number' => ['required', 'string', 'max:50', 'unique:members,employee_number'],
            'name'            => ['required', 'string', 'max:100'],
            'department'      => ['nullable', 'string', 'max:100'],
            'branch'          => ['nullable', 'string', 'max:100'],
            'organization'    => ['nullable', 'string', 'max:100'],
        ]);

        $member = Member::create(array_merge($validated, ['is_active' => true]));

        return response()->json(['status' => 'created', 'data' => $member], 201);
    }

    /**
     * Show a single member (tanpa face_embedding, sudah di-hide oleh model).
     */
    public function show(Member $member): JsonResponse
    {
        return response()->json($member);
    }

    /**
     * Update member profile (bukan embedding — embedding hanya via sync edge node).
     */
    public function update(Request $request, Member $member): JsonResponse
    {
        $validated = $request->validate([
            'name'         => ['sometimes', 'string', 'max:100'],
            'department'   => ['nullable', 'string', 'max:100'],
            'branch'       => ['nullable', 'string', 'max:100'],
            'organization' => ['nullable', 'string', 'max:100'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);

        $member->update($validated);

        return response()->json(['status' => 'updated', 'data' => $member]);
    }

    /**
     * Soft-deactivate a member (jangan hard delete untuk audit trail).
     */
    public function destroy(Member $member): JsonResponse
    {
        $member->update(['is_active' => false]);

        return response()->json(['status' => 'deactivated']);
    }
}
