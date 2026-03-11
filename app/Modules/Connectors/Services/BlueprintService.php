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
                'command_actions' => $config['command_actions'] ?? [],
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

    /**
     * Generate a visual payload template based on the command's category.
     */
    public function generatePayload(Command $command): string
    {
        $params = $command->parameters; // Loaded via relationship
        $category = $command->category_slug;

        return match ($category) {
            'ericsson-ucip' => $this->renderXmlPayload($command->command_key, $params),
            'ericsson-cai'  => $this->renderMmlPayload($command->command_key, $params),
            'smpp'          => $this->renderSmppPayload($command->command_key, $params),
            default         => "No generator available for category: {$category}"
        };
    }

    protected function renderXmlPayload(string $method, $params): string
    {
        $xml = "<?xml version=\"1.0\"?>\n<methodCall>\n  <methodName>{$method}</methodName>\n  <params>\n";
        foreach ($params as $p) {
            $xml .= "    <{$p->name}>{{ {$p->name} }}</{$p->name}>\n";
        }
        $xml .= "  </params>\n</methodCall>";
        return $xml;
    }

    protected function renderMmlPayload(string $method, $params): string
    {
        $mml = strtoupper($method) . ":";
        $pairs = [];
        foreach ($params as $p) {
            $pairs[] = "{$p->name}={{ {$p->name} }}";
        }
        return $mml . implode(',', $pairs) . ";";
    }

    protected function renderSmppPayload(string $method, $params): string
    {
        $pdu = "[SMPP PDU: {$method}]\n";
        foreach ($params as $p) {
            $pdu .= " - {$p->name}: {{ {$p->name} }}\n";
        }
        return $pdu;
    }

    public function getCategoryBlueprint(string $slug): ?array
    {
        return config("blueprints.{$slug}");
    }
}