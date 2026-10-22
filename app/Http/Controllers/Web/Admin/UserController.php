<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\StoreUserRequest;
use App\Http\Requests\Web\Admin\UpdateUserRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('user.manage');

        $search = $request->query('search');
        $roleFilter = $request->query('role');

        $users = User::with('roles')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($roleFilter, function ($query, $roleFilter) {
                $query->role($roleFilter);
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        $roles = Role::orderBy('name')->get();

        return view('admin.users.index', compact('users', 'roles', 'search', 'roleFilter'));
    }

    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $user->assignRole($data['role']);

        activity('user')
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->withProperties(['role' => $data['role'], 'email' => $user->email])
            ->log("User {$user->name} created with role {$data['role']}");

        return redirect()->route('admin.users.index')
            ->with('success', "Pengguna {$user->name} berhasil ditambahkan.");
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $data = $request->validated();

        $updateData = [
            'name'  => $data['name'],
            'email' => $data['email'],
        ];

        if (!empty($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
        }

        $user->update($updateData);
        $user->syncRoles([$data['role']]);

        activity('user')
            ->performedOn($user)
            ->causedBy(auth()->user())
            ->withProperties(['role' => $data['role'], 'email' => $user->email])
            ->log("User {$user->name} updated");

        return redirect()->route('admin.users.index')
            ->with('success', "Data pengguna {$user->name} diperbarui.");
    }

    public function destroy(User $user)
    {
        $this->authorize('user.manage');

        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'Anda tidak dapat menghapus akun Anda sendiri.');
        }

        $name = $user->name;
        $user->delete();

        activity('user')
            ->causedBy(auth()->user())
            ->withProperties(['deleted_user_name' => $name])
            ->log("User {$name} deleted");

        return redirect()->route('admin.users.index')
            ->with('success', "Pengguna {$name} telah dihapus.");
    }
}
