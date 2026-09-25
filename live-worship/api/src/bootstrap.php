<?php
declare(strict_types=1);

foreach (['ApiError', 'Database', 'Access', 'Files', 'Transpose', 'OnsongParser', 'Api'] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
