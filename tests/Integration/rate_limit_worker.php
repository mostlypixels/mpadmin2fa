<?php

declare(strict_types=1);

$root = getenv('MP2FA_PS_ROOT') ?: dirname(__DIR__, 4);
require_once $root . '/config/config.inc.php';
Module::getInstanceByName('mpadmin2fa');

$connection = Doctrine\DBAL\DriverManager::getConnection([
    'dbname' => _DB_NAME_,
    'driver' => 'pdo_mysql',
    'host' => _DB_SERVER_,
    'password' => _DB_PASSWD_,
    'user' => _DB_USER_,
]);
$repository = new Mpadmin2fa\Repository\SecurityRepository($connection, _DB_PREFIX_);

echo $repository->incrementFailure((string) $argv[1], (string) $argv[2], 5, 3600);
