<?php

namespace App\Domain\Member\Services;

use App\Domain\Member\Models\Member;
use Illuminate\Support\Facades\DB;

final class MemberService
{
    public function create(array $data): Member
    {
        return DB::transaction(function () use ($data) {
            $member = Member::create(array_merge($data, ['is_active' => true]));

            activity('member')
                ->performedOn($member)
                ->log('Member created');

            return $member;
        });
    }

    public function update(Member $member, array $data): Member
    {
        return DB::transaction(function () use ($member, $data) {
            $member->update($data);

            activity('member')
                ->performedOn($member)
                ->log('Member updated');

            return $member->fresh();
        });
    }

    public function deactivate(Member $member): void
    {
        DB::transaction(function () use ($member) {
            $member->update(['is_active' => false]);

            activity('member')
                ->performedOn($member)
                ->log('Member deactivated');
        });
    }
}
