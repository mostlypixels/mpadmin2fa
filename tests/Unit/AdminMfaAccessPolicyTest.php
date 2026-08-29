<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Security\AdminMfaAccessPolicy;
use PHPUnit\Framework\TestCase;

final class AdminMfaAccessPolicyTest extends TestCase
{
    /**
     * @dataProvider equivalentEntryPoints
     */
    public function testSymfonyAndLegacyEntryPointsProduceTheSameDecision(
        string $expected,
        string $symfonyRoute,
        string $legacyController,
        string $legacyAction,
        bool $methodSafe,
        bool $mfaRequired,
        bool $active,
        bool $verified,
        bool $fresh
    ): void {
        $policy = new AdminMfaAccessPolicy();

        self::assertSame($expected, $policy->decide(
            'symfony',
            $symfonyRoute,
            '',
            $methodSafe,
            $mfaRequired,
            $active,
            $verified,
            $fresh,
            false
        ));
        self::assertSame($expected, $policy->decide(
            'legacy',
            $legacyController,
            $legacyAction,
            $methodSafe,
            $mfaRequired,
            $active,
            $verified,
            $fresh,
            false
        ));
    }

    public static function equivalentEntryPoints(): iterable
    {
        yield 'unenrolled required employee' => [
            AdminMfaAccessPolicy::REQUIRE_MFA,
            'admin_dashboard',
            'AdminDashboard',
            '',
            true,
            true,
            false,
            false,
            false,
        ];
        yield 'enrolled unverified employee' => [
            AdminMfaAccessPolicy::REQUIRE_MFA,
            'admin_dashboard',
            'AdminDashboard',
            '',
            true,
            true,
            true,
            false,
            false,
        ];
        yield 'module install without fresh verification' => [
            AdminMfaAccessPolicy::REQUIRE_STEP_UP,
            'admin_module_manage_action',
            'AdminModules',
            'install',
            false,
            false,
            true,
            true,
            false,
        ];
        yield 'module install with fresh verification' => [
            AdminMfaAccessPolicy::ALLOW,
            'admin_module_manage_action',
            'AdminModules',
            'install',
            false,
            false,
            true,
            true,
            true,
        ];
    }

    public function testLegacyGetMutationStillRequiresStepUp(): void
    {
        self::assertSame(AdminMfaAccessPolicy::REQUIRE_STEP_UP, (new AdminMfaAccessPolicy())->decide(
            'legacy',
            'AdminModules',
            'uninstall',
            true,
            false,
            true,
            true,
            false,
            false
        ));
    }

    public function testRecoveryRestrictedSessionCanOnlyReachEnrollment(): void
    {
        $policy = new AdminMfaAccessPolicy();

        self::assertSame(AdminMfaAccessPolicy::ALLOW, $policy->decide(
            'symfony',
            'mpadmin2fa_enroll',
            '',
            true,
            true,
            true,
            true,
            true,
            true
        ));
        self::assertSame(AdminMfaAccessPolicy::REQUIRE_MFA, $policy->decide(
            'symfony',
            'admin_dashboard',
            '',
            true,
            true,
            true,
            true,
            true,
            true
        ));
    }

    public function testUnknownEntryPointIsDenied(): void
    {
        self::assertSame(AdminMfaAccessPolicy::DENY, (new AdminMfaAccessPolicy())->decide(
            'unknown',
            'AdminModules',
            'install',
            false,
            false,
            true,
            true,
            true,
            false
        ));
    }
}
