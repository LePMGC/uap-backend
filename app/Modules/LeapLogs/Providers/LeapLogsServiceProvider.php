<?php

namespace App\Modules\LeapLogs\Providers;

use Illuminate\Support\ServiceProvider;
use App\Modules\LeapLogs\Services\LeapLogParser;

class LeapLogsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the Parser as a singleton since it is a stateless utility
        $this->app->singleton(LeapLogParser::class, function ($app) {
            return new LeapLogParser();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load module routes
        $this->loadRoutesFrom(__DIR__ . '/../../../../routes/api.php');

        // If you decide to add specific views or migrations for LeapLogs later:
        // $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'leap-logs');
        // $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
