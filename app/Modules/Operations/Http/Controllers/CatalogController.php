<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public static function middleware(): array
    {
        return [
            'auth:api',
        ];
    }

    /**
     * GET /api/operations/catalog/bundles
     * Pulls active catalog variations along with active validity rules maps.
     */
    public function getBundlesForFrontend(Request $request): JsonResponse
    {
        $excludedCategories = [
            'ASSOCIATION',
            'INACTIVEDB',
            'OLD_RESIDENTIAL',
            'STOP',
        ];

        $products = DB::table('catalog_products')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotIn('type', $excludedCategories)
            ->orderBy('type', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        $categories = $products->pluck('type')
            ->unique()
            ->map(function ($type) {
                return ucfirst(strtolower($type));
            })
            ->values()
            ->toArray();

        $bundles = $products->map(function ($product) {
            return [
                'id'             => $product->id,
                'offer_id'       => $product->offer_id,
                'name'           => $product->name,
                'category'       => ucfirst(strtolower($product->type)),
                'price'          => number_format((float) $product->cost, 0, '.', ' ').' F',
                'validity'       => $product->validity ? (int) $product->validity : null,
                'validity_units' => $product->validity_units ? strtoupper($product->validity_units) : null,
            ];
        })->values()->toArray();

        return response()->json([
            'success' => true,
            'data'    => [
                'categories' => $categories,
                'bundles'    => $bundles,
            ],
        ]);
    }


    /**
     * GET /api/operations/catalog/bundle-categories
     *
     * Returns available bundle categories/types
     * for provisioning profile configuration.
     */
    public function getBundleCategories(Request $request): JsonResponse
    {
        $excludedCategories = [
            'ASSOCIATION',
            'INACTIVEDB',
            'OLD_RESIDENTIAL',
            'STOP',
        ];


        $categories = DB::table('catalog_products')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereNotIn('type', $excludedCategories)
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->map(function ($type) {
                return ucfirst(strtolower($type));
            })
            ->values()
            ->toArray();


        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }
}
