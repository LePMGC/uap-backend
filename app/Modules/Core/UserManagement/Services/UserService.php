<?php

namespace App\Modules\Core\UserManagement\Services;

use App\Modules\Core\UserManagement\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UserService
{
    /**
     * Get all users with optional filtering and pagination.
     */
    public function getAllUsers(array $filters = []): LengthAwarePaginator
    {
        $query = User::query()->with('roles');

        if (!empty($filters['name'])) {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }
        if (!empty($filters['username'])) {
            $query->where('username', 'like', '%' . $filters['username'] . '%');
        }
        if (!empty($filters['email'])) {
            $query->where('email', 'like', '%' . $filters['email'] . '%');
        }
        if (!empty($filters['phone_number'])) {
            $query->where('phone_number', 'like', '%' . $filters['phone_number'] . '%');
        }

        return $query->paginate($filters['per_page'] ?? 15);
    }

    public function getUserById(int $id): User
    {
        return User::with('roles.permissions')->findOrFail($id);
    }

    public function createUser(array $data): User
    {
        $user = User::create([
            'username'     => $data['username'],
            'name'         => $data['name'],
            'email'        => $data['email'] ?? null,
            'phone_number' => $data['phone_number'],
            'password'     => Hash::make($data['password']),
        ]);

        if (!empty($data['role'])) {
            $user->assignRole($data['role']);
        }

        return $user;
    }

    public function updateUser(int $id, array $data): User
    {
        $user = User::findOrFail($id);
        
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update(array_filter($data));

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }

        return $user;
    }

    public function deleteUser(int $id): bool
    {
        $user = User::findOrFail($id);
        return $user->delete();
    }

    public function updateUserStatus(int $id, bool $blocked): User
    {
        // 1. Business Logic: Prevent a user from blocking/unblocking themselves
        if (auth()->id() === $id) {
            $action = $blocked ? 'block' : 'unblock';
            throw new AccessDeniedHttpException("You cannot {$action} your own account.");
        }

        $user = User::findOrFail($id);

        // 2. Business Logic: Prevent redundant status updates
        if ($user->is_blocked === $blocked) {
            $status = $blocked ? 'blocked' : 'unblocked';
            throw new ConflictHttpException("User is already {$status}.");
        }

        $user->is_blocked = $blocked;
        $user->save();

        return $user;
    }
}