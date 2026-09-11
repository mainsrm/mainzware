<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MainzWorld\Router;

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

Router::dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
