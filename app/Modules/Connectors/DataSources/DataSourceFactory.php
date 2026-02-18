<?php

namespace App\Modules\Connectors\DataSources;

class DataSourceFactory
{
    public static function make(string $type): DataSourceInterface
    {
        return match (strtolower($type)) {
            'sftp'     => new SftpConnector(),
            'database' => new DatabaseConnector(),
            'api'      => new ApiConnector(),
            'upload'   => new LocalFileConnector(),
            default    => throw new \InvalidArgumentException("Source type [$type] not supported."),
        };
    }
}