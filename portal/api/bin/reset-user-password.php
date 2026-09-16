<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use MainzWorld\Data\Users;

$username = trim($argv[1] ?? '');
if ($username === '') {
    fwrite(STDERR, "Usage: php bin/reset-user-password.php <username>\n");
    exit(1);
}

$user = Users::findByUsername($username);
if ($user === null) {
    fwrite(STDERR, "User not found: {$username}\n");
    exit(1);
}

fwrite(STDERR, "New password for {$username}: ");
shell_exec('stty -echo');
$password = fgets(STDIN) ?: '';
shell_exec('stty echo');
fwrite(STDERR, "\n");

$password = trim($password);
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

Users::update((int) $user['id'], null, null, password_hash($password, PASSWORD_BCRYPT));
echo "Password updated for {$username}.\n";
