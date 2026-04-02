<?php

return [
    // Default to a writable directory inside the project if the external path fails
    'log_path' => env('UAP_LOG_PATH', storage_path('logs')),
    'log_prefix' => env('UAP_LOG_PREFIX', 'application'),
];
