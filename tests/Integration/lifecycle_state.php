<?php

declare(strict_types=1);

$root = getenv('MP2FA_PS_ROOT') ?: dirname(__DIR__, 4);
require_once $root . '/config/config.inc.php';

$database = Db::getInstance();
$action = (string) ($argv[1] ?? '');

$assertCount = static function (string $label, string $sql, int $expected) use ($database): void {
    $actual = (int) $database->getValue($sql);
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf('%s mismatch: expected %d, got %d.', $label, $expected, $actual));
    }
};

$moduleName = 'mpadmin2fa';
$moduleSql = '"' . pSQL($moduleName) . '"';

switch ($action) {
    case 'verify-install':
        $version = (string) $database->getValue('SELECT version FROM ' . _DB_PREFIX_ . 'module WHERE name = ' . $moduleSql);
        if (Module::getInstanceByName($moduleName)->version !== $version) {
            throw new RuntimeException('Installed module version mismatch: ' . $version);
        }
        $assertCount('tables', 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
            . ' AND table_name LIKE "' . pSQL(_DB_PREFIX_ . 'mp2fa_%') . '"', 6);
        $assertCount('tabs', 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'tab WHERE module = ' . $moduleSql, 7);
        $assertCount('failure timestamp', 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()'
            . ' AND table_name = "' . pSQL(_DB_PREFIX_ . 'mp2fa_rate_limit') . '" AND column_name = "last_failure_at"', 1);
        $assertCount('legacy timestamp', 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()'
            . ' AND table_name = "' . pSQL(_DB_PREFIX_ . 'mp2fa_rate_limit') . '" AND column_name = "date_upd"', 0);
        $assertCount('dispatcher hook', 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'hook_module hm'
            . ' INNER JOIN ' . _DB_PREFIX_ . 'hook h ON h.id_hook = hm.id_hook'
            . ' INNER JOIN ' . _DB_PREFIX_ . 'module m ON m.id_module = hm.id_module'
            . ' WHERE m.name = ' . $moduleSql . ' AND h.name = "actionDispatcherBefore"', 1);
        $profiles = (int) $database->getValue('SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'profile');
        $assertCount('navigation access', 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'access a'
            . ' INNER JOIN ' . _DB_PREFIX_ . 'authorization_role r ON r.id_authorization_role = a.id_authorization_role'
            . ' WHERE r.slug IN ("ROLE_MOD_TAB_ADMINMPADMIN2FA_MTR_READ",'
            . ' "ROLE_MOD_TAB_ADMINMPADMIN2FA_READ", "ROLE_MOD_TAB_ADMINMPADMIN2FAAUTHENTICATOR_READ")', 3 * $profiles);
        break;

    case 'verify-repeated-install':
        $module = Module::getInstanceByName($moduleName);
        $employeeId = (int) $database->getValue('SELECT id_employee FROM ' . _DB_PREFIX_ . 'employee ORDER BY id_employee LIMIT 1');
        if ($employeeId <= 0) {
            throw new RuntimeException('Repeated-install coverage requires an employee fixture.');
        }
        $now = pSQL(gmdate('Y-m-d H:i:s'));
        $database->execute('INSERT INTO ' . _DB_PREFIX_ . 'mp2fa_employee'
            . ' (id_employee, status, secret_ciphertext, key_version, last_counter, confirmed_at, date_add, date_upd)'
            . ' VALUES (' . $employeeId . ', "active", "preservation-fixture", 1, 42, "' . $now . '", "' . $now . '", "' . $now . '")');
        $database->execute('INSERT INTO ' . _DB_PREFIX_ . 'mp2fa_recovery_code'
            . ' (id_employee, code_hash, used_at, date_add) VALUES (' . $employeeId . ', "preservation-hash", NULL, "' . $now . '")');
        $database->execute('INSERT INTO ' . _DB_PREFIX_ . 'mp2fa_approval'
            . ' (id_employee, requested_by, approved_by, status, date_add, date_upd)'
            . ' VALUES (' . $employeeId . ', ' . $employeeId . ', NULL, "pending", "' . $now . '", "' . $now . '")');
        $database->execute('INSERT INTO ' . _DB_PREFIX_ . 'mp2fa_rate_limit'
            . ' (scope, subject_hash, failures, blocked_until, last_failure_at)'
            . ' VALUES ("preservation", "' . str_repeat('a', 64) . '", 4, NULL, "' . $now . '")');
        $database->execute('INSERT INTO ' . _DB_PREFIX_ . 'mp2fa_audit'
            . ' (id_employee, event, ip, metadata_json, date_add)'
            . ' VALUES (' . $employeeId . ', "preservation.fixture", "127.0.0.1", "{}", "' . $now . '")');
        $tables = ['mp2fa_keyring', 'mp2fa_employee', 'mp2fa_recovery_code', 'mp2fa_approval', 'mp2fa_rate_limit', 'mp2fa_audit'];
        $snapshot = static function () use ($database, $tables): array {
            $state = [];
            foreach ($tables as $table) {
                $state[$table] = $database->executeS('SELECT * FROM ' . _DB_PREFIX_ . $table . ' ORDER BY 1');
            }
            $state['module'] = $database->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'module'
                . ' WHERE name = "mpadmin2fa" ORDER BY id_module');
            $state['module_shop'] = $database->executeS('SELECT ms.* FROM ' . _DB_PREFIX_ . 'module_shop ms'
                . ' INNER JOIN ' . _DB_PREFIX_ . 'module m ON m.id_module = ms.id_module'
                . ' WHERE m.name = "mpadmin2fa" ORDER BY ms.id_module, ms.id_shop');
            $state['configuration'] = $database->executeS('SELECT name, value FROM ' . _DB_PREFIX_ . 'configuration'
                . ' WHERE name LIKE "MP2FA_%" ORDER BY name, id_shop_group, id_shop');

            return $state;
        };
        $before = $snapshot();
        if ($module->install()) {
            throw new RuntimeException('Repeated installation unexpectedly succeeded.');
        }
        if ($before !== $snapshot()) {
            throw new RuntimeException('Repeated installation changed existing MFA state.');
        }
        if (!Module::isInstalled($moduleName) || !Module::isEnabled($moduleName)) {
            throw new RuntimeException('Repeated installation disabled or unregistered the existing module.');
        }
        $assertCount('preserved module', 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'module WHERE name = ' . $moduleSql, 1);
        $database->delete('mp2fa_employee', 'id_employee = ' . $employeeId);
        $database->delete('mp2fa_approval', 'id_employee = ' . $employeeId);
        $database->delete('mp2fa_rate_limit', 'scope = "preservation"');
        $database->delete('mp2fa_audit', 'event = "preservation.fixture"');
        break;

    case 'prepare-upgrade':
        $database->execute('ALTER TABLE ' . _DB_PREFIX_ . 'mp2fa_rate_limit ADD date_upd DATETIME NULL AFTER blocked_until');
        $database->execute('UPDATE ' . _DB_PREFIX_ . 'mp2fa_rate_limit SET date_upd = last_failure_at');
        $database->execute('ALTER TABLE ' . _DB_PREFIX_ . 'mp2fa_rate_limit DROP COLUMN last_failure_at');
        $database->execute('UPDATE ' . _DB_PREFIX_ . 'module SET version = "0.2.7" WHERE name = ' . $moduleSql);
        $database->execute('DELETE hm FROM ' . _DB_PREFIX_ . 'hook_module hm'
            . ' INNER JOIN ' . _DB_PREFIX_ . 'hook h ON h.id_hook = hm.id_hook'
            . ' INNER JOIN ' . _DB_PREFIX_ . 'module m ON m.id_module = hm.id_module'
            . ' WHERE m.name = ' . $moduleSql . ' AND h.name = "actionDispatcherBefore"');
        break;

    case 'create-tab-trigger':
        $database->execute('DROP TRIGGER IF EXISTS mp2fa_fail_tab_insert');
        if (!$database->execute('CREATE TRIGGER mp2fa_fail_tab_insert BEFORE INSERT ON ' . _DB_PREFIX_ . 'tab'
            . ' FOR EACH ROW SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "injected tab failure"')) {
            throw new RuntimeException('Could not create the tab failure trigger.');
        }
        break;

    case 'verify-rollback':
        $database->execute('DROP TRIGGER IF EXISTS mp2fa_fail_tab_insert');
        $assertCount('module', 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'module WHERE name = ' . $moduleSql, 0);
        $assertCount('tables', 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
            . ' AND table_name LIKE "' . pSQL(_DB_PREFIX_ . 'mp2fa_%') . '"', 0);
        $assertCount('tabs', 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'tab WHERE module = ' . $moduleSql, 0);
        $assertCount('configuration', 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'configuration WHERE name LIKE "MP2FA_%"', 0);
        break;

    case 'verify-cleanup':
        $assertCount('module', 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'module WHERE name = ' . $moduleSql, 0);
        $assertCount('tables', 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
            . ' AND table_name LIKE "' . pSQL(_DB_PREFIX_ . 'mp2fa_%') . '"', 0);
        $assertCount('tabs', 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'tab WHERE module = ' . $moduleSql, 0);
        $assertCount('roles', 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'authorization_role'
            . ' WHERE slug LIKE "ROLE_MOD_TAB_ADMINMPADMIN2FA%"', 0);
        $assertCount('configuration', 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'configuration WHERE name LIKE "MP2FA_%"', 0);
        break;

    default:
        throw new InvalidArgumentException('Unknown lifecycle action: ' . $action);
}
