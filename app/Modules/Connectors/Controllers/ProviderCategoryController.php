<?php

namespace App\Modules\Connectors\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Services\BlueprintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ProviderCategoryController extends Controller implements HasMiddleware
{
    public function __construct(
        protected BlueprintService $blueprintService
    ) {
    }

    /**
     * Set up middleware for the controller.
     */
    public static function middleware(): array
    {
        return [
            // Absolute baseline requirement: User must provide a valid API token
            new Middleware('auth:api'),

            // 1. Category and navigational tree listing permissions
            new Middleware(
                \Spatie\Permission\Middleware\PermissionMiddleware::using('view_provider_categories'),
                only: ['index', 'tree']
            ),

            // 2. Structural metadata blueprints query rights (Command structures and contracts)
            new Middleware(
                \Spatie\Permission\Middleware\PermissionMiddleware::using('view_command_blueprints'),
                only: ['blueprints', 'showBlueprint']
            ),
        ];
    }

    /**
     * List available protocols.
     */
    public function index(): JsonResponse
    {
        $categories = $this->blueprintService->getCategories();
        return response()->json($categories);
    }

    /**
     * List commands within a category.
     * Response now includes the 'id' for the Frontend to use in Batch Jobs.
     */
    public function blueprints(string $slug): JsonResponse
    {
        $commands = $this->blueprintService->getCommandsByCategory($slug);

        if (empty($commands)) {
            return response()->json(['message' => 'Category not found or empty'], 404);
        }

        return response()->json($commands);
    }

    /**
     * Get the specific "Contract" for a command using its Database ID.
     */
    public function showBlueprint(int $id): JsonResponse
    {
        $details = $this->blueprintService->getCommandDetailsById($id);

        if (!$details) {
            return response()->json(['message' => 'Blueprint metadata not found'], 404);
        }

        return response()->json($details);
    }

    /**
        * Get tree hierarchical representation of categories and commands for navigation.
    */
    public function tree(\Illuminate\Http\Request $request): JsonResponse
    {
        $search = $request->query('search');
        $user = auth()->user();

        // Pass the authenticated operator instance down to check dynamic action verbs
        $tree = $this->blueprintService->getCommandTree($search, $user);

        return response()->json($tree);
    }
}
