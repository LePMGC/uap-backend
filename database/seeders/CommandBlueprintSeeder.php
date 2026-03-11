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
        // 1. Get all categories defined in the central config
        $blueprints = config('blueprints');

        if (!is_array($blueprints)) {
            $this->command->error("Blueprints configuration not found. Ensure the ServiceProvider merges the config.");
            return;
        }

        foreach ($blueprints as $categorySlug => $categoryConfig) {
            $this->command->info("Seeding category: {$categorySlug}");

            // Define the path to the blueprint files for this specific category
            $path = app_path("Modules/Connectors/Blueprints/{$categorySlug}");

            if (!File::exists($path)) {
                $this->command->warn("Directory missing for {$categorySlug} at: {$path}");
                continue;
            }

            $files = File::files($path);

            foreach ($files as $file) {
                // Ensure we only process PHP files
                if ($file->getExtension() !== 'php') continue;

                $data = include $file->getRealPath();
                $commandKey = basename($file->getFilename(), '.php');

                // 2. Update or Create the Command record
                $command = Command::updateOrCreate(
                    [
                        'category_slug' => $categorySlug, 
                        'command_key'   => $commandKey
                    ],
                    [
                        'name'             => $data['name'] ?? ucwords(str_replace(['_', '-'], ' ', $commandKey)),
                        'action'           => $data['action'] ?? 'view',
                        'description'      => $data['description'] ?? '',
                        'system_params'    => $data['system_params'] ?? [],
                        'is_custom'        => false,
                        'request_payload' => $data['request_payload'] ?? null, 
                    ]
                );
            }
        }
    }
}