<?php

namespace App\Modules\Connectors\Providers;

use Exception;

class ProviderFactory
{
    /**
     * Map category slugs to their respective Driver classes.
     */
    protected static array $drivers = [
        'ericsson-ucip' => UcipProvider::class,
        'ericsson-cai'  => CaiProvider::class,
        'ericsson-eda'  => CaiProvider::class, // EDA often shares the CAI protocol logic
    ];

    /**
     * Create a provider instance based on the category.
     * * @param array $instanceConfig Settings from the DB (IP, Port, Auth)
     * @param array $blueprint The static command definition from code
     */
    public static function make(array $instanceConfig, array $blueprint): BaseProvider
    {
        $categorySlug = $blueprint['category_slug'] ?? null;

        if (!isset(self::$drivers[$categorySlug])) {
            throw new Exception("No provider driver implemented for category: {$categorySlug}");
        }

        $driverClass = self::$drivers[$categorySlug];

        return new $driverClass($instanceConfig, $blueprint);
    }
}