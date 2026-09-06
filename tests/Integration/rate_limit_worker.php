<?php

declare(strict_types=1);

// Workers exercise the package repository without racing PrestaShop's translation-cache bootstrap.
$root = getenv('MP2FA_PS_ROOT');
if ('1' !== getenv('MP2FA_INTEGRATION') || !$root || 'cli' !== PHP_SAPI
    || !is_file($root . '/modules/mpadmin2fa/SHA256SUMS')
) {
    throw new RuntimeException('Workers require an explicitly disposable installed package.');
}
require $root . '/vendor/autoload.php';
require $root . '/modules/mpadmin2fa/vendor/autoload.php';
if (realpath((new ReflectionClass(Mpadmin2fa\Repository\SecurityRepository::class))->getFileName())
    !== realpath($root . '/modules/mpadmin2fa/src/Repository/SecurityRepository.php')
) {
    throw new RuntimeException('The worker must load the package repository.');
}
$parameters = require $root . '/app/config/parameters.php';
$p = $parameters['parameters'];
$connection = Doctrine\DBAL\DriverManager::getConnection([
    'dbname' => $p['database_name'],
    'driver' => 'pdo_mysql',
    'host' => $p['database_host'],
    'port' => $p['database_port'] ?: 3306,
    'password' => $p['database_password'],
    'user' => $p['database_user'],
]);
$repository = new Mpadmin2fa\Repository\SecurityRepository($connection, $p['database_prefix']);
$connection->connect();
echo "READY\n";
flush();
if ("GO\n" !== fgets(STDIN)) {
    throw new RuntimeException('The concurrency barrier was not released.');
}
echo $repository->incrementFailure((string) $argv[1], (string) $argv[2]), "\n";
