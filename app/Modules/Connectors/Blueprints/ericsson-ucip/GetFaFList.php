<?php

return [
    'method' => 'GetFaFList',
    'action' => 'view',
    'description' => 'Retrieve the Friends and Family list for a specific subscriber',
    
    // System-managed parameters
    'system_params' => [
        'originNodeType'      => 'EXT',
        'originHostName'      => '{host_name}',
        'originTransactionID' => '{auto_gen_id}', // Updated here
        'originTimeStamp'     => '{auto_gen_iso8601}',
        'requestedOwner'      => 1,
    ],

    // User-provided parameters
    'user_params' => [
        'subscriberNumber' => [
            'type' => 'string',
            'mandatory' => true,
            'label' => 'Subscriber Number (MSISDN)'
        ],
        'subscriberNumberNAI' => [
            'type' => 'int',
            'mandatory' => false,
            'default' => 1,
            'label' => 'NAI'
        ]
    ]
];