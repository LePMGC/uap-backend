<?php

namespace App\Modules\Connectors\Services;

use App\Modules\Connectors\Models\Command;

class BlueprintService
{
    /**
     * Get all categories and their basic metadata.
     */
    public function getCategories(): array
    {
        $blueprints = config('blueprints');

        if (!is_array($blueprints)) {
            return [];
        }

        return array_map(function ($config, $key) {
            return [
                'slug'            => $key,
                'request_format' => $config['request_format'] ?? 'xml',
                'response_format' => $config['response_format'] ?? 'xml',
                'command_count'   => isset($config['commands']) ? count($config['commands']) : 0,
            ];
        }, $blueprints, array_keys($blueprints));
    }

    /**
     * Get all commands for a specific category, enriched with Database IDs.
     */
    public function getCommandsByCategory(string $categorySlug): array
    {
        $category = config("blueprints.{$categorySlug}");

        if (!is_array($category) || !isset($category['commands'])) {
            return [];
        }

        // Fetch IDs from database to match the file-based blueprints
        $dbCommands = Command::where('category_slug', $categorySlug)
            ->pluck('id', 'command_key') // Assuming 'command_key' matches the filename/slug
            ->toArray();

        return array_map(function ($command, $name) use ($dbCommands) {
            return [
                'id'          => $dbCommands[$name] ?? null, // The critical Database ID
                'slug'        => $name,
                'name'        => $command['name'] ?? ucfirst(str_replace('_', ' ', $name)),
                'method'      => $command['method'] ?? $name,
                'description' => $command['description'] ?? '',
                'action'      => $command['action'] ?? 'view',
            ];
        }, $category['commands'], array_keys($category['commands']));
    }

    /**
     * Get full details using the Database ID.
     */
    public function getCommandDetailsById(int $id): ?array
    {
        $command = Command::findOrFail($id);
        
        // Merge Database record with File-based blueprint static info
        $blueprint = config("blueprints.{$command->category_slug}.commands.{$command->command_key}");
        
        if (!$blueprint) {
            return null;
        }

        return array_merge($blueprint, [
            'id' => $command->id,
            'category_slug' => $command->category_slug
        ]);
    }
}