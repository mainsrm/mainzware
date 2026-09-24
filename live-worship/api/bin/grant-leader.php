<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use LiveWorship\Database;

$username = trim((string) ($argv[1] ?? ''));
if ($username === '') { fwrite(STDERR, "Usage: php grant-leader.php <mainzware-username>\n"); exit(2); }
try {
    $db = Database::migratorConnection();
    $find = $db->prepare('SELECT id FROM auth.users WHERE username=:username AND is_active=TRUE');
    $find->execute(['username' => $username]);
    $identityId = $find->fetchColumn();
    if (!$identityId) { fwrite(STDERR, "No active MainzWare user named {$username}.\n"); exit(1); }
    $grant = $db->prepare(
        "INSERT INTO live_worship.members (identity_id, role, active) VALUES (:identity, 'leader', TRUE)
         ON CONFLICT (identity_id) DO UPDATE SET role='leader', active=TRUE"
    );
    $grant->execute(['identity' => (int) $identityId]);
    echo "Granted Live Worship leader access to {$username}.\n";
} catch (Throwable $error) { fwrite(STDERR, "Could not grant leader access: {$error->getMessage()}\n"); exit(1); }
