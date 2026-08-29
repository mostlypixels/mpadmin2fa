<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Security\EnrollmentApprovalAuthorization;
use PHPUnit\Framework\TestCase;

final class EnrollmentApprovalAuthorizationTest extends TestCase
{
    /** @dataProvider deniedApprovals */
    public function testApprovalIsDeniedUnlessEveryRequirementPasses(
        int $actorId,
        int $profileId,
        int $targetId,
        bool $hasUpdatePermission,
        string $reason
    ): void {
        self::assertSame(
            $reason,
            (new EnrollmentApprovalAuthorization())->denialReason(
                $actorId,
                $profileId,
                $targetId,
                $hasUpdatePermission,
                1
            )
        );
    }

    public static function deniedApprovals(): iterable
    {
        yield 'unauthenticated' => [0, 1, 8, true, 'An authenticated employee is required.'];
        yield 'missing native permission' => [7, 1, 8, false, 'Native update permission is required to approve 2FA enrollment.'];
        yield 'delegated non-SuperAdmin' => [7, 2, 8, true, 'Only a SuperAdmin can approve 2FA enrollment.'];
        yield 'self approval' => [7, 1, 7, true, 'Employees cannot approve their own 2FA setup.'];
    }

    public function testAnotherSuperAdminWithNativePermissionCanApprove(): void
    {
        self::assertNull((new EnrollmentApprovalAuthorization())->denialReason(7, 1, 8, true, 1));
    }
}
