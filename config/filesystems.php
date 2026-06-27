<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /**
         * 1. Private Disk Layer: For high-security telecom batch input files.
         * Stores lists of MSISDNs or MSISDN+Bundle sheets away from public access.
         */
        'secure_reimbursements' => [
            'driver' => 'local',
            'root' => storage_path('app/secure_reimbursements'),
            'throw' => true, // Throws exception immediately if disk writes or permissions fail
        ],

        /**
         * 2. Public Evidence Disk Layer: For physical validation proof attachments.
         * Files are stored under storage/app/public/reimbursement_evidence and are
         * exposed via standard web routes using the application symlink.
         */
        'reimbursement_attachments' => [
            'driver' => 'local',
            'root' => storage_path('app/public/reimbursement_evidence'),
            'url' => env('APP_URL') . '/storage/reimbursement_evidence',
            'visibility' => 'public',
            'throw' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
        // Automatically bridges your specific attachments path to the public directory
        public_path('storage/reimbursement_evidence') => storage_path('app/public/reimbursement_evidence'),
    ],

];
