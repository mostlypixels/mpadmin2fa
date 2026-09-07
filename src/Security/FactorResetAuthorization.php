<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

final class FactorResetAuthorization
{
    public function denialReason(
        int $actorId,
        int $actorProfileId,
        int $targetEmployeeId,
        bool $hasNativeDeletePermission,
        int $superAdminProfileId
    ): ?string {
        if ($actorId <= 0) {
            return 'An authenticated employee is required.';
        }
        if (!$hasNativeDeletePermission) {
            return 'Native delete permission is required to reset two-factor authentication.';
        }
        if ($actorProfileId !== $superAdminProfileId) {
            return 'Only a SuperAdmin can reset another employee\'s two-factor authentication.';
        }
        if ($targetEmployeeId <= 0 || $actorId === $targetEmployeeId) {
            return 'Choose another employee whose two-factor authentication should be reset.';
        }

        return null;
    }
}
