<?php

namespace App\Modules\Connectors\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Connectors\Models\DataSource;
use App\Modules\Connectors\DataSources\DataSourceFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class DataSourceController extends Controller implements HasMiddleware
{

   public static function middleware(): array
    {
        return [
            new Middleware('auth:api'),
            new Middleware('permission:view_datasources', only: ['index', 'show']),
            new Middleware('permission:create_datasources', only: ['store']),
            new Middleware('permission:edit_datasources', only: ['update']),
            new Middleware('permission:delete_datasources', only: ['destroy']),
            new Middleware('permission:test_datasources', only: ['testConnection']),
        ];
    }

    /**
     * Display a listing of the data sources.
     */
    public function index()
    {
        $sources = DataSource::with('creator:id,username')->get();
        return response()->json($sources);
    }

    /**
     * Store a newly created data source.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:sftp,database,api,upload',
            'connection_settings' => 'required|array',
        ]);

        $source = DataSource::create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'connection_settings' => $validated['connection_settings'],
            'created_by' => Auth::id(),
        ]);

        return response()->json($source, 201);
    }

    /**
     * Test a connection before (or after) saving.
     */
    public function testConnection(Request $request)
    {
        $request->validate([
            'type' => 'required|in:sftp,database,api,upload',
            'connection_settings' => 'required|array',
        ]);

        try {
            $connector = DataSourceFactory::make($request->type);
            $success = $connector->testConnection($request->connection_settings);

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Connection failed. Please check your settings.'
                ], 422); // Using 422 to indicate the settings are "unusable"
            }

            return response()->json([
                'success' => true,
                'message' => 'Connection successful!'
            ], 200);

        } catch (\Exception $e) {
            // This catches logic crashes, missing drivers, or syntax errors
            return response()->json([
                'success' => false, 
                'message' => 'An internal error occurred during testing: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified data source.
     */
    public function show(DataSource $dataSource)
    {
        // The connection_settings are automatically decrypted via the Model Cast
        return response()->json($dataSource->load('creator:id,username'));
    }

    /**
     * Update the specified data source in storage.
     */
    public function update(Request $request, $id)
    {
        $dataSource = DataSource::find($id);

        if (!$dataSource) {
            return response()->json([
                'success' => false,
                'message' => 'Data source not found.'
            ], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:sftp,database,api,upload',
            'connection_settings' => 'sometimes|required|array',
            'is_active' => 'sometimes|boolean'
        ]);

        $dataSource->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Data source updated successfully.',
            'data' => $dataSource
        ]);
    }

    /**
     * Remove the specified data source.
     */
    public function destroy($id)
    {
        $dataSource = DataSource::find($id);

        if (!$dataSource) {
            return response()->json([
                'success' => false,
                'message' => 'Data source not found.'
            ], 404);
        }

        $dataSource->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data source deleted successfully.'
        ]);
    }
}