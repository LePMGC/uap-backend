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

    public static function middleware(): array
    {
        return [
            new Middleware('auth:api'),
            new Middleware('permission:view_provider_categories', only: ['index']),
            new Middleware('permission:view_command_blueprints', only: ['blueprints', 'showBlueprint']),
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

    public function tree(): JsonResponse
    {
        $tree = $this->blueprintService->getCommandTree();
        return response()->json($tree);
    }
}
