<?php
namespace App\Modules\Connectors\DataSources;

interface DataSourceInterface
{
    /**
     * Test if the provided configuration can successfully connect.
     */
    public function testConnection(array $config): bool;

    /**
     * Fetch data and return it as a collection or generator for the batch job.
     */
    public function fetchData(array $config): iterable;
}