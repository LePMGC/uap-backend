<?php

namespace App\Modules\Core\UserManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\UserManagement\Services\AuthDriverFactory;
use App\Modules\Core\UserManagement\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use App\Modules\Core\UserManagement\Services\UserService;

class AuthController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

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
            // LOG: Failed login attempt (Potential Brute Force)
            \App\Modules\Connectors\Services\UapLogger::error('Security', 'LOGIN_FAILED', [
                'username' => $credentials['username'],
                'ip' => $request->ip()
            ], 'WARNING');

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

            // LOG: New user discovered and provisioned via LDAP
            \App\Modules\Connectors\Services\UapLogger::info('Security', 'USER_JIT_PROVISIONED', [
                'username' => $credentials['username'],
                'assigned_role' => 'operator',
                'source' => 'LDAP_SERVER'
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

        \App\Modules\Connectors\Services\UapLogger::info('Security', 'LOGIN_SUCCESS', [
            'user_id' => $user->id,
            'username' => $user->username
        ]);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'must_change_password' => (bool) $user->must_change_password, // FE checks this
            'user' => [
                'username' => $user->username,
                'name' => $user->name
            ]
        ]);
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

    /**
     * Refresh the JWT token. This endpoint can be called by the frontend when the access token is close to expiring.
     */
    public function refresh()
    {
        return $this->respondWithToken(auth('api')->refresh());
    }

    /**
     * Invalidate the current token, effectively logging the user out. The frontend should also delete the token from storage upon successful logout.
     */
    public function logout()
    {
        auth('api')->logout(); // invalidates the token

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Allow users to change their password. This is especially important for users who were JIT provisioned with a random password, or for security hygiene. The frontend should call this endpoint when the user logs in for the first time (if must_change_password is true) or from a profile settings page.
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:8|confirmed|different:current_password',
        ]);

        $user = auth()->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['error' => 'Current password does not match.'], 422);
        }

        $this->userService->updatePassword($user->id, $request->new_password);

        return response()->json(['message' => 'Password changed successfully.']);
    }
}