<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_2_5(Mpadmin2fa $module): bool
{
    return $module->registerHook('dashboardZoneOne')
        && $module->registerHook('displayAdminDashboardZoneOne');
}
