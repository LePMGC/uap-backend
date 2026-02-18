<?php

namespace App\Modules\Core\UserManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\UserManagement\Services\AuthDriverFactory;
use App\Modules\Core\UserManagement\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class AuthController extends Controller
{

    public static function middleware(): array
    {
        return [
        ];
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        // 1. LDAP/Local verification
        $authDriver = AuthDriverFactory::make();
        if (!$authDriver->authenticate($credentials['username'], $credentials['password'])) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // 2. Find the user locally
        $user = User::where('username', $credentials['username'])->first();

        // --- JIT PROVISIONING ---
        // If the user authenticated via LDAP but doesn't exist locally, create them.
        if (!$user) {
            $user = User::create([
                'username'     => $credentials['username'],
                'name'         => ucwords(str_replace(['.', '_'], ' ', $credentials['username'])),
                'phone_number' => 'LDAP_PROVISIONED',
                // Use Str::random(32) instead of str_random(32)
                'password'     => Hash::make(Str::random(32)), 
            ]);

            // Assign the 'operator' role
            $user->assignRole('operator'); 
        }

        // 3. Blocked Status Check
        if ($user->is_blocked) {
            return response()->json([
                'error' => 'Your account is deactivated. Please contact an administrator.'
            ], 403);
        }

        // 4. Generate JWT Token
        $token = auth('api')->login($user);

        return $this->respondWithToken($token);
    }

    protected function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'refresh_token' => auth('api')->refresh(), // Generates a new refreshable token
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60, // usually 15-60 mins
            'refresh_expires_in' => config('jwt.refresh_ttl') * 60, // usually 2 weeks
        ]);
    }

    public function refresh()
    {
        return $this->respondWithToken(auth('api')->refresh());
    }

    public function logout()
    {
        auth('api')->logout(); // invalidates the token

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

}