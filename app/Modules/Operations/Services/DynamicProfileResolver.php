<?php

namespace App\Modules\Operations\Services;

use App\Modules\Operations\Models\CatalogProduct;
use App\Modules\Operations\Models\ProvisioningProfile;
use App\Modules\Operations\Exceptions\ProvisioningException;

class DynamicProfileResolver
{
    /**
     * Cache lookups in memory to prevent duplicate DB queries during batch row loops.
     */
    protected array $profileCache = [];

    public function resolveForOfferId(int|string $offerId): ProvisioningProfile
    {
        if (isset($this->profileCache[$offerId])) {
            return $this->profileCache[$offerId];
        }

        // 1. Fetch CatalogProduct by offer_id[cite: 12]
        $product = CatalogProduct::where('offer_id', $offerId)
            ->where('is_active', true)
            ->first();

        if (!$product || empty($product->type)) {
            throw new ProvisioningException("No active catalog product found for Offer ID: {$offerId}");
        }

        $productType = strtolower($product->type);

        // 2. Search active ProvisioningProfile matching catalog_product_types JSON[cite: 13]
        $profile = ProvisioningProfile::query()
            ->where('is_active', true)
            ->whereRaw("
                EXISTS (
                    SELECT 1 
                    FROM jsonb_array_elements_text(catalog_product_types::jsonb) AS elem 
                    WHERE LOWER(elem) = ?
                )
            ", [$productType])
            ->first();

        if (!$profile) {
            throw new ProvisioningException("No active ProvisioningProfile configured for catalog product type: {$productType}");
        }

        return $this->profileCache[$offerId] = $profile;
    }
}
