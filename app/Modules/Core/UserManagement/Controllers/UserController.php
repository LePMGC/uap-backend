<?php

namespace App\Modules\Core\UserManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\UserManagement\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class UserController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('auth:api'),
            new Middleware('permission:manage_users'),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['name', 'username', 'email', 'phone_number', 'per_page']);
        return response()->json($this->userService->getAllUsers($filters));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->userService->getUserById($id));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username'     => 'required|string|unique:users,username',
            'name'         => 'required|string',
            'phone_number' => 'nullable|string',
            'email'        => 'nullable|email|unique:users,email',
            'role'         => 'nullable|string|exists:roles,name',
        ]);

        $user = $this->userService->createUser($validated);
        return response()->json($user, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'username'     => 'string|unique:users,username,' . $id,
            'email'        => 'nullable|email|unique:users,email,' . $id,
            'role'         => 'nullable|string|exists:roles,name',
        ]);

        $user = $this->userService->updateUser($id, $validated);
        return response()->json($user);
    }

    public function destroy(int $id): JsonResponse
    {
        if (auth()->id() === $id) {
            return response()->json(['error' => 'You cannot delete your own account'], 403);
        }

        $user = User::find($id);
        \App\Modules\Connectors\Services\UapLogger::error('Security', 'USER_DELETED', [
            'admin_id' => auth()->id(),
            'deleted_username' => $user->username ?? 'Unknown'
        ], 'CRITICAL');

        $this->userService->deleteUser($id);
        return response()->json(['message' => 'User deleted successfully']);
    }

    public function block(int $id): JsonResponse
    {
        try {
            $user = $this->userService->updateUserStatus($id, true);

            // LOG: Access Revocation
            \App\Modules\Connectors\Services\UapLogger::error('Security', 'USER_BLOCKED', [
                'admin_id' => auth()->id(),
                'target_user_id' => $id,
                'target_username' => $user->username
            ], 'CRITICAL');

            return response()->json(['message' => 'User blocked successfully', 'user' => $user]);
        } catch (AccessDeniedHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (ConflictHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function unblock(int $id): JsonResponse
    {
        try {
            $user = $this->userService->updateUserStatus($id, false);
            return response()->json(['message' => 'User unblocked successfully', 'user' => $user]);
        } catch (AccessDeniedHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (ConflictHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function resetPassword(int $id): JsonResponse
    {
        // Ensure only admins can do this (middleware already handles manage_users)
        $tempPassword = $this->userService->resetPassword($id);

        return response()->json([
            'message' => 'Password reset successful.',
            'temporary_password' => $tempPassword
        ]);
    }
}