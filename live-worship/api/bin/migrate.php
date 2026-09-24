<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use LiveWorship\Database;

try {
    $db = Database::migratorConnection();
    $db->exec('CREATE SCHEMA IF NOT EXISTS live_worship');
    $db->exec('CREATE TABLE IF NOT EXISTS live_worship.schema_migrations (filename text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())');
    $applied = $db->query('SELECT filename FROM live_worship.schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $files = glob(dirname(__DIR__) . '/db_migrations/*.sql');
    sort($files);
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $applied, true)) continue;
        echo "Applying Live Worship {$name} ...\n";
        $db->beginTransaction();
        try {
            $db->exec(file_get_contents($file));
            $insert = $db->prepare('INSERT INTO live_worship.schema_migrations (filename) VALUES (:filename)');
            $insert->execute(['filename' => $name]);
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
    echo "Live Worship database is up to date.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Live Worship migration failed: {$error->getMessage()}\n");
    exit(1);
}
