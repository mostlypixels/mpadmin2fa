<?php

declare(strict_types=1);

putenv('SYMFONY_DEPRECATIONS_HELPER=disabled');
$root = getenv('MP2FA_PS_ROOT');
if ('1' !== getenv('MP2FA_INTEGRATION') || !$root || 'cli' !== PHP_SAPI) {
    throw new RuntimeException('Use an explicitly disposable installed shop with MP2FA_INTEGRATION=1 and MP2FA_PS_ROOT.');
}
if (!is_file($root . '/modules/mpadmin2fa/SHA256SUMS')) {
    throw new RuntimeException('Integration tests require the built release package.');
}
// PHPUnit belongs to the harness; production classes must come from the package.
foreach ((array) spl_autoload_functions() as $autoload) {
    if (is_array($autoload) && $autoload[0] instanceof Composer\Autoload\ClassLoader) {
        $autoload[0]->setPsr4('Mpadmin2fa\\', []);
    }
}
require_once $root . '/config/config.inc.php';
Module::getInstanceByName('mpadmin2fa');
if (realpath((new ReflectionClass(Mpadmin2fa\Repository\SecurityRepository::class))->getFileName())
    !== realpath($root . '/modules/mpadmin2fa/src/Repository/SecurityRepository.php')
) {
    throw new RuntimeException('A source-checkout class escaped into a package integration test.');
}
