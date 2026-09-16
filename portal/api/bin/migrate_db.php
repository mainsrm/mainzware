<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MainzWorld\Config\Database;

try {
    $pdo = Database::migratorConnection();
    // Always schema-qualified: once a search_path with other schemas exists, an
    // unqualified CREATE would make a second, empty tracking table and replay
    // every migration from 001 against schemas that already hold live data.
    $pdo->exec('CREATE TABLE IF NOT EXISTS public.schema_migrations (
        filename TEXT PRIMARY KEY,
        applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )');

    $applied = $pdo->query('SELECT filename FROM public.schema_migrations')
        ->fetchAll(PDO::FETCH_COLUMN);

    $dir = dirname(__DIR__) . '/db_migrations';
    $files = glob("$dir/*.sql");
    sort($files); // numeric filename prefixes (001_, 002_, ...) enforce run order

    $ran = 0;
    foreach ($files as $path) {
        $name = basename($path);
        if (in_array($name, $applied, true)) {
            continue;
        }

        echo "Applying {$name} ...\n";
        $pdo->beginTransaction();
        try {
            $pdo->exec(file_get_contents($path));
            $pdo->prepare('INSERT INTO public.schema_migrations (filename) VALUES (:filename)')
                ->execute(['filename' => $name]);
            $pdo->commit();
            $ran++;
        } catch (Throwable $e) {
            $pdo->rollBack();
            fwrite(STDERR, "FAILED on {$name}: {$e->getMessage()}\n");
            exit(1);
        }
    }

    echo $ran > 0 ? "Applied {$ran} migration(s).\n" : "Already up to date.\n";
} catch (Throwable $e) {
    // Catch connection/setup failures too, so credentials and stack traces never reach stdout/stderr.
    fwrite(STDERR, "Migration run failed: " . $e->getMessage() . "\n");
    exit(1);
}

