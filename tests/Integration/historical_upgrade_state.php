<?php

declare(strict_types=1);

$root = getenv('MP2FA_PS_ROOT');
$snapshotPath = getenv('MP2FA_UPGRADE_SNAPSHOT');
if ('1' !== getenv('MP2FA_INTEGRATION') || !$root || !$snapshotPath || 'cli' !== PHP_SAPI) {
    throw new RuntimeException('Historical upgrade checks require an explicitly disposable shop.');
}
require_once $root . '/config/config.inc.php';
$module = Module::getInstanceByName('mpadmin2fa');
// Boot neither version's container: exercise each package's own repository and crypto.
$parameters = require $root . '/app/config/parameters.php';
$p = $parameters['parameters'];
$connection = Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $p['database_host'], 'port' => $p['database_port'] ?: 3306,
    'dbname' => $p['database_name'], 'user' => $p['database_user'], 'password' => $p['database_password'],
]);
$repository = new Mpadmin2fa\Repository\SecurityRepository($connection, _DB_PREFIX_);
$keys = new Mpadmin2fa\Security\KeyManager($repository, new Mpadmin2fa\Security\CookieKeyProvider(), new Mpadmin2fa\Security\ProtectedKeyRewrapper());
$db = Db::getInstance();
$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException('Historical upgrade failed: ' . $label);
    }
    echo 'PASS: ' . $label . PHP_EOL;
};
$rows = static function (string $table) use ($db): array {
    $rows = $db->executeS('SELECT * FROM ' . _DB_PREFIX_ . $table . ' ORDER BY 1');
    if (false === $rows) {
        throw new RuntimeException('Cannot read historical fixture state.');
    }
    return $rows;
};
$version = (string) $db->getValue('SELECT version FROM ' . _DB_PREFIX_ . 'module WHERE name = "mpadmin2fa"');
$action = (string) ($argv[1] ?? '');
if ('snapshot' === $action) {
    $assert('0.2.7' === $module->version && '0.2.7' === $version, 'the historical installer recorded version 0.2.7');
    $assert(!method_exists($module, 'reconcileAdminTabs'), 'the loaded installer predates lifecycle remediation');
    $assert(!$module->isRegisteredInHook('actionDispatcherBefore'), 'historical installation lacks the new dispatcher hook');
    $assert(1 === (int) $db->getValue('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()'
        . ' AND table_name = "' . pSQL(_DB_PREFIX_ . 'mp2fa_rate_limit') . '" AND column_name = "date_upd"'),
        'historical installation created its original rate-limit schema');
    $employeeId = (int) $db->getValue('SELECT id_employee FROM ' . _DB_PREFIX_ . 'employee WHERE email = "' . pSQL((string) getenv('MP2FA_TEST_EMAIL')) . '"');
    $assert($employeeId > 0, 'the generated test employee exists');
    $secret = (new Mpadmin2fa\Security\TotpService())->generateSecret();
    $recovery = bin2hex(random_bytes(20));
    $encrypted = $keys->encrypt($secret);
    $repository->savePendingEnrollment($employeeId, $encrypted['ciphertext'], $encrypted['key_version']);
    $repository->activateEnrollment($employeeId, (int) floor(time() / 30) - 2, [password_hash($recovery, PASSWORD_DEFAULT)]);
    $assert($db->insert('mp2fa_rate_limit', [
        'scope' => 'challenge', 'subject_hash' => hash('sha256', 'historical-upgrade'),
        'failures' => 4, 'blocked_until' => '2030-01-01 00:00:00', 'date_upd' => '2026-01-02 03:04:05',
    ]), 'historical rate-limit history was populated');
    $assert($db->insert('mp2fa_approval', [
        'id_employee' => $employeeId, 'requested_by' => $employeeId, 'status' => 'pending',
        'date_add' => '2026-01-02 03:04:05', 'date_upd' => '2026-01-02 03:04:05',
    ]), 'historical approval history was populated');
    $assert($db->insert('mp2fa_audit', [
        'id_employee' => $employeeId, 'event' => 'enrollment.confirmed', 'metadata_json' => '{}',
        'date_add' => '2026-01-02 03:04:05',
    ]), 'historical audit history was populated');
    Configuration::updateValue('MP2FA_STEP_UP_SECONDS', 120);
    Configuration::updateValue('MP2FA_SECURITY_RECIPIENTS', 'upgrade-fixture@example.test');
    $snapshot = ['employee' => $employeeId, 'secret' => $secret, 'recovery' => $recovery, 'tables' => []];
    foreach (['mp2fa_keyring', 'mp2fa_employee', 'mp2fa_recovery_code', 'mp2fa_approval', 'mp2fa_audit', 'mp2fa_rate_limit'] as $table) {
        $snapshot['tables'][$table] = $rows($table);
    }
    file_put_contents($snapshotPath, json_encode($snapshot));
    chmod($snapshotPath, 0600);
    exit(0);
}
if ('inject-failure' === $action) {
    $entrypoint = realpath($root . '/modules/mpadmin2fa/mpadmin2fa.php');
    if (!$entrypoint || $entrypoint === realpath(dirname(__DIR__, 2) . '/mpadmin2fa.php')
        || !is_file(dirname($entrypoint) . '/SHA256SUMS')
    ) {
        throw new RuntimeException('Refusing to instrument an unpackaged source checkout.');
    }
    $source = file_get_contents($entrypoint);
    $marker = '            && $this->installConfiguration(true)';
    $assert(1 === substr_count($source, $marker), 'upgrade failure marker is unambiguous');
    file_put_contents($entrypoint, str_replace($marker,
        '            && (function (): bool { throw new RuntimeException("injected mp2fa upgrade failure after schema"); })()'
        . "\n" . $marker, $source));
    exit(0);
}
$assert(in_array($action, ['verify', 'repeat', 'repair', 'verify-failed'], true), 'valid verification phase');
if ('repair' === $action) {
    $assert($db->execute('UPDATE ' . _DB_PREFIX_ . 'mp2fa_rate_limit SET last_failure_at = NULL'),
        'partial-upgrade timestamp backfill is pending');
}
if (in_array($action, ['repeat', 'repair'], true)) {
    require_once $root . '/modules/mpadmin2fa/upgrade/upgrade-1.0.0rc1.php';
    $assert(upgrade_module_1_0_0rc1($module), 'the RC upgrade entry point can run twice');
}
$assert('1.0.0rc1' === $module->version && ('verify-failed' === $action ? '0.2.7' : '1.0.0rc1') === $version,
    'verify-failed' === $action ? 'failed upgrade preserves the previous installed version'
        : 'native upgrade advanced the installed version to the RC');
$snapshot = json_decode(file_get_contents($snapshotPath), true);
foreach ($snapshot['tables'] as $table => $expected) {
    if ('mp2fa_rate_limit' === $table) {
        foreach ($expected as &$row) {
            $row['last_failure_at'] = $row['date_upd'];
        }
        unset($row);
    }
    // SQL column order changes during migration; compare values by column name.
    $assert($expected == $rows($table), $table . ' data survives upgrade unchanged');
}
$factor = $repository->factor($snapshot['employee']);
$assert(hash_equals($snapshot['secret'], $keys->decrypt($factor['secret_ciphertext'], (int) $factor['key_version'])),
    'the new scoped crypto decrypts the historical enrolled secret');
$recoveryRows = $rows('mp2fa_recovery_code');
$assert(password_verify($snapshot['recovery'], $recoveryRows[0]['code_hash']) && null === $recoveryRows[0]['used_at'],
    'the historical recovery code remains valid and unused');
$assert(120 === (int) Configuration::get('MP2FA_STEP_UP_SECONDS')
    && 'upgrade-fixture@example.test' === Configuration::get('MP2FA_SECURITY_RECIPIENTS'),
    'configured policy values survive upgrade');

// Fresh writes must work under strict SQL mode against the migrated historical schema.
$connection->executeUpdate("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
$subject = hash('sha256', 'new-after-historical-upgrade');
try {
    $assert(1 === $repository->incrementFailure('challenge', $subject),
        'new rate-limit subjects can be inserted after historical upgrade in strict SQL mode');
    $assert(2 === $repository->incrementFailure('challenge', $subject),
        'new rate-limit subjects retain atomic increments after historical upgrade');
} finally {
    $repository->clearFailures('challenge', $subject);
}
