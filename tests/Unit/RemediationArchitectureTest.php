<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RemediationArchitectureTest extends TestCase
{
    public function testModernAndLegacyEntryPointsUseTheSharedPolicy(): void
    {
        $subscriber = (string) file_get_contents(dirname(__DIR__, 2) . '/src/EventSubscriber/AdminMfaSubscriber.php');
        $legacyAdapter = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/LegacyAdminMfaAdapter.php');

        self::assertStringContainsString('AdminMfaAccessPolicy $accessPolicy', $subscriber);
        self::assertStringContainsString('AdminMfaAccessPolicy $accessPolicy', $legacyAdapter);
        self::assertStringContainsString('$this->accessPolicy->decide(', $subscriber);
        self::assertStringContainsString('$this->accessPolicy->decide(', $legacyAdapter);
    }

    public function testLegacyEnforcementRunsBeforeControllerInstantiation(): void
    {
        $module = (string) file_get_contents(dirname(__DIR__, 2) . '/mpadmin2fa.php');

        self::assertStringContainsString("registerHook('actionDispatcherBefore')", $module);
        self::assertStringContainsString('function hookActionDispatcherBefore', $module);
        self::assertStringContainsString('LegacyAdminMfaAdapter::class', $module);
    }

    public function testPs8PostInstallAndUpgradeReuseIdempotentReconciliation(): void
    {
        $module = (string) file_get_contents(dirname(__DIR__, 2) . '/mpadmin2fa.php');
        $upgrade = (string) file_get_contents(dirname(__DIR__, 2) . '/upgrade/upgrade-0.2.8.php');

        self::assertStringContainsString('function postInstall(): bool', $module);
        self::assertStringContainsString('return $this->reconcileAdminTabs();', $module);
        self::assertStringContainsString('function reconcileAdminTabs(): bool', $module);
        self::assertStringContainsString('$module->reconcileAdminTabs()', $upgrade);
    }

    public function testReleasePackageRejectsPhpUnitCache(): void
    {
        $build = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/build-scoped.php');
        $release = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/release.php');

        self::assertStringContainsString("'.phpunit.result.cache'", $build);
        self::assertStringContainsString("'.phpunit.result.cache'", $release);
    }
}
