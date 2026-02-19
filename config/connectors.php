<?php

return [
    'batch' => [
        /*
        |--------------------------------------------------------------------------
        | Redis Chunk Size
        |--------------------------------------------------------------------------
        | How many rows to group into a single Redis job. 
        | Higher numbers reduce Redis overhead but increase memory usage per worker.
        */
        'chunk_size' => env('CONNECTOR_BATCH_CHUNK_SIZE', 500),

        /*
        |--------------------------------------------------------------------------
        | Storage Path
        |--------------------------------------------------------------------------
        | The directory within storage/app where batch files are kept.
        */
        'storage_path' => 'jobs',
    ],
];