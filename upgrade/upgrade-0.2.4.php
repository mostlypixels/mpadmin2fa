<?php

declare(strict_types=1);

use PrestaShop\PrestaShop\Core\MailTemplate\ThemeCatalogInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_2_4(Mpadmin2fa $module): bool
{
    return $module->registerHook(ThemeCatalogInterface::LIST_MAIL_THEMES_HOOK);
}
