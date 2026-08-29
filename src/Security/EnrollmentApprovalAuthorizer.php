<?php

declare(strict_types=1);

namespace Mpadmin2fa\Security;

use Mpadmin2fa\Exception\EnrollmentApprovalDenied;

final class EnrollmentApprovalAuthorizer
{
    public function assertCanApprove(
        ?int $actorId,
        ?int $profileId,
        bool $hasNativeUpdatePermission,
        int $targetEmployeeId,
        int $superAdminProfileId
    ): void {
        if (null === $actorId || $actorId <= 0) {
            throw new EnrollmentApprovalDenied('unauthenticated', 'An authenticated employee is required.');
        }
        if (!$hasNativeUpdatePermission) {
            throw new EnrollmentApprovalDenied(
                'native_permission_missing',
                'Native update permission is required to approve 2FA setup.'
            );
        }
        if ($profileId !== $superAdminProfileId) {
            throw new EnrollmentApprovalDenied(
                'superadmin_required',
                'Only a SuperAdmin can approve 2FA setup.'
            );
        }
        if ($actorId === $targetEmployeeId) {
            throw new EnrollmentApprovalDenied(
                'self_approval',
                'Employees cannot approve their own 2FA setup.'
            );
        }
    }
}
