<?php

namespace App\Modules\Connectors\Providers;

use Illuminate\Support\ServiceProvider;

class ConnectorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // This line tells Laravel to include your file in the global config array
        $this->mergeConfigFrom(
            __DIR__.'/../Config/blueprints.php', 'blueprints'
        );
    }

    public function boot(): void
    {
        // ... other boot logic like loading routes/migrations
    }
}