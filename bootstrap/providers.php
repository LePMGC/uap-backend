<?php

return [
    App\Providers\AppServiceProvider::class,
    PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider::class, // Add this if missing
    App\Modules\Connectors\Providers\ConnectorsServiceProvider::class,
];
