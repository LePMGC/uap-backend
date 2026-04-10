<?php

return [
    App\Modules\Connectors\Providers\ConnectorsServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
    App\Providers\TelescopeServiceProvider::class,
    PHPOpenSourceSaver\JWTAuth\Providers\LaravelServiceProvider::class,
    App\Modules\LeapLogs\Providers\LeapLogsServiceProvider::class,
];
