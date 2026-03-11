<?php

// app/Modules/Connectors/Config/blueprints.php

return [
    'ericsson-ucip' => [
        'category_slug' => 'ericsson-ucip',
        'request_format' => 'xml',
        'response_format' => 'xml',
        'command_actions' => ['create', 'delete', 'run', 'update', 'view'],
        'commands' => array_reduce(glob(__DIR__ . '/../Blueprints/ericsson-ucip/*.php'), function ($result, $file) {
            $result[basename($file, '.php')] = include $file;
            return $result;
        }, []),
    ],
    'ericsson-cai' => [
        'category_slug' => 'ericsson-cai',
        'request_format' => 'mml',
        'response_format' => 'mml',
        'command_actions' => ['create', 'get', 'set'],
        'commands' => array_reduce(glob(__DIR__ . '/../Blueprints/ericsson-cai/*.php'), function ($result, $file) {
            $result[basename($file, '.php')] = include $file;
            return $result;
        }, []),
    ],
    'smpp' => [
        'category_slug' => 'smpp',
        'request_format' => 'binary', 
        'response_format' => 'binary',
        'command_actions' => [],
        'commands' => array_reduce(glob(__DIR__ . '/../Blueprints/smpp/*.php'), function ($result, $file) {
            $result[basename($file, '.php')] = include $file;
            return $result;
        }, []),
    ],
];