<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_2_8(Mpadmin2fa $module): bool
{
    return (new Mpadmin2fa\Install\SchemaInstaller())->upgradeRateLimitTable()
        && ($module->isRegisteredInHook('actionDispatcherBefore')
            || $module->registerHook('actionDispatcherBefore'))
        && $module->reconcileAdminTabs();
}
