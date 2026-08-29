<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Exception\EnrollmentApprovalDenied;
use Mpadmin2fa\Security\EnrollmentApprovalAuthorizer;
use PHPUnit\Framework\TestCase;

final class EnrollmentApprovalAuthorizerTest extends TestCase
{
    public function testAnotherSuperAdminWithNativePermissionCanApprove(): void
    {
        (new EnrollmentApprovalAuthorizer())->assertCanApprove(1, 1, true, 2, 1);
        self::assertTrue(true);
    }

    /**
     * @dataProvider deniedApprovals
     */
    public function testEveryApprovalRequirementIsEnforced(
        ?int $actorId,
        ?int $profileId,
        bool $hasPermission,
        int $targetId,
        string $reason
    ): void {
        try {
            (new EnrollmentApprovalAuthorizer())->assertCanApprove(
                $actorId,
                $profileId,
                $hasPermission,
                $targetId,
                1
            );
            self::fail('The approval should have been denied.');
        } catch (EnrollmentApprovalDenied $exception) {
            self::assertSame($reason, $exception->reason());
        }
    }

    public static function deniedApprovals(): iterable
    {
        yield 'unauthenticated' => [null, null, true, 2, 'unauthenticated'];
        yield 'missing native permission' => [1, 1, false, 2, 'native_permission_missing'];
        yield 'delegated non-SuperAdmin' => [2, 2, true, 3, 'superadmin_required'];
        yield 'self approval' => [2, 1, true, 2, 'self_approval'];
    }
}
