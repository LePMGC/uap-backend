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
            \App\Modules\Connectors\Services\UapLogger::error('Security', 'LOGIN_FAILED', [
                'username' => $credentials['username'],
                'ip' => $request->ip()
            ], 'WARNING');

            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // 2. Find the user locally
        $user = User::where('username', $credentials['username'])->first();

        // --- JIT PROVISIONING ---
        if (!$user) {
            $user = User::create([
                'username'     => $credentials['username'],
                'name'         => ucwords(str_replace(['.', '_'], ' ', $credentials['username'])),
                'phone_number' => 'LDAP_PROVISIONED',
                'password'     => Hash::make(\Illuminate\Support\Str::random(32)), 
                'must_change_password' => false, // LDAP users don't use local password reset flow
            ]);

            \App\Modules\Connectors\Services\UapLogger::info('Security', 'USER_JIT_PROVISIONED', [
                'username' => $credentials['username'],
                'assigned_role' => 'operator',
                'source' => 'LDAP_SERVER'
            ]);

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

        // 5. Build the Response
        $isLocalMode = config('auth.uap_mode', 'local') === 'local';

        return response()->json([
            'access_token'  => $token,
            /** * RESTORED: Refresh token is required for the FE 
             * to stay logged in beyond the access token TTL 
             */
            'refresh_token' => auth('api')->refresh(), 
            'token_type'    => 'bearer',
            'expires_in'    => auth('api')->factory()->getTTL() * 60,
            
            // Security Flags
            'must_change_password' => ($isLocalMode && $user->must_change_password),
            'auth_mode'            => config('auth.uap_mode'),
            
            // User Context
            'user' => [
                'username' => $user->username,
                'name'     => $user->name,
                'role'     => $user->getRoleNames()->first(), // Useful for FE routing
            ]
        ]);
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token)
    {
        $user = auth('api')->user();
        $isLocalMode = config('auth.uap_mode', 'local') === 'local';

        return response()->json([
            'access_token'  => $token,
            // Instead of calling refresh() here (which invalidates the $token),
            // we return the same token or a dedicated long-lived one.
            // For testing, let's just return the token itself as the refresh marker
            // or a manual refresh if your JWT provider supports dedicated refresh TTLs.
            'refresh_token' => $token, 
            'token_type'    => 'bearer',
            'expires_in'    => auth('api')->factory()->getTTL() * 60,
            
            'must_change_password' => ($isLocalMode && $user->must_change_password),
            'auth_mode'            => config('auth.uap_mode'),
            
            'user' => [
                'username' => $user->username,
                'name'     => $user->name,
                'role'     => $user->getRoleNames()->first(),
            ]
        ]);
    }

    /**
     * Refresh a token.
     * * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        // This is where the actual invalidation/rotation should happen
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
        if (config('auth.uap_mode') !== 'local') {
            return response()->json([
                'error' => 'Self-service password change is not available for LDAP accounts.'
            ], 403);
        }

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