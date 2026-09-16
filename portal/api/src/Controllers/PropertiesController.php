<?php
declare(strict_types=1);

namespace MainzWorld\Controllers;

use MainzWorld\Data\SaleProperties;
use MainzWorld\Support\SaleScraper;

final class PropertiesController
{
    public function index(): void
    {
        header('Content-Type: application/json');
        // The first request of the day may block briefly while the scraper refreshes the cache.
        set_time_limit(150);
        SaleScraper::refreshStaleSources();
        echo json_encode(SaleProperties::all(), JSON_THROW_ON_ERROR);
    }
}
