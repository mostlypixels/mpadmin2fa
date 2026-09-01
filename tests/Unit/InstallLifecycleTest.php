<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InstallLifecycleTest extends TestCase
{
    private string $module;

    protected function setUp(): void
    {
        $module = file_get_contents(dirname(__DIR__, 2) . '/mpadmin2fa.php');
        self::assertIsString($module);
        $this->module = $module;
    }

    public function testInstallOwnsEveryStageBeforeReturningSuccess(): void
    {
        $install = $this->methodBody('install');

        self::assertStringContainsString('$this->tabs = [];', $this->module);
        self::assertLessThan(strpos($install, 'new SchemaInstaller()'), strpos($install, 'parent::install()'));
        self::assertLessThan(strpos($install, 'installConfiguration()'), strpos($install, 'new SchemaInstaller()'));
        self::assertLessThan(strpos($install, 'registerRequiredHooks()'), strpos($install, 'installConfiguration()'));
        self::assertLessThan(strpos($install, 'reconcileAdminTabs()'), strpos($install, 'registerRequiredHooks()'));
    }

    public function testFailureRollbackAndUninstallOwnEveryResource(): void
    {
        foreach ([
            'AdminTabLifecycle())->remove',
            'deleteConfiguration()',
            'SchemaInstaller())->uninstall',
            'parent::uninstall()',
        ] as $cleanup) {
            self::assertStringContainsString($cleanup, $this->module);
        }
    }

    public function testPostInstallIsOnlyAnIdempotentCompatibilityWrapper(): void
    {
        self::assertStringContainsString(
            "public function postInstall(): bool\n    {\n        return \$this->reconcileAdminTabs();",
            $this->module,
        );
        self::assertGreaterThanOrEqual(3, substr_count($this->module, 'reconcileAdminTabs()'));
    }

    private function methodBody(string $method): string
    {
        $start = strpos($this->module, 'public function ' . $method . '(');
        self::assertNotFalse($start);
        $next = strpos($this->module, "\n    public function ", $start + 1);

        return false === $next ? substr($this->module, $start) : substr($this->module, $start, $next - $start);
    }
}
