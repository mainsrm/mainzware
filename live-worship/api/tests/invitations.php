<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use LiveWorship\Database;
use LiveWorship\TenantInvitations;
use LiveWorship\TenantMembershipDirectory;
use LiveWorship\TenantNames;
use LiveWorship\TenantProvisioner;

$migrator = Database::migratorConnection();
$runtime = Database::connection();
$ownerActor = TenantNames::uuid();
$joinerActor = TenantNames::uuid();
$tenant = null;

foreach ([[$ownerActor, 'Invitation test owner'], [$joinerActor, 'Invitation test joiner']] as [$id, $name]) {
    $stmt = $migrator->prepare(
        'INSERT INTO lw_control.actors
            (id, identity_provider, external_subject, display_name, activated_on, activated_by)
         VALUES (:id, :provider, :subject, :name, now(), :by)'
    );
    $stmt->execute([
        'id' => $id,
        'provider' => 'invitation-test',
        'subject' => $id,
        'name' => $name,
        'by' => '00000000-0000-0000-0000-000000000001',
    ]);
}

try {
    $tenant = TenantProvisioner::provision($migrator, 'Invitation Test ' . substr($ownerActor, 0, 8), $ownerActor);
    $ownerTeams = TenantMembershipDirectory::forActor($runtime, $ownerActor);
    if (count($ownerTeams) !== 1 || $ownerTeams[0]['tenant_id'] !== $tenant['id'] || $ownerTeams[0]['role'] !== 'owner') throw new RuntimeException('Provisioning did not populate the membership directory.');
    $invite = TenantInvitations::create($runtime, $tenant['id'], $ownerActor, 'musician');
    if (!preg_match('/^LW-[A-Z0-9_-]{20,80}$/', $invite['code'])) throw new RuntimeException('Invitation code format is invalid.');

    $joined = TenantInvitations::accept($runtime, $invite['code'], $joinerActor);
    if ($joined['tenant_id'] !== $tenant['id'] || $joined['role'] !== 'musician') throw new RuntimeException('Invitation acceptance returned the wrong membership.');
    $joinerTeams = TenantMembershipDirectory::forActor($runtime, $joinerActor);
    if (count($joinerTeams) !== 1 || $joinerTeams[0]['tenant_id'] !== $tenant['id'] || $joinerTeams[0]['role'] !== 'musician') throw new RuntimeException('Invitation acceptance did not populate the membership directory.');

    $schema = TenantNames::schemaName($tenant['id']);
    $member = $runtime->prepare("SELECT role FROM \"{$schema}\".members WHERE actor_id=:actor_id AND inactivated_on IS NULL AND inactivated_by IS NULL");
    $member->execute(['actor_id' => $joinerActor]);
    if ($member->fetchColumn() !== 'musician') throw new RuntimeException('Accepted invitation did not create the tenant membership.');

    try {
        TenantInvitations::accept($runtime, $invite['code'], TenantNames::uuid());
        throw new RuntimeException('A consumed invitation was accepted twice.');
    } catch (\LiveWorship\TenantInvitationError $error) {
        if ($error->status !== 404) throw $error;
    }

    $revokable = TenantInvitations::create($runtime, $tenant['id'], $ownerActor, 'choir');
    TenantInvitations::revoke($runtime, $tenant['id'], $ownerActor, $revokable['id']);
    $expired = TenantInvitations::create($runtime, $tenant['id'], $ownerActor, 'choir');
    $runtime->prepare('UPDATE lw_control.tenant_invitations SET expires_on=now() - interval \'1 minute\' WHERE id=:id')->execute(['id' => $expired['id']]);
    try {
        TenantInvitations::accept($runtime, $expired['code'], TenantNames::uuid());
        throw new RuntimeException('An expired invitation was accepted.');
    } catch (\LiveWorship\TenantInvitationError $error) {
        if ($error->status !== 410) throw $error;
    }
    $expiredRow = $runtime->prepare('SELECT inactivated_on FROM lw_control.tenant_invitations WHERE id=:id');
    $expiredRow->execute(['id' => $expired['id']]);
    if (($expiredOn = $expiredRow->fetchColumn()) === false || $expiredOn === null) throw new RuntimeException('Expired invitation was not retained for audit.');
    echo "Invitation create, accept, one-time-use, expiry, and revoke checks passed.\n";
} finally {
    if ($tenant) {
        TenantProvisioner::requestDeprovision($migrator, $tenant['id'], $ownerActor, 0, 'Invitation API test cleanup');
        TenantProvisioner::processDeprovisionQueued($migrator, 1, true);
    }
    $migrator->prepare('DELETE FROM lw_control.actors WHERE id IN (:owner, :joiner)')->execute(['owner' => $ownerActor, 'joiner' => $joinerActor]);
}
