<?php

namespace App\Domain\User\Queries;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class UserSearchQuery
{
    public function build(?string $keyword = null): Builder
    {
        $query = User::query()
            ->select([
                'id',
                'name',
                'email',
                'created_at',
            ]);

        if ($keyword) {
            $query->where('name', 'like', "%{$keyword}%")
                  ->orWhere('email', 'like', "%{$keyword}%");
        }

        return $query;
    }
}
