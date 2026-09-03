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

        self::assertStringContainsString("'actionDispatcherBefore'", $module);
        self::assertStringContainsString('$this->registerHook($hook)', $module);
        self::assertStringContainsString('function hookActionDispatcherBefore', $module);
        self::assertStringContainsString('LegacyAdminMfaAdapter::class', $module);
    }

    public function testPs8InstallOwnsTabAccessBoundaryAndPostInstallIsRepairOnly(): void
    {
        $module = (string) file_get_contents(dirname(__DIR__, 2) . '/mpadmin2fa.php');
        $upgrade = (string) file_get_contents(dirname(__DIR__, 2) . '/upgrade/upgrade-0.2.8.php');
        $lifecycle = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Install/AdminTabLifecycle.php');

        self::assertStringContainsString('private $declaredTabs = [];', $module);
        self::assertStringContainsString('$this->tabs = [];', $module);
        self::assertStringContainsString('if (!$this->reconcileAdminTabs())', $module);
        self::assertStringContainsString('$this->rollbackInstall($parentAttempted);', $module);
        self::assertStringContainsString('function postInstall(): bool', $module);
        self::assertStringContainsString('return $this->reconcileAdminTabs();', $module);
        self::assertStringContainsString('function reconcileAdminTabs(): bool', $module);
        self::assertStringContainsString('$module->reconcileAdminTabs()', $upgrade);
        self::assertStringContainsString('function remove(string $moduleName, array $declaredTabs): bool', $lifecycle);
        self::assertStringContainsString('return $this->grantDefaultAccess();', $lifecycle);
    }

    public function testScopedReleasePreservesModuleAndPrestaShopClasses(): void
    {
        $build = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/build-scoped.php');
        $scoper = (string) file_get_contents(dirname(__DIR__, 2) . '/php-scoper.inc.php');

        self::assertStringContainsString("'prestashop'", $build);
        self::assertStringContainsString('composer config autoloader-suffix MpAdmin2FaScoped', $build);
        self::assertStringContainsString('assertModuleNamespacesPreserved($releaseRoot);', $build);
        self::assertStringContainsString('writePrestaShopAutoloadBridge($releaseRoot);', $build);
        self::assertStringNotContainsString("'expose-namespaces'", $scoper);
        self::assertStringContainsString("'Mpadmin2fa'", $scoper);
        self::assertStringContainsString("'Profile'", $scoper);
        self::assertStringContainsString("'Tab'", $scoper);
    }

    public function testReleasePackageRejectsPhpUnitCache(): void
    {
        $build = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/build-scoped.php');
        $release = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/release.php');

        self::assertStringContainsString("'.phpunit.result.cache'", $build);
        self::assertStringContainsString("'.phpunit.result.cache'", $release);
    }

    public function testHttpsHarnessUsesAPrivateWritablePhpSessionDirectory(): void
    {
        $harness = (string) file_get_contents(dirname(__DIR__, 2) . '/tests/Integration/run_requests.sh');

        self::assertStringContainsString('mkdir "$runtime/php-sessions"', $harness);
        self::assertStringContainsString('chmod 700 "$runtime/php-sessions"', $harness);
        self::assertStringContainsString('php_admin_value session.save_path "$runtime/php-sessions"', $harness);
    }
}
