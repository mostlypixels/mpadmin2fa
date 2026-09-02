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
    ],
    'exclude-namespaces' => [
        'Composer',
        'Doctrine',
        'Mpadmin2fa',
        'PrestaShop',
        'PrestaShopBundle',
        'Psr',
        'Symfony',
        'Twig',
    ],
    'patchers' => [
        static function (string $filePath, string $prefix, string $contents): string {
            return str_replace('#[\SensitiveParameter] ', '', $contents);
        },
    ],
    'exclude-classes' => [
        'Access',
        'Configuration',
        'Context',
        'Db',
        'Dispatcher',
        'Language',
        'Mail',
        'Module',
        'Mpadmin2fa',
        'Profile',
        'Tab',
        'Tools',
        'Validate',
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
