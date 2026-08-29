<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

final class EnrollmentApprovalAuthorization
{
    public function denialReason(
        int $actorId,
        int $actorProfileId,
        int $targetEmployeeId,
        bool $hasNativeUpdatePermission,
        int $superAdminProfileId
    ): ?string {
        if ($actorId <= 0) {
            return 'An authenticated employee is required.';
        }

        if (!$hasNativeUpdatePermission) {
            return 'Native update permission is required to approve 2FA enrollment.';
        }

        if ($actorProfileId !== $superAdminProfileId) {
            return 'Only a SuperAdmin can approve 2FA enrollment.';
        }

        if ($actorId === $targetEmployeeId) {
            return 'Employees cannot approve their own 2FA setup.';
        }

        return null;
    }
}
