<?php

// app/Modules/Connectors/Config/blueprints.php

return [
    'ericsson-ucip' => [
        'category_slug' => 'ericsson-ucip',
        'response_format' => 'xml',
        'commands' => array_reduce(glob(__DIR__ . '/../Blueprints/ericsson-ucip/*.php'), function ($result, $file) {
            $result[basename($file, '.php')] = include $file;
            return $result;
        }, []),
    ],
    'ericsson-cai' => [
        'category_slug' => 'ericsson-cai',
        'response_format' => 'mml',
        'commands' => array_reduce(glob(__DIR__ . '/../Blueprints/ericsson-cai/*.php'), function ($result, $file) {
            $result[basename($file, '.php')] = include $file;
            return $result;
        }, []),
    ],
];