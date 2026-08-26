<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_2_1(Mpadmin2fa $module): bool
{
    return $module->upgradeAdminTabs();
}
