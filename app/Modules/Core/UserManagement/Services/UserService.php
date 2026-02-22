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

        if (!empty($filters['name'])) {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }
        if (!empty($filters['username'])) {
            $query->where('username', 'like', '%' . $filters['username'] . '%');
        }
        if (!empty($filters['email'])) {
            $query->where('email', 'like', '%' . $filters['email'] . '%');
        }

        return $query->paginate($filters['per_page'] ?? 15);
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

        if (!empty($data['role'])) {
            $user->assignRole($data['role']);
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
     * Administrative status management (Block/Unblock).
     */
    public function updateUserStatus(int $id, bool $blocked): User
    {
        if (auth()->id() === $id) {
            throw new AccessDeniedHttpException("You cannot change your own status.");
        }

        $user = User::findOrFail($id);
        $user->is_blocked = $blocked;
        $user->save();

        UapLogger::error('Security', 'USER_STATUS_CHANGE', [
            'actor' => auth()->user()->username ?? 'SYSTEM',
            'target' => $user->username,
            'action' => $blocked ? 'ACCOUNT_DEACTIVATED' : 'ACCOUNT_REACTIVATED'
        ], 'CRITICAL');

        return $user;
    }
}