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

        // LOG: Critical Security Event
        \App\Modules\Connectors\Services\UapLogger::error('Security', 'USER_STATUS_CHANGED', [
            'actor_id'    => auth()->id() ?? 'SYSTEM',
            'target_user' => $user->username,
            'from'        => $oldStatus,
            'to'          => $newStatus,
        ], 'CRITICAL');

        return $user;
    }

    public function deleteUser(int $id): bool
    {
        $user = User::findOrFail($id);

        // LOG: Irreversible action
        \App\Modules\Connectors\Services\UapLogger::error('Security', 'USER_PERMANENTLY_DELETED', [
            'actor_id' => auth()->id() ?? 'SYSTEM',
            'username' => $user->username,
            'user_id'  => $user->id
        ], 'CRITICAL');

        return $user->delete();
    }

    public function updateUserStatus(int $id, bool $blocked): User
    {
        // Business Logic: Prevent self-blocking (already in your service)
        if (auth()->id() === $id) {
            throw new AccessDeniedHttpException("You cannot change your own status.");
        }

        $user = User::findOrFail($id);
        $oldStatus = $user->is_blocked ? 'BLOCKED' : 'ACTIVE';
        $newStatus = $blocked ? 'BLOCKED' : 'ACTIVE';

        $user->is_blocked = $blocked;
        $user->save();

        // LOG: Security compliance audit
        \App\Modules\Connectors\Services\UapLogger::error('Security', 'USER_STATUS_CHANGE', [
            'actor' => auth()->user()->username ?? 'SYSTEM',
            'target' => $user->username,
            'action' => $blocked ? 'ACCOUNT_DEACTIVATED' : 'ACCOUNT_REACTIVATED',
            'previous_state' => $oldStatus,
            'new_state' => $newStatus
        ], 'CRITICAL');

        return $user;
    }
}