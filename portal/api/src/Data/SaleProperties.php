<?php
declare(strict_types=1);

namespace MainzWorld\Data;

use MainzWorld\Config\Database;

final class SaleProperties
{
    public static function all(): array
    {
        $stmt = Database::connection()->query(
            'SELECT id, address, county, state, sale_status, sale_group, map_url, parcel,
                    source_url, sri_id, sri_property_id, scraped_at
             FROM sale_properties
                         WHERE is_active = TRUE
                             AND EXISTS (
                                     SELECT 1 FROM scrape_sources ss
                                     WHERE ss.url = sale_properties.source_url AND ss.is_active = TRUE
                             )
             ORDER BY scraped_at DESC, id DESC'
        );

        return $stmt->fetchAll();
    }
}
