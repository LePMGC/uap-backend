<?php

namespace App\Modules\Connectors\Auth;

use App\Modules\Core\UserManagement\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Modules\Connectors\Services\UapLogger;

class LocalAuthConnector implements AuthConnectorInterface
{
    /**
     * Authenticate using the local database.
     */
    public function authenticate(string $username, string $password): bool
    {
        $user = User::where('username', $username)->first();

        if (!$user) {
            UapLogger::error('Security', 'AUTH_FAILED_LOCAL_MISSING_USER', [
                'attempted_username' => $username
            ], 'WARNING');
            return false;
        }

        if (Hash::check($password, $user->password)) {
            UapLogger::info('Security', 'AUTH_SUCCESS_LOCAL', [
                'user_id' => $user->id,
                'username' => $username
            ]);
            return true;
        }

        UapLogger::error('Security', 'AUTH_FAILED_LOCAL_WRONG_PW', [
            'username' => $username
        ], 'WARNING');
        
        return false;
    }

    /**
     * Retrieve local user attributes.
     */
    public function getUserAttributes(string $username): array
    {
        $user = User::where('username', $username)->first();
        
        return $user ? $user->toArray() : [];
    }
}