<?php

namespace App\Http\Controllers\Web\User;

use App\Http\Controllers\Controller;
use App\Domain\User\DTOs\UserData;
use App\Domain\User\Queries\UserSearchQuery;
use App\Domain\User\Services\CreateUser;
use App\Http\Requests\Web\User\StoreUserRequest;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request, UserSearchQuery $query)
    {
        $keyword = $request->query('keyword');
        
        $users = $query->build($keyword)->paginate(50);

        // Here we would typically return a view, e.g.:
        // return view('users.index', compact('users'));
        
        // For now, since the view doesn't exist, we return a simple representation
        return response("This is the Web User Index. Total users: " . $users->total());
    }

    public function store(StoreUserRequest $request, CreateUser $createUser)
    {
        $userData = UserData::fromArray($request->validated());
        
        $user = $createUser->execute($userData);

        // Here we would typically redirect, e.g.:
        // return redirect()->route('users.index')->with('success', 'User created successfully!');
        
        return redirect('/users')->with('success', 'User created successfully!');
    }
}
