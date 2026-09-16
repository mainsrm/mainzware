<?php
declare(strict_types=1);

namespace MainzWorld;

use MainzWorld\Controllers\AuthController;
use MainzWorld\Controllers\BudgetController;
use MainzWorld\Controllers\ContentController;
use MainzWorld\Controllers\DebtsController;
use MainzWorld\Controllers\MarketController;
use MainzWorld\Controllers\ProjectsController;
use MainzWorld\Controllers\PropertiesController;
use MainzWorld\Controllers\PropertyListsController;
use MainzWorld\Controllers\ScrapeSourcesController;
use MainzWorld\Controllers\GisSourcesController;
use MainzWorld\Controllers\PropertySourcesController;
use MainzWorld\Controllers\UsersController;

// Minimal front-controller router matching openapi.yaml's /api/v1/* paths.
final class Router
{
    public static function dispatch(string $method, string $path): void
    {
        $path = rtrim(parse_url($path, PHP_URL_PATH) ?: '/', '/');

        if ($method === 'GET' && $path === '/api/v1/projects') {
            (new ProjectsController())->index();
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/properties') {
            (new PropertiesController())->index();
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/scrape-sources') {
            (new ScrapeSourcesController())->index();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/scrape-sources') {
            (new ScrapeSourcesController())->create();
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/api/v1/scrape-sources/(\d+)$#', $path, $m)) {
            (new ScrapeSourcesController())->delete((int) $m[1]);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/gis-sources') {
            (new GisSourcesController())->index();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/gis-sources') {
            (new GisSourcesController())->create();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/property-sources') {
            (new PropertySourcesController())->create();
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/api/v1/gis-sources/(\d+)$#', $path, $m)) {
            (new GisSourcesController())->delete((int) $m[1]);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/property-lists') {
            (new PropertyListsController())->index();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/property-lists') {
            (new PropertyListsController())->create();
            return;
        }

        if ($method === 'GET' && preg_match('#^/api/v1/property-lists/(\d+)$#', $path, $m)) {
            (new PropertyListsController())->show((int) $m[1]);
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/api/v1/property-lists/(\d+)$#', $path, $m)) {
            (new PropertyListsController())->delete((int) $m[1]);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/budget/transactions') {
            (new BudgetController())->index();
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/budget/categories') {
            (new BudgetController())->categories();
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/budget/category-rules') {
            (new BudgetController())->rules();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/budget/category-rules') {
            (new BudgetController())->createRule();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/budget/categories') {
            (new BudgetController())->createCategory();
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/budget/transactions/(\d+)/category$#', $path, $m)) {
            (new BudgetController())->updateTransactionCategory((int) $m[1]);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/budget/budgets') {
            (new BudgetController())->budgets();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/budget/budgets') {
            (new BudgetController())->createBudget();
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/api/v1/budget/budgets/(\d+)$#', $path, $m)) {
            (new BudgetController())->deleteBudget((int) $m[1]);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/budget/budgets/(\d+)$#', $path, $m)) {
            (new BudgetController())->renameBudget((int) $m[1]);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/budget/share') {
            (new BudgetController())->share();
            return;
        }

        if ($method === 'GET' && preg_match('#^/api/v1/budget/budgets/(\d+)/members$#', $path, $m)) {
            (new BudgetController())->members((int) $m[1]);
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/api/v1/budget/budgets/(\d+)/members/(\d+)$#', $path, $m)) {
            (new BudgetController())->unshare((int) $m[1], (int) $m[2]);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/budget/categories/(\d+)$#', $path, $m)) {
            (new BudgetController())->updateCategory((int) $m[1]);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/budget/transactions') {
            (new BudgetController())->addManual();
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/budget/summary') {
            (new BudgetController())->summary();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/budget/import') {
            (new BudgetController())->import();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/budget/receipts') {
            (new BudgetController())->uploadReceipt();
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/budget/receipts') {
            (new BudgetController())->receipts();
            return;
        }

        if ($method === 'GET' && preg_match('#^/api/v1/budget/receipts/(\d+)$#', $path, $m)) {
            (new BudgetController())->showReceipt((int) $m[1]);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/budget/receipts/(\d+)/process$#', $path, $m)) {
            (new BudgetController())->processReceipt((int) $m[1]);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/budget/receipts/(\d+)/confirm$#', $path, $m)) {
            (new BudgetController())->confirmReceipt((int) $m[1]);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/budget/receipts/(\d+)/items$#', $path, $m)) {
            (new BudgetController())->addReceiptItem((int) $m[1]);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/budget/receipts/(\d+)/items/(\d+)$#', $path, $m)) {
            (new BudgetController())->updateReceiptItem((int) $m[1], (int) $m[2]);
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/api/v1/budget/receipts/(\d+)/items/(\d+)$#', $path, $m)) {
            (new BudgetController())->deleteReceiptItem((int) $m[1], (int) $m[2]);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/debts') {
            (new DebtsController())->index();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/debts') {
            (new DebtsController())->create();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/debts/snowball') {
            (new DebtsController())->snowball();
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/debts/(\d+)$#', $path, $m)) {
            (new DebtsController())->update((int) $m[1]);
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/api/v1/debts/(\d+)$#', $path, $m)) {
            (new DebtsController())->delete((int) $m[1]);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/market/btc-ichimoku') {
            (new MarketController())->btcIchimoku();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/auth/login') {
            (new AuthController())->login();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/auth/logout') {
            (new AuthController())->logout();
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/auth/me') {
            (new AuthController())->me();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/auth/password') {
            (new AuthController())->changePassword();
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/content') {
            (new ContentController())->index();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/content') {
            (new ContentController())->create();
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/users') {
            (new UsersController())->index();
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/users') {
            (new UsersController())->create();
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/users/(\d+)/(activate|deactivate)$#', $path, $m)) {
            (new UsersController())->setActive((int) $m[1], $m[2] === 'activate');
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/users/(\d+)$#', $path, $m)) {
            (new UsersController())->update((int) $m[1]);
            return;
        }

        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']);
    }
}
