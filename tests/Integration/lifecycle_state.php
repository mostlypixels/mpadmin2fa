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
        if ('0.2.8' !== $version) {
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
