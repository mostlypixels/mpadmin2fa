<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Mpadmin2fa\Install\FailedInstallEventGuard;
use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Core\Module\ModuleInterface;
use PrestaShopBundle\Event\ModuleManagementEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class FailedInstallEventGuardTest extends TestCase
{
    private function event(string $name): ModuleManagementEvent
    {
        $module = $this->createMock(ModuleInterface::class);
        $module->method('get')->with('name')->willReturn($name);

        return new ModuleManagementEvent($module);
    }

    public function testFailedInstallCannotRecreateTabsAfterRollback(): void
    {
        $dispatcher = new EventDispatcher();
        $guard = new FailedInstallEventGuard('mpadmin2fa', static function (): bool { return true; });
        $dispatcher->addListener(ModuleManagementEvent::INSTALL, $guard, PHP_INT_MAX);
        $registered = false;
        $dispatcher->addListener(ModuleManagementEvent::INSTALL, static function () use (&$registered): void {
            $registered = true;
        });

        $event = $this->event('mpadmin2fa');
        $dispatcher->dispatch($event, ModuleManagementEvent::INSTALL);

        self::assertTrue($event->isPropagationStopped());
        self::assertFalse($registered);
        self::assertNotContains($guard, $dispatcher->getListeners(ModuleManagementEvent::INSTALL));
    }

    public function testOtherModulesRemainUnaffected(): void
    {
        $dispatcher = new EventDispatcher();
        $guard = new FailedInstallEventGuard('mpadmin2fa', static function (): bool { return true; });
        $dispatcher->addListener(ModuleManagementEvent::INSTALL, $guard, PHP_INT_MAX);

        $event = $this->event('anothermodule');
        $dispatcher->dispatch($event, ModuleManagementEvent::INSTALL);

        self::assertFalse($event->isPropagationStopped());
        self::assertContains($guard, $dispatcher->getListeners(ModuleManagementEvent::INSTALL));
    }

    public function testSuccessfulRetryDoesNotInheritAFailureGuard(): void
    {
        $dispatcher = new EventDispatcher();
        $failed = true;
        $guard = new FailedInstallEventGuard('mpadmin2fa', static function () use (&$failed): bool { return $failed; });
        $dispatcher->addListener(ModuleManagementEvent::INSTALL, $guard, PHP_INT_MAX);

        // Newer PS8 versions do not dispatch on failure; the next event may be a successful retry.
        $failed = false;
        $event = $this->event('mpadmin2fa');
        $dispatcher->dispatch($event, ModuleManagementEvent::INSTALL);

        self::assertFalse($event->isPropagationStopped());
        self::assertNotContains($guard, $dispatcher->getListeners(ModuleManagementEvent::INSTALL));
    }
}
