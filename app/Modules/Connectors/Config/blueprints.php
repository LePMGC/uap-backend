<?php

// app/Modules/Connectors/Config/blueprints.php

return [
    'ericsson-ucip' => [
        'category_slug' => 'ericsson-ucip',
        'request_format' => 'xml',
        'response_format' => 'xml',
        'command_actions' => ['create', 'delete', 'run', 'update', 'view'],
        'commands' => [],
    ],
    'ericsson-cai' => [
        'category_slug' => 'ericsson-cai',
        'request_format' => 'mml',
        'response_format' => 'mml',
        'command_actions' => ['create', 'get', 'set'],
        'commands' => [],
    ],
    'smpp' => [
        'category_slug' => 'smpp',
        'request_format' => 'binary',
        'response_format' => 'binary',
        'command_actions' => [],
        'commands' => [],
    ],
];
