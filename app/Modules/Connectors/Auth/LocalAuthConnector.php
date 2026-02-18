<?php

namespace App\Modules\Connectors\Auth;

use App\Modules\Core\UserManagement\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class LocalAuthConnector implements AuthConnectorInterface
{
    /**
     * Authenticate using the local database.
     */
    public function authenticate(string $username, string $password): bool
    {
        Log::info("LocalAuthConnector: Attempting local login for User=$username");

        // 1. Find the user in the local database
        $user = User::where('username', $username)->first();

        // 2. If user doesn't exist, fail
        if (!$user) {
            Log::warning("LocalAuthConnector: User not found [$username]");
            return false;
        }

        // 3. Verify the password hash
        if (Hash::check($password, $user->password)) {
            Log::info("LocalAuthConnector: Authentication successful for [$username]");
            return true;
        }

        Log::error("LocalAuthConnector: Invalid credentials for [$username]");
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