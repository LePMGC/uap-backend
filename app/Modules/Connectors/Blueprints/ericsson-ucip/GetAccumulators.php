<?php

return [
    'method' => 'GetAccumulators',
    'action' => 'view',
    'description' => 'Retrieve accumulator information',
    'system_params' => [
        'originNodeType' => 'EXT',
        'originHostName' => '{host_name}',
        'originTransactionID' => '{auto_gen_id}',
        'originTimeStamp' => '{auto_gen_iso8601}',
    ],
    // 2. User Parameters (Frontend uses this to build the form)
    'user_params' => [
        'subscriberNumber' => [
            'type' => 'string',
            'mandatory' => true,
            'default' => '',
            'label' => 'Subscriber Number (MSISDN)'
        ],
        'subscriberNumberNAI' => [
            'type' => 'string',
            'mandatory' => false,
            'default' => '1',
            'label' => 'NAI'
        ],
        'accumulatorSelection' => [
            'type' => 'struct',
            'mandatory' => false,
            'fields' => [
                'accumulatorIDFirst' => ['type' => 'int', 'label' => 'ID First'],
                'accumulatorIDLast'  => ['type' => 'int', 'label' => 'ID Last'],
            ]
        ],
        'messageCapabilityFlag' => [
            'type' => 'struct',
            'mandatory' => false,
            'fields' => [
                'promotionNotificationFlag' => ['type' => 'boolean', 'label' => 'Promotion Notification'],
                'firstIVRCallSetFlag'      => ['type' => 'boolean', 'label' => 'First IVR Call Set'],
                'accountActivationFlag'    => ['type' => 'boolean', 'label' => 'Account Activation'],
            ]
        ],
        'negotiatedCapabilities' => [
            'type' => 'int',
            'mandatory' => false,
            'label' => 'Negotiated Capabilities'
        ]
    ]
];