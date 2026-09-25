<?php
declare(strict_types=1);

namespace LiveWorship;

final class Access
{
    public static function member(): array
    {
        $authMode = $_SESSION['live_worship_auth_mode'] ?? '';
        if ($authMode === 'signed_out') throw new ApiError(401, 'Sign in to Live Worship.');

        if ($authMode === 'standalone') {
            $accountId = (int) ($_SESSION['live_worship_account_id'] ?? 0);
            if ($accountId < 1) throw new ApiError(401, 'Sign in to Live Worship.');
            $stmt = Database::connection()->prepare(
                'SELECT m.id, m.standalone_account_id, m.role, m.active, m.view_mode, a.username
                   FROM live_worship.members m
                   JOIN live_worship.standalone_accounts a ON a.id = m.standalone_account_id
                  WHERE m.standalone_account_id = :account_id AND m.active = TRUE'
            );
            $stmt->execute(['account_id' => $accountId]);
            $member = $stmt->fetch();
            if (!$member) throw new ApiError(403, 'A worship leader must add your account to this team.');
            $member['id'] = (int) $member['id'];
            $member['standalone_account_id'] = (int) $member['standalone_account_id'];
            $member['username'] = (string) $member['username'];
            $member['auth_type'] = 'live_worship';
            return $member;
        }

        $identity = \MainzWorld\Support\Auth::currentUser();
        if ($identity === null) throw new ApiError(401, 'Sign in to Live Worship or MainzWare.');
        $stmt = Database::connection()->prepare(
            'SELECT id, identity_id, role, active, view_mode FROM live_worship.members WHERE identity_id = :identity_id AND active = TRUE'
        );
        $stmt->execute(['identity_id' => (int) $identity['id']]);
        $member = $stmt->fetch();
        if (!$member) throw new ApiError(403, 'A Live Worship leader must add your account to this app.');
        $member['id'] = (int) $member['id'];
        $member['identity_id'] = (int) $member['identity_id'];
        $member['username'] = $identity['username'];
        $member['auth_type'] = 'mainzware';
        return $member;
    }

    public static function leader(array $member): void
    {
        if ($member['role'] !== 'leader') throw new ApiError(403, 'Worship leader access is required for this action.');
    }
}
