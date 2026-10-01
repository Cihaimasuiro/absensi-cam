<?php

namespace App\Http\Controllers\API\V1\User;

use App\Http\Controllers\Controller;
use App\Domain\User\DTOs\UserData;
use App\Domain\User\Queries\UserSearchQuery;
use App\Domain\User\Services\CreateUser;
use App\Http\Requests\API\V1\User\StoreUserRequest;
use App\Http\Resources\API\V1\UserResource;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request, UserSearchQuery $query)
    {
        $keyword = $request->query('keyword');
        
        $users = $query->build($keyword)->paginate(50);

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request, CreateUser $createUser)
    {
        $userData = UserData::fromArray($request->validated());
        
        $user = $createUser->execute($userData);

        return new UserResource($user);
    }
}
