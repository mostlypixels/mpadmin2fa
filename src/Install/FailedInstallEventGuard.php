<?php

declare(strict_types=1);

namespace Mpadmin2fa\Install;

use PrestaShopBundle\Event\ModuleManagementEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * PS 8.0.0 dispatches module.install even when onInstall() returns false.
 */
final class FailedInstallEventGuard
{
    /** @var string */
    private $moduleName;

    /** @var callable */
    private $hasFailed;

    public function __construct(string $moduleName, callable $hasFailed)
    {
        $this->moduleName = $moduleName;
        $this->hasFailed = $hasFailed;
    }

    public function __invoke(ModuleManagementEvent $event, string $eventName, EventDispatcherInterface $dispatcher): void
    {
        if ($this->moduleName !== $event->getModule()->get('name')) {
            return;
        }

        // One-shot, and a successful retry in the same process must remain allowed.
        $dispatcher->removeListener($eventName, $this);
        if (call_user_func($this->hasFailed)) {
            $event->stopPropagation();
        }
    }
}
