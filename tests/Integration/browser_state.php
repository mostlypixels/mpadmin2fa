<?php

declare(strict_types=1);

$root = getenv('MP2FA_PS_ROOT');
$runtime = getenv('MP2FA_REQUEST_RUNTIME');
$email = getenv('MP2FA_TEST_EMAIL');
if ('1' !== getenv('MP2FA_INTEGRATION') || !$root || !$runtime || !$email || 'cli' !== PHP_SAPI) {
    throw new RuntimeException('Browser fixtures require an explicitly disposable installed shop.');
}
require_once $root . '/config/config.inc.php';
$employeeId = (int) Db::getInstance()->getValue('SELECT id_employee FROM ' . _DB_PREFIX_ . 'employee WHERE email = "' . pSQL($email) . '"');
if ($employeeId <= 0 || !Module::isInstalled('mpadmin2fa')) {
    throw new RuntimeException('Expected generated employee credentials and the installed package.');
}
// Reset request-test enrollment fixtures so the browser exercises first-SuperAdmin setup.
foreach (['recovery_code', 'employee', 'approval', 'rate_limit'] as $table) {
    if (!Db::getInstance()->execute('DELETE FROM ' . _DB_PREFIX_ . 'mp2fa_' . $table)) {
        throw new RuntimeException('Cannot reset disposable browser fixture.');
    }
}
Configuration::updateValue('PS_SSL_ENABLED', 1);
Configuration::updateValue('PS_SSL_ENABLED_EVERYWHERE', 1);
Configuration::updateValue('PS_COOKIE_CHECKIP', 0);
Configuration::updateValue('PS_SHOP_DOMAIN', 'localhost:8080');
Configuration::updateValue('PS_SHOP_DOMAIN_SSL', 'localhost:8443');
if (!Db::getInstance()->execute('UPDATE ' . _DB_PREFIX_ . 'shop_url SET domain = "localhost:8080", domain_ssl = "localhost:8443"')) {
    throw new RuntimeException('Cannot configure disposable browser origins.');
}
Configuration::updateValue('MP2FA_STEP_UP_SECONDS', 60);
$modern = '/admin-dev/index.php/modules/mpadmin2fa/';
$token = '?token=' . Tools::getAdminToken($employeeId);
$routes = [
    'enroll' => $modern . 'enroll' . $token,
    'challenge' => $modern . 'challenge' . $token,
    'policy' => $modern . 'security' . $token,
    'settings' => $modern . 'settings' . $token,
    'dashboard' => '/admin-dev/index.php?controller=AdminDashboard&token='
        . Tools::getAdminToken('AdminDashboard' . (int) Tab::getIdFromClassName('AdminDashboard') . $employeeId),
    'legacySensitive' => '/admin-dev/index.php?controller=AdminModules&token='
        . Tools::getAdminToken('AdminModules' . (int) Tab::getIdFromClassName('AdminModules') . $employeeId)
        . '&action=install&module_name=mp2fa_missing_browser_fixture',
];
file_put_contents($runtime . '/browser-routes.json', json_encode($routes));
chmod($runtime . '/browser-routes.json', 0600);
echo 'Disposable browser fixture prepared.' . PHP_EOL;
