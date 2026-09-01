<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Security\AdminMfaAccessPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdminMfaAccessPolicyTest extends TestCase
{
    public function testInvalidEmployeeIsDenied(): void
    {
        self::assertSame(AdminMfaAccessPolicy::DENY, $this->decide(['employee_id' => 0]));
    }

    public function testRequiredEmployeeWithoutFactorMustEnroll(): void
    {
        self::assertSame(AdminMfaAccessPolicy::REQUIRE_MFA, $this->decide(['requires_mfa' => true]));
    }

    public function testActiveUnverifiedEmployeeMustChallenge(): void
    {
        self::assertSame(AdminMfaAccessPolicy::REQUIRE_MFA, $this->decide(['active' => true]));
    }

    public function testRecoverySessionCanOnlyReachEnrollmentOrLogout(): void
    {
        self::assertSame(AdminMfaAccessPolicy::REQUIRE_MFA, $this->decide([
            'active' => true,
            'recovery' => true,
            'route' => 'admin_dashboard',
            'verified' => true,
        ]));
        self::assertSame(AdminMfaAccessPolicy::ALLOW, $this->decide([
            'active' => true,
            'recovery' => true,
            'route' => 'mpadmin2fa_enroll',
            'verified' => true,
        ]));
        self::assertSame(AdminMfaAccessPolicy::ALLOW, $this->decide([
            'active' => true,
            'recovery' => true,
            'route' => 'admin_logout',
            'verified' => true,
        ]));
    }

    public function testModernSensitiveMutationRequiresFreshVerification(): void
    {
        self::assertSame(AdminMfaAccessPolicy::REQUIRE_STEP_UP, $this->decide([
            'active' => true,
            'route' => 'admin_module_manage_action',
            'safe_method' => false,
            'verified' => true,
        ]));
        self::assertSame(AdminMfaAccessPolicy::ALLOW, $this->decide([
            'active' => true,
            'fresh' => true,
            'route' => 'admin_module_manage_action',
            'safe_method' => false,
            'verified' => true,
        ]));
    }

    public static function legacySensitiveActions(): iterable
    {
        yield 'module install GET' => ['AdminModules', 'install', true];
        yield 'module uninstall' => ['AdminModules', 'uninstall', false];
        yield 'module upgrade' => ['AdminModules', 'submitUpgradeModule', false];
        yield 'bulk module action' => ['AdminModules', 'submitBulkdisableSelectionmodule', false];
        yield 'theme import' => ['AdminThemes', 'import', false];
        yield 'data import' => ['AdminImport', 'submitImportFile', false];
        yield 'data import without action hint' => ['AdminImport', '', false];
    }

    #[DataProvider('legacySensitiveActions')]
    public function testLegacySensitiveActionRequiresTheSameStepUp(
        string $controller,
        string $action,
        bool $safeMethod,
    ): void {
        self::assertSame(AdminMfaAccessPolicy::REQUIRE_STEP_UP, $this->decide([
            'action' => $action,
            'active' => true,
            'controller' => $controller,
            'safe_method' => $safeMethod,
            'verified' => true,
        ]));
    }

    public function testUnrelatedLegacyPageIsAllowedAfterMfa(): void
    {
        self::assertSame(AdminMfaAccessPolicy::ALLOW, $this->decide([
            'active' => true,
            'controller' => 'AdminOrders',
            'safe_method' => false,
            'verified' => true,
        ]));
    }

    private function decide(array $changes): string
    {
        $state = array_merge([
            'action' => '',
            'active' => false,
            'controller' => '',
            'employee_id' => 42,
            'fresh' => false,
            'recovery' => false,
            'requires_mfa' => false,
            'route' => '',
            'safe_method' => true,
            'verified' => false,
        ], $changes);

        return (new AdminMfaAccessPolicy())->decide(
            $state['employee_id'],
            $state['requires_mfa'],
            $state['active'],
            $state['verified'],
            $state['recovery'],
            $state['fresh'],
            $state['safe_method'],
            $state['route'],
            $state['controller'],
            $state['action'],
        );
    }
}
