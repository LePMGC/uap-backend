<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Modules\Operations\Models\CatalogProduct;

class CatalogProductSeeder extends Seeder
{
    public function run(): void
    {
        $filePath = database_path('seeders/bundles.csv');

        if (!file_exists($filePath)) {
            $this->command->error("Source file not found at: {$filePath}");
            return;
        }

        $file = fopen($filePath, 'r');

        // 1. Read the header row and map column names to their exact index position
        $headers = fgetcsv($file);
        $headers = array_map(function ($h) {
            // Remove UTF-8 BOM and normalize headers completely
            $h = preg_replace('/[\x{FEFF}\x{EFBB}\x{BF}]/u', '', $h);
            return strtoupper(trim($h));
        }, $headers);
        $headerMap = array_flip($headers);

        // Find positions dynamically, with lookups fallback
        $idxOfferId      = $headerMap['OFFER_ID'] ?? null;
        $idxDesc         = $headerMap['OFFER_DESC'] ?? $headerMap['NAME'] ?? null;
        $idxType         = $headerMap['OFFER_TYPE'] ?? $headerMap['CATEGORY'] ?? null;
        $idxPrice        = $headerMap['PRICE'] ?? $headerMap['COST'] ?? null;
        $idxValidity     = $headerMap['VALIDITY'] ?? null;
        $idxValidityUnit = $headerMap['VALIDITY_UNIT'] ?? $headerMap['VALIDITY_UNITS'] ?? null;

        if ($idxOfferId === null) {
            $this->command->error("Critical error: Unable to detect an 'OFFER_ID' header column in bundles.csv");
            fclose($file);
            return;
        }

        $this->command->info('Parsing bundles.csv with dynamic header mapping (Auto-Increment ID active)...');

        $processedOfferIds = [];
        $count = 0;

        while (($row = fgetcsv($file)) !== false) {
            if (empty($row) || !isset($row[$idxOfferId]) || trim($row[$idxOfferId]) === '') {
                continue;
            }

            $offerId = (int) trim($row[$idxOfferId]);

            // De-duplicate in memory loop
            if (in_array($offerId, $processedOfferIds)) {
                continue;
            }

            // Extract values safely using the dynamic index locations map
            $description  = $idxDesc !== null ? trim($row[$idxDesc] ?? '') : "Offer #{$offerId}";
            $rawOfferType = $idxType !== null ? trim($row[$idxType] ?? 'DATA') : 'DATA';
            $price        = $idxPrice !== null ? (float) trim($row[$idxPrice] ?? 0) : 0.00;
            $validity     = $idxValidity !== null ? (int) trim($row[$idxValidity] ?? 0) : 0;
            $validityUnit = $idxValidityUnit !== null ? trim($row[$idxValidityUnit] ?? '') : null;

            // Clean the offer type text strings contextually (e.g., "DATA_BUNDLE" -> "DATA")
            $normalizedType = str_replace('_BUNDLE', '', strtoupper($rawOfferType));

            // Save record using Upsert tracking pattern on unique offer_id constraint.
            // PostgreSQL auto-generates the auto-increment integer ID field upon creation.
            CatalogProduct::updateOrCreate(
                ['offer_id' => $offerId],
                [
                    'name'           => $description,
                    'type'           => $normalizedType,
                    'cost'           => $price,
                    'validity'       => $validity,
                    'validity_units' => $validityUnit ? strtoupper($validityUnit) : null,
                    'is_active'      => true
                ]
            );

            $processedOfferIds[] = $offerId;
            $count++;
        }

        fclose($file);
        $this->command->info("Successfully populated {$count} unique products without formatting conflicts.");
    }
}
