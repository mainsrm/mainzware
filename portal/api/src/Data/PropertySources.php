<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

final class PropertySources
{
    /**
     * Save SRI and GIS sources together so neither side can be committed alone.
     *
     * @param array<int, array{label: string, url: string, vendor: string}> $sriSources
     * @param array<int, array{county: string, state: string, url: string}> $gisSources
     * @return array{sri_ids: int[], gis_ids: int[], sri_urls: string[]}
     */
    public static function save(array $sriSources, array $gisSources): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $sriIds = [];
            $sriUrls = [];
            $sriInsert = $pdo->prepare(
                'INSERT INTO scrape_sources (vendor, label, url)
                 VALUES (:vendor, :label, :url)
                 ON CONFLICT (url) DO UPDATE SET
                    vendor = EXCLUDED.vendor, label = EXCLUDED.label, is_active = TRUE
                 RETURNING id'
            );
            foreach ($sriSources as $source) {
                $sriInsert->execute([
                    'vendor' => $source['vendor'],
                    'label' => $source['label'],
                    'url' => $source['url'],
                ]);
                $sriIds[] = (int) $sriInsert->fetchColumn();
                $sriUrls[] = $source['url'];
            }

            $gisInsert = $pdo->prepare(
                'INSERT INTO gis_sources (county, state, url)
                 VALUES (:county, :state, :url)
                 ON CONFLICT (county, state) DO UPDATE SET url = EXCLUDED.url
                 RETURNING id'
            );
            $gisIds = [];
            foreach ($gisSources as $source) {
                $gisInsert->execute([
                    'county' => $source['county'],
                    'state' => $source['state'],
                    'url' => $source['url'],
                ]);
                $gisIds[] = (int) $gisInsert->fetchColumn();
            }

            $pdo->commit();
            return ['sri_ids' => $sriIds, 'gis_ids' => $gisIds, 'sri_urls' => $sriUrls];
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}