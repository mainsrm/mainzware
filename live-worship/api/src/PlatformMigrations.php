<?php
declare(strict_types=1);

namespace LiveWorship;

use PDO;
use Throwable;

final class PlatformMigrations
{
    public static function applyPlatform(PDO $db): void
    {
        $db->exec('CREATE SCHEMA IF NOT EXISTS lw_control');
        $db->exec('CREATE TABLE IF NOT EXISTS lw_control.schema_migrations (filename text PRIMARY KEY, applied_on timestamptz NOT NULL DEFAULT now())');
        self::applyDirectory($db, dirname(__DIR__) . '/platform_migrations', 'lw_control.schema_migrations', null);
        self::grantRuntimeAccess($db, 'lw_control');
        self::grantRuntimeAccess($db, 'lw_master');
        self::grantLoginActivityAccess($db);
        self::restrictLoginActivityMutationAccess($db);
        TenantMembershipDirectory::rebuild($db);
    }

    public static function applyTenant(PDO $db, string $schemaName): void
    {
        if (!preg_match('/^lw_t_[0-9a-f]+$/', $schemaName)) throw new \InvalidArgumentException('Invalid tenant schema name.');
        if (!preg_match('/^[a-z0-9_]+$/', $schemaName)) throw new \InvalidArgumentException('Invalid tenant schema identifier.');
        $quotedSchema = self::quoteIdentifier($schemaName);
        $db->exec("CREATE SCHEMA IF NOT EXISTS {$quotedSchema}");
        $db->exec('CREATE TABLE IF NOT EXISTS lw_control.tenant_schema_migrations (schema_name text NOT NULL, filename text NOT NULL, applied_on timestamptz NOT NULL DEFAULT now(), PRIMARY KEY (schema_name, filename))');
        self::applyDirectory($db, dirname(__DIR__) . '/tenant_migrations', 'lw_control.tenant_schema_migrations', $schemaName, $quotedSchema);
        self::grantRuntimeAccess($db, $schemaName);
    }

    private static function applyDirectory(PDO $db, string $directory, string $ledger, ?string $schemaName = null, ?string $quotedSchema = null): void
    {
        $appliedQuery = $schemaName === null
            ? $db->query("SELECT filename FROM {$ledger}")
            : $db->prepare("SELECT filename FROM {$ledger} WHERE schema_name = :schema_name");
        if ($schemaName === null) $appliedQuery->execute();
        else $appliedQuery->execute(['schema_name' => $schemaName]);
        $applied = array_fill_keys($appliedQuery->fetchAll(PDO::FETCH_COLUMN), true);

        $files = glob($directory . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $name = basename($file);
            if (isset($applied[$name])) continue;
            $sql = file_get_contents($file);
            if ($sql === false) throw new \RuntimeException("Could not read migration {$name}.");
            if ($quotedSchema !== null) $sql = str_replace('{{TENANT_SCHEMA}}', $quotedSchema, $sql);
            $db->beginTransaction();
            try {
                $db->exec($sql);
                if ($schemaName === null) {
                    $insert = $db->prepare("INSERT INTO {$ledger} (filename) VALUES (:filename)");
                    $insert->execute(['filename' => $name]);
                } else {
                    $insert = $db->prepare("INSERT INTO {$ledger} (schema_name, filename) VALUES (:schema_name, :filename)");
                    $insert->execute(['schema_name' => $schemaName, 'filename' => $name]);
                }
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) $db->rollBack();
                throw $error;
            }
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private static function grantRuntimeAccess(PDO $db, string $schemaName): void
    {
        $role = trim((string) (getenv('LIVE_WORSHIP_DB_USER') ?: getenv('MAINZWORLD_DB_USER') ?: ''));
        if ($role === '' || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $role)) return;
        $schema = self::quoteIdentifier($schemaName);
        $quotedRole = self::quoteIdentifier($role);
        $db->exec("GRANT USAGE ON SCHEMA {$schema} TO {$quotedRole}");
        $db->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {$schema} TO {$quotedRole}");
        $db->exec("GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA {$schema} TO {$quotedRole}");
        $db->exec("ALTER DEFAULT PRIVILEGES IN SCHEMA {$schema} GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$quotedRole}");
        $db->exec("ALTER DEFAULT PRIVILEGES IN SCHEMA {$schema} GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO {$quotedRole}");
    }

    private static function grantLoginActivityAccess(PDO $db): void
    {
        $roles = array_unique(array_filter([
            trim((string) (getenv('LIVE_WORSHIP_DB_USER') ?: '')),
            trim((string) (getenv('MAINZWORLD_DB_USER') ?: '')),
        ], static fn(string $role): bool => preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $role) === 1));
        if (!$roles || $db->query("SELECT to_regclass('lw_control.login_activity')")->fetchColumn() === null) return;
        foreach ($roles as $role) {
            $quotedRole = self::quoteIdentifier($role);
            $db->exec("GRANT USAGE ON SCHEMA lw_control TO {$quotedRole}");
            $db->exec("GRANT SELECT, INSERT ON TABLE lw_control.login_activity TO {$quotedRole}");
        }
    }

    private static function restrictLoginActivityMutationAccess(PDO $db): void
    {
        $roles = array_unique(array_filter([
            trim((string) (getenv('LIVE_WORSHIP_DB_USER') ?: '')),
            trim((string) (getenv('MAINZWORLD_DB_USER') ?: '')),
        ], static fn(string $role): bool => preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $role) === 1));
        if (!$roles || $db->query("SELECT to_regclass('lw_control.login_activity')")->fetchColumn() === null) return;
        foreach ($roles as $role) {
            $db->exec('REVOKE UPDATE, DELETE ON TABLE lw_control.login_activity FROM ' . self::quoteIdentifier($role));
        }
    }
}
