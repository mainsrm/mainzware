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
        // Never scrape inline: serve the cached rows and let a background worker
        // refresh anything stale, so this request returns immediately.
        SaleScraper::dispatchIfStale();
        echo json_encode(SaleProperties::all(), JSON_THROW_ON_ERROR);
    }
}
