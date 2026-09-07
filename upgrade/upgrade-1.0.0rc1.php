<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_0rc1(Mpadmin2fa $module): bool
{
    // Repair development 0.2.8 installations that skip the older migration.
    require_once __DIR__ . '/upgrade-0.2.8.php';

    return upgrade_module_0_2_8($module);
}
