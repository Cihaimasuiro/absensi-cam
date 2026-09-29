<?php

namespace App\Domain\User\Services;

use App\Domain\User\Models\User;
use App\Domain\User\DTOs\UserData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class CreateUser
{
    public function execute(UserData $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = new User();
            $user->name = $data->name;
            $user->email = $data->email;
            $user->password = Hash::make($data->password);
            
            $user->save();

            // Here you might dispatch events like: UserCreated::dispatch($user);

            return $user;
        });
    }
}
