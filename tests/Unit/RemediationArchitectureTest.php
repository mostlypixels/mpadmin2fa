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

    public function testLegacyEnforcementRunsBeforeControllerAction(): void
    {
        $module = (string) file_get_contents(dirname(__DIR__, 2) . '/mpadmin2fa.php');

        self::assertStringContainsString("'actionDispatcherBefore'", $module);
        self::assertStringContainsString('registerRequiredHooks()', $module);
        self::assertStringContainsString('function hookActionDispatcherBefore', $module);
        self::assertStringContainsString('LegacyAdminMfaAdapter::class', $module);
    }

    public function testUpgradeReusesIdempotentTabReconciliation(): void
    {
        $module = (string) file_get_contents(dirname(__DIR__, 2) . '/mpadmin2fa.php');
        $upgrade = (string) file_get_contents(dirname(__DIR__, 2) . '/upgrade/upgrade-0.2.8.php');

        self::assertStringContainsString('function reconcileAdminTabs(): bool', $module);
        self::assertStringContainsString('$module->reconcileAdminTabs()', $upgrade);
    }

    public function testCompatibilityStopsAtLatestValidatedStableMinor(): void
    {
        $module = (string) file_get_contents(dirname(__DIR__, 2) . '/mpadmin2fa.php');

        self::assertStringContainsString(
            "['min' => '9.0.0', 'max' => '9.1.99']",
            $module
        );
    }
}
