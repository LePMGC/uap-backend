<?php

return [
    'name' => 'Send SMS',
    'description' => 'Send a short message via SMSC using SMPP SubmitSM PDU',
    'method' => 'submit_sm',
    
    // System params handled by the engine/driver
    'system_params' => [
        'service_type' => 'CMT',
        'source_addr_ton' => 1, // International
        'source_addr_npi' => 1, // ISDN
        'esm_class' => 0,
        'protocol_id' => 0,
        'priority_flag' => 1,
        'registered_delivery' => 1, // Request delivery receipt
        'replace_if_present_flag' => 0,
        'sm_default_msg_id' => 0,
    ],

    // Fields mapped from the UI or CSV Batch
    'user_params' => [
        'source_addr' => [
            'label' => 'Sender ID',
            'type' => 'string',
            'description' => 'Alphanumeric or Shortcode sender name',
            'is_mandatory' => true,
        ],
        'destination_addr' => [
            'label' => 'Recipient MSISDN',
            'type' => 'string',
            'description' => 'Phone number in international format',
            'is_mandatory' => true,
        ],
        'short_message' => [
            'label' => 'Message Content',
            'type' => 'text',
            'description' => 'The text body of the SMS',
            'is_mandatory' => true,
        ],
        'data_coding' => [
            'label' => 'Encoding',
            'type' => 'select',
            'options' => [0 => 'GSM 7-bit', 8 => 'UCS2 (Unicode)', 3 => 'ISO-8859-1'],
            'default' => 0,
            'is_mandatory' => false,
        ],
    ],
];