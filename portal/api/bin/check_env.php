<?php
declare(strict_types=1);

/**
 * Deploy preflight: fails loudly when production is missing an environment
 * variable it needs. deploy-mainzware.sh preserves the host's .env across
 * deploys, so a variable added to .env.example never reaches production on its
 * own -- and code that falls back to a default keeps "working" while doing the
 * wrong thing. MAINZWORLD_SCRAPER_DIR went missing exactly this way and every
 * scheduled scrape silently did nothing.
 *
 * Run from the deploy script before migrate_db.php, with .env already sourced.
 */

// Variables whose absence breaks or silently de-hardens the app. Anything in
// .env.example but not here is optional and only reported as a notice.
const REQUIRED = [
    'MAINZWORLD_DB_NAME',
    'MAINZWORLD_DB_USER',
    'MAINZWORLD_JWT_SECRET',
    'MAINZWORLD_SCRAPER_DIR',
    'PLAYWRIGHT_BROWSERS_PATH',
];

$missing = [];
foreach (REQUIRED as $key) {
    $value = getenv($key);
    if ($value === false || trim($value) === '') {
        $missing[] = $key;
    }
}

$examplePath = dirname(__DIR__) . '/.env.example';
$optionalMissing = [];
if (is_file($examplePath)) {
    foreach (file($examplePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        $key = trim(strstr($line, '=', true));
        if ($key === '' || in_array($key, REQUIRED, true)) {
            continue;
        }
        $value = getenv($key);
        if ($value === false || trim($value) === '') {
            $optionalMissing[] = $key;
        }
    }
}

if ($optionalMissing !== []) {
    echo "Notice: optional variables not set: " . implode(', ', array_unique($optionalMissing)) . "\n";
}

if ($missing !== []) {
    fwrite(STDERR, "Environment check FAILED. Missing required variable(s): "
        . implode(', ', $missing) . "\n"
        . "Add them to the host's api/.env (it is preserved across deploys), then redeploy.\n");
    exit(1);
}

echo "Environment check passed.\n";
