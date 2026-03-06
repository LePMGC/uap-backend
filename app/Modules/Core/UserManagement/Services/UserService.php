<?php

namespace App\Modules\Core\UserManagement\Services;

use App\Modules\Core\UserManagement\Models\User;
use App\Modules\Connectors\Services\UapLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use RuntimeException;

class UserService
{
    /**
     * Get all users with optional filtering and pagination.
     */

    public function getAllUsers(array $filters = []): LengthAwarePaginator
    {
        $query = User::query()->with('roles');

        // 1. Existing Filters
        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', '%' . $filters['search'] . '%')
                ->orWhere('email', 'like', '%' . $filters['search'] . '%');
            });
        }

        // 2. Filter by Role ID
        if (!empty($filters['role'])) {
            $query->whereHas('roles', function ($q) use ($filters) {
                $q->where('id', $filters['role']);
            });
        }

        // 3. Filter by Status (active/blocked)
        if (!empty($filters['status'])) {
            $isBlocked = ($filters['status'] === 'blocked');
            $query->where('is_blocked', $isBlocked);
        }

        // --- ADD SORTING HERE ---
        $query->orderBy('created_at', 'desc');

        $paginator = $query->paginate($filters['per_page'] ?? 15);

        $paginator->getCollection()->transform(function ($user) {
            $user->role = $user->getRoleNames()->first();
            $user->role_id = $user->roles->first()->id ?? null;
            return $user;
        });

        return $paginator;
    }

    public function getUserById(int $id): User
    {
        $user = User::with('roles')->findOrFail($id);
        
        // Append the role name directly to the object
        $user->role = $user->getRoleNames()->first();
        
        return $user;
    }

    /**
     * Create a user with conditional password enforcement based on UAP_MODE.
     */
    public function createUser(array $data): array
    {
        $isLocalMode = config('auth.uap_mode', 'local') === 'local';
        
        // Generate a random password (used for Local accounts, or as a stub for LDAP)
        $temporaryPassword = Str::random(12);

        $user = User::create([
            'username'     => $data['username'],
            'name'         => $data['name'],
            'email'        => $data['email'] ?? null,
            'phone_number' => $data['phone_number'] ?? null,
            'password'     => Hash::make($temporaryPassword),
            // ONLY force change if the app manages the passwords (Local Mode)
            'must_change_password' => $isLocalMode,
            'is_blocked'   => false,
        ]);

        if (!empty($data['role_id'])) {
            $user->assignRole($data['role_id']);
        }

        UapLogger::info('Security', 'USER_CREATED', [
            'actor' => auth()->user()->username ?? 'SYSTEM',
            'new_user' => $user->username,
            'mode' => config('auth.uap_mode')
        ]);

        return [
            'user' => $user,
            'temporary_password' => $isLocalMode ? $temporaryPassword : 'Managed by LDAP/AD'
        ];
    }

    /**
     * Reset password logic - strictly forbidden for non-local accounts.
     */
    public function resetPassword(int $userId): string
    {
        if (config('auth.uap_mode', 'local') !== 'local') {
            throw new RuntimeException("Password reset is disabled in LDAP mode. Please use your Domain Controller.");
        }

        $user = User::findOrFail($userId);
        $temporaryPassword = Str::random(12);

        $user->update([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true
        ]);

        UapLogger::warning('Security', 'USER_PASSWORD_RESET_BY_ADMIN', [
            'admin_id' => auth()->id(),
            'target_user' => $user->username
        ]);

        return $temporaryPassword;
    }

    /**
     * Update password for local users (clears the reset flag).
     */
    public function updatePassword(int $userId, string $newPassword): void
    {
        if (config('auth.uap_mode', 'local') !== 'local') {
            throw new RuntimeException("Password updates must be performed in the LDAP directory.");
        }

        $user = User::findOrFail($userId);
        $user->update([
            'password' => Hash::make($newPassword),
            'must_change_password' => false // User has successfully set their own password
        ]);

        UapLogger::info('Security', 'USER_PASSWORD_CHANGED_SELF', [
            'user_id' => $user->id,
            'username' => $user->username
        ]);
    }

    /**
     * Update user details and sync roles.
     */
    public function updateUser(int $id, array $data): User
    {
        $user = User::findOrFail($id);

        // Update basic info
        $user->update(collect($data)->except('role_id')->toArray());

        // Handle Role Update if role_id is provided
        if (isset($data['role_id'])) {
            $role = \Spatie\Permission\Models\Role::findOrFail($data['role_id']);
            $user->syncRoles([$role->name]);
        }

        UapLogger::info('User Management', 'USER_UPDATED', [
            'actor' => auth()->user()->username ?? 'SYSTEM',
            'target' => $user->username,
            'changes' => array_keys($data)
        ]);

        return $user->load('roles');
    }

    /**
     * Delete a user with safety checks.
     */
    public function deleteUser(int $id): void
    {
        if (auth()->id() === $id) {
            throw new AccessDeniedHttpException("You cannot delete your own account.");
        }

        $user = User::findOrFail($id);

        $user->delete();

        \App\Modules\Connectors\Services\UapLogger::error('Security', 'USER_DELETED', [
            'admin_id' => auth()->id(),
            'deleted_username' => $user->username ?? 'Unknown'
        ], 'CRITICAL');
    }

    /**
     * Update user status (is_blocked)
     */
    public function updateUserStatus(int $id, $status){
        if (auth()->id() === $id) {
            throw new AccessDeniedHttpException("You cannot update status of your own account.");
        }

        $user = User::findOrFail($id);
        $user->is_blocked = $status;
        $user->save();

        return $user;
    }
}