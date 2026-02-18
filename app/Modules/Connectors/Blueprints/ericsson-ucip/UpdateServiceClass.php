<?php

return [
    'method' => 'UpdateServiceClass',
    'action' => 'update',
    'description' => 'Update an existing service class of a subscriber',
    
    // System parameters required by the Ericsson AIR node for every request
    'system_params' => [
        'originNodeType'      => 'EXT',
        'originHostName'      => '{host_name}',
        'originTransactionID' => '{auto_gen_id}', // Updated here
        'originTimeStamp'     => '{auto_gen_iso8601}',
        'requestedOwner'      => 1,
    ],

    // This command requires no user input to function
    'user_params' => []
];