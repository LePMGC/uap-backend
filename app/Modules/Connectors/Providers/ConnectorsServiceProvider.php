<?php

namespace App\Modules\Connectors\Providers;

use Illuminate\Support\ServiceProvider;

class ConnectorsServiceProvider extends ServiceProvider
{
    public function register()
    {
        // This merges your module config into the main Laravel config tree
        // under the 'providers' key.
        $this->mergeConfigFrom(
            __DIR__.'/../Config/blueprints.php', 'providers'
        );
    }

    public function boot()
    {
        // Register migrations or routes if not already done via main app
    }
}