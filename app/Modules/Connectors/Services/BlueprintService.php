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
                'command_count'   => Command::where(['category_slug' => $key])->count()
            ];
        }, $blueprints, array_keys($blueprints));
    }

    /**
     * Get all commands for a specific category, enriched with Database IDs.
     */
    public function getCommandsByCategory(string $categorySlug): array
    {
        // Fetch all commands from DB
        $commands = Command::where('category_slug', $categorySlug)->get();

        return $commands->map(function ($command) {
            return [
                'id'          => $command->id,
                'category_slug'        => $command->category_slug,
                'name'        => $command->name ?? ucfirst(str_replace('_', ' ', $command->command_key)),
                'command_key'      => $command->command_key,
                'description' => $command->description ?? '',
                'action'      => $command->action ?? 'view',
            ];
        })->toArray();
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
        // 1. Load the base protocol config from the file
        $config = config("blueprints.{$slug}");

        if (!$config) {
            return null;
        }

        // 2. Fetch ALL commands for this category from the Database
        // We use keyBy('command_key') so the Provider can access them via $this->blueprint['commands']['SET']
        $dbCommands = Command::where('category_slug', $slug)
            ->get()
            ->keyBy('command_key')
            ->toArray();

        // 3. Inject the DB commands into the config array
        $config['commands'] = $dbCommands;

        return $config;
    }


    public function getCommandTree(?string $search = null): array
    {
        $blueprints = config('blueprints');

        if (!is_array($blueprints)) {
            return [];
        }

        $tree = [];

        foreach ($blueprints as $slug => $config) {
            // 1. Build the query for commands in this category
            $query = Command::where('category_slug', $slug);

            // 2. Apply filtering if search is provided
            if ($search) {
                $searchTerm = strtolower($search);

                // If the category slug itself matches the search, we show all commands in it.
                // Otherwise, we filter by name or command_key.
                if (!str_contains(strtolower($slug), $searchTerm)) {
                    $query->where(function ($q) use ($searchTerm) {
                        $q->where('name', 'ilike', "%{$searchTerm}%")
                          ->orWhere('command_key', 'ilike', "%{$searchTerm}%");
                    });
                }
            }

            $commands = $query->get();

            // 3. Only add the category to the tree if it has commands matching the filter
            if ($commands->isNotEmpty()) {
                $tree[] = [
                    'slug'     => $slug,
                    'name'     => $config['name'] ?? ucfirst(str_replace('-', ' ', $slug)),
                    'commands' => $commands->map(function ($cmd) {
                        return [
                            'id'            => $cmd->id,
                            'name'          => $cmd->name,
                            'category_slug' => $cmd->category_slug,
                            'action'        => $cmd->action ?? 'run',
                            'command_key'   => $cmd->command_key
                        ];
                    })->toArray()
                ];
            }
        }

        return $tree;
    }
}
