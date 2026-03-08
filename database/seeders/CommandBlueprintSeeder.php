<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Connectors\Models\Command;
use App\Modules\Connectors\Models\CommandParameter;
use Illuminate\Support\Facades\File;

class CommandBlueprintSeeder extends Seeder
{
    public function run(): void
    {
        $category = 'ericsson-ucip';
        $path = app_path("Modules/Connectors/Blueprints/{$category}");

        if (!File::exists($path)) {
            return;
        }

        $files = File::files($path);

        foreach ($files as $file) {
            $data = include $file->getRealPath();

            // 1. Create the Command
            $command = Command::updateOrCreate(
                ['category_slug' => $category, 'command_key' => $data['method']],
                [
                    'name'          => ucwords(str_replace(['_', '-'], ' ', $data['method'])),
                    'action'        => $data['action'] ?? 'view',
                    'description'   => $data['description'] ?? '',
                    'system_params' => $data['system_params'] ?? [],
                    'is_custom'     => false,
                    // Technical users will use this template; initially, we can leave it
                    // for them to populate or generate a default XML structure here.
                    'payload_template' => null, 
                ]
            );

            // 2. Clear old parameters if re-seeding
            $command->allParameters()->delete();

            // 3. Process User Parameters
            if (!empty($data['user_params'])) {
                $this->processParameters($command->id, $data['user_params']);
            }
        }
    }

    /**
     * Recursively process parameters and nested structs
     */
    protected function processParameters($commandId, array $params, $parentId = null): void
    {
        $order = 0;
        foreach ($params as $name => $spec) {
            $parameter = CommandParameter::create([
                'command_id'   => $commandId,
                'parent_id'    => $parentId,
                'name'         => $name,
                'label'        => $spec['label'] ?? ucwords($name),
                'type'         => $spec['type'] ?? 'string',
                'is_mandatory' => $spec['mandatory'] ?? false,
                'default_value'=> $spec['default'] ?? null,
                'sort_order'   => $order++,
                'validation_rules' => [
                    'required' => $spec['mandatory'] ?? false,
                    'type'     => $spec['type'] ?? 'string'
                ]
            ]);

            // If it's a struct with nested fields, recurse
            if (isset($spec['fields']) && is_array($spec['fields'])) {
                $this->processParameters($commandId, $spec['fields'], $parameter->id);
            }
        }
    }
}