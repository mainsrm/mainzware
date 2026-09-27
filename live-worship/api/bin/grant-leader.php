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
        "INSERT INTO live_worship.members
            (identity_id, role, activated_on, activated_by, inactivated_on, inactivated_by)
         VALUES (:identity, 'leader', now(), 0, NULL, NULL)
         ON CONFLICT (identity_id) DO UPDATE
            SET role='leader', activated_on=now(), activated_by=0,
                inactivated_on=NULL, inactivated_by=NULL"
    );
    $grant->execute(['identity' => (int) $identityId]);
    echo "Granted Live Worship leader access to {$username}.\n";
} catch (Throwable $error) { fwrite(STDERR, "Could not grant leader access: {$error->getMessage()}\n"); exit(1); }
