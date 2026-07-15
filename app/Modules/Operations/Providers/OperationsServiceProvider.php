<?php

namespace App\Modules\Operations\Providers;

use Illuminate\Support\ServiceProvider;
use App\Modules\Operations\Models\ProvisioningRequest;
use App\Modules\Operations\Observers\ProvisioningRequestObserver;

class OperationsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind interface/service mappings here if needed in the future
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register the Model Observer for Provisioning Requests
        ProvisioningRequest::observe(ProvisioningRequestObserver::class);
    }
}
