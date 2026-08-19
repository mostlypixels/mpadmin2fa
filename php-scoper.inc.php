<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

$scopeRoot = getenv('MP2FA_SCOPE_ROOT') ?: __DIR__;

return [
    'prefix' => 'MpAdmin2Fa\\Mpadmin2faVendor',
    'finders' => [
        Finder::create()->files()->name('*.php')->in([
            $scopeRoot . '/src',
            $scopeRoot . '/vendor',
        ]),
        Finder::create()->files()->depth('== 0')->name('mpadmin2fa.php')->in($scopeRoot),
    ],
    'exclude-namespaces' => [
        'Composer',
        'Doctrine',
        'PrestaShop',
        'PrestaShopBundle',
        'Psr',
        'Symfony',
        'Twig',
    ],
    'expose-namespaces' => ['Mpadmin2fa'],
    'exclude-classes' => [
        'Configuration',
        'Db',
        'Language',
        'Mail',
        'Module',
        'Tools',
    ],
    'exclude-functions' => ['pSQL'],
    'exclude-constants' => [
        '_DB_PREFIX_',
        '_MYSQL_ENGINE_',
        '_NEW_COOKIE_KEY_',
        '_PS_ADMIN_PROFILE_',
        '_PS_VERSION_',
    ],
];
