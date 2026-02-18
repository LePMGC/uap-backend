<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CAI / EDA Response Codes
    |--------------------------------------------------------------------------
    | Based on standard Ericsson MML / CAI protocol definitions.
    */
    'responses' => [
        0    => 'Successful',
        1    => 'Partially Successful',
        101  => 'Subscriber Not Found',
        102  => 'Subscriber Already Exists',
        105  => 'Invalid Parameter',
        110  => 'Database Error',
        201  => 'Authentication Failed',
        4002 => 'Equipment Not Found',
        // Add more codes as per your technical manual
    ],
];