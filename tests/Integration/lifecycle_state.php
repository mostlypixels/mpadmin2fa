<?php

declare(strict_types=1);

// This harness changes module state and installs database failure triggers.
$root = getenv('MP2FA_PS_ROOT');
if ('1' !== getenv('MP2FA_INTEGRATION') || !$root) {
    throw new RuntimeException('Use MP2FA_INTEGRATION=1 and MP2FA_PS_ROOT with a disposable installed shop.');
}
require_once $root . '/config/config.inc.php';

$database = Db::getInstance();
$action = (string) ($argv[1] ?? '');
$prefix = _DB_PREFIX_;
$moduleSql = '"mpadmin2fa"';
$assertCount = static function (string $label, string $sql, int $expected) use ($database): void {
    $actual = $database->getValue($sql);
    if (false === $actual || (int) $actual !== $expected) {
        throw new RuntimeException(sprintf('%s mismatch: expected %d, got %s.', $label, $expected, var_export($actual, true)));
    }
};
$execute = static function (string $sql) use ($database): void {
    if (!$database->execute($sql)) {
        throw new RuntimeException('Lifecycle fixture statement failed: ' . $sql);
    }
};
$verifyCleanup = static function () use ($assertCount, $prefix, $moduleSql): void {
    $assertCount('module', 'SELECT COUNT(*) FROM ' . $prefix . 'module WHERE name = ' . $moduleSql, 0);
    $assertCount('tables', 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
        . ' AND LEFT(table_name, ' . strlen($prefix . 'mp2fa_') . ') = "' . pSQL($prefix . 'mp2fa_') . '"', 0);
    $assertCount('tabs', 'SELECT COUNT(*) FROM ' . $prefix . 'tab WHERE module = ' . $moduleSql, 0);
    $assertCount('roles', 'SELECT COUNT(*) FROM ' . $prefix . 'authorization_role'
        . ' WHERE slug LIKE "ROLE_MOD_TAB_ADMINMPADMIN2FA%" OR slug LIKE "ROLE_MOD_MODULE_MPADMIN2FA_%"', 0);
    $assertCount('configuration', 'SELECT COUNT(*) FROM ' . $prefix . 'configuration WHERE name LIKE "MP2FA_%"', 0);
    foreach (['hook_module', 'module_shop', 'module_country', 'module_currency', 'module_group'] as $table) {
        $assertCount('orphan ' . $table, 'SELECT COUNT(*) FROM ' . $prefix . $table . ' child'
            . ' LEFT JOIN ' . $prefix . 'module m ON m.id_module = child.id_module WHERE m.id_module IS NULL', 0);
    }
    foreach (['access', 'module_access'] as $table) {
        $assertCount('orphan ' . $table, 'SELECT COUNT(*) FROM ' . $prefix . $table . ' a'
            . ' LEFT JOIN ' . $prefix . 'authorization_role r ON r.id_authorization_role = a.id_authorization_role'
            // Some stock PS8 fixtures contain a legacy zero-role access row.
            . ' WHERE r.id_authorization_role IS NULL AND a.id_authorization_role > 0', 0);
    }
};

switch ($action) {
    case 'prepare-profile':
        $verifyCleanup();
        $profile = new Profile();
        foreach (Language::getLanguages(false) as $language) {
            $profile->name[(int) $language['id_lang']] = 'MFA lifecycle ordinary profile';
        }
        if (!$profile->add() || (int) $profile->id === (int) _PS_ADMIN_PROFILE_) {
            throw new RuntimeException('Could not create the ordinary-profile fixture.');
        }
        break;

    case 'verify-install':
        $assertCount('version', 'SELECT COUNT(*) FROM ' . $prefix . 'module WHERE name = ' . $moduleSql . ' AND version = "0.2.8"', 1);
        $assertCount('tables', 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
            . ' AND LEFT(table_name, ' . strlen($prefix . 'mp2fa_') . ') = "' . pSQL($prefix . 'mp2fa_') . '"', 6);
        $assertCount('tabs', 'SELECT COUNT(*) FROM ' . $prefix . 'tab WHERE module = ' . $moduleSql, 7);
        $assertCount('configuration', 'SELECT COUNT(*) FROM ' . $prefix . 'configuration WHERE name LIKE "MP2FA_%"', 7);
        $assertCount('active key', 'SELECT COUNT(*) FROM ' . $prefix . 'mp2fa_keyring WHERE active = 1', 1);
        $assertCount('failure timestamp', 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()'
            . ' AND table_name = "' . pSQL($prefix . 'mp2fa_rate_limit') . '" AND column_name = "last_failure_at"', 1);
        $assertCount('required hooks', 'SELECT COUNT(DISTINCT h.name) FROM ' . $prefix . 'hook_module hm'
            . ' INNER JOIN ' . $prefix . 'hook h ON h.id_hook = hm.id_hook'
            . ' INNER JOIN ' . $prefix . 'module m ON m.id_module = hm.id_module'
            . ' WHERE m.name = ' . $moduleSql . ' AND h.name IN ("actionDispatcherBefore", "actionAdminControllerSetMedia",'
            . ' "actionObjectProfileDeleteAfter", "dashboardZoneOne", "displayAdminDashboardZoneOne", "actionListMailThemes",'
            . ' "actionAdminLoginControllerLoginAfter")', 7);
        $profiles = (int) $database->getValue('SELECT COUNT(*) FROM ' . $prefix . 'profile');
        if ($profiles < 2) {
            throw new RuntimeException('Clean-install coverage requires an ordinary profile.');
        }
        $assertCount('navigation access', 'SELECT COUNT(*) FROM ' . $prefix . 'access a'
            . ' INNER JOIN ' . $prefix . 'authorization_role r ON r.id_authorization_role = a.id_authorization_role'
            . ' WHERE r.slug IN ("ROLE_MOD_TAB_ADMINMPADMIN2FA_MTR_READ",'
            . ' "ROLE_MOD_TAB_ADMINMPADMIN2FA_READ", "ROLE_MOD_TAB_ADMINMPADMIN2FAAUTHENTICATOR_READ")', 3 * $profiles);
        $assertCount('ordinary-profile privileged access', 'SELECT COUNT(*) FROM ' . $prefix . 'access a'
            . ' INNER JOIN ' . $prefix . 'authorization_role r ON r.id_authorization_role = a.id_authorization_role'
            . ' WHERE a.id_profile <> ' . (int) _PS_ADMIN_PROFILE_ . ' AND r.slug LIKE "ROLE_MOD_TAB_ADMINMPADMIN2FA%"'
            . ' AND r.slug NOT IN ("ROLE_MOD_TAB_ADMINMPADMIN2FA_MTR_READ",'
            . ' "ROLE_MOD_TAB_ADMINMPADMIN2FA_READ", "ROLE_MOD_TAB_ADMINMPADMIN2FAAUTHENTICATOR_READ")', 0);
        break;

    case 'verify-repeated-install':
        $module = Module::getInstanceByName('mpadmin2fa');
        $before = $database->getRow('SELECT * FROM ' . $prefix . 'mp2fa_keyring WHERE active = 1');
        if ($module->install()) {
            throw new RuntimeException('Repeated installation unexpectedly succeeded.');
        }
        $after = $database->getRow('SELECT * FROM ' . $prefix . 'mp2fa_keyring WHERE active = 1');
        if (!$before || $before !== $after) {
            throw new RuntimeException('Repeated installation changed or removed the active encryption key.');
        }
        $assertCount('preserved module', 'SELECT COUNT(*) FROM ' . $prefix . 'module WHERE name = ' . $moduleSql, 1);
        break;

    case 'prepare-upgrade':
        $execute('ALTER TABLE ' . $prefix . 'mp2fa_rate_limit ADD date_upd DATETIME NULL AFTER blocked_until');
        $execute('UPDATE ' . $prefix . 'mp2fa_rate_limit SET date_upd = last_failure_at');
        $execute('ALTER TABLE ' . $prefix . 'mp2fa_rate_limit DROP COLUMN last_failure_at');
        $execute('UPDATE ' . $prefix . 'module SET version = "0.2.7" WHERE name = ' . $moduleSql);
        $execute('DELETE hm FROM ' . $prefix . 'hook_module hm'
            . ' INNER JOIN ' . $prefix . 'hook h ON h.id_hook = hm.id_hook'
            . ' INNER JOIN ' . $prefix . 'module m ON m.id_module = hm.id_module'
            . ' WHERE m.name = ' . $moduleSql . ' AND h.name IN ("actionDispatcherBefore", "actionAdminLoginControllerLoginAfter")');
        $execute('DELETE a FROM ' . $prefix . 'access a'
            . ' INNER JOIN ' . $prefix . 'authorization_role r ON r.id_authorization_role = a.id_authorization_role'
            . ' WHERE a.id_profile <> ' . (int) _PS_ADMIN_PROFILE_ . ' AND r.slug LIKE "ROLE_MOD_TAB_ADMINMPADMIN2FA%"');
        break;

    case 'prepare-failure':
        $verifyCleanup();
        $stage = (string) ($argv[2] ?? '');
        $conditions = [
            'configuration' => ['configuration', 'NEW.name = "MP2FA_SECURITY_RECIPIENTS"'],
            'hook' => ['hook_module', 'NEW.id_hook = (SELECT id_hook FROM ' . $prefix . 'hook WHERE name = "actionListMailThemes")'
                . ' AND NEW.id_module = (SELECT id_module FROM ' . $prefix . 'module WHERE name = ' . $moduleSql . ')'],
            'tab' => ['tab', 'NEW.class_name = "AdminMpAdmin2faSecurityActivity"'],
            'access' => ['access', 'NEW.id_profile <> ' . (int) _PS_ADMIN_PROFILE_
                . ' AND NEW.id_authorization_role = (SELECT id_authorization_role FROM ' . $prefix . 'authorization_role'
                . ' WHERE slug = "ROLE_MOD_TAB_ADMINMPADMIN2FAAUTHENTICATOR_READ")'],
        ];
        if ('schema' === $stage) {
            // Let all six CREATE statements complete, then reject the initial encryption key.
            Module::getInstanceByName('mpadmin2fa');
            $installer = new Mpadmin2fa\Install\SchemaInstaller();
            $statements = new ReflectionMethod($installer, 'statements');
            $statements->setAccessible(true);
            $execute($statements->invoke($installer)[0]);
            $conditions['schema'] = ['mp2fa_keyring', 'NEW.version = 1'];
        }
        if (!isset($conditions[$stage])) {
            throw new InvalidArgumentException('Unknown failure stage: ' . $stage);
        }
        [$table, $condition] = $conditions[$stage];
        $execute('CREATE TRIGGER mp2fa_lifecycle_failure BEFORE INSERT ON ' . $prefix . $table
            . ' FOR EACH ROW BEGIN IF ' . $condition . ' THEN SIGNAL SQLSTATE "45000"'
            . ' SET MESSAGE_TEXT = "injected mp2fa ' . pSQL($stage) . ' failure"; END IF; END');
        break;

    case 'verify-rollback':
        $execute('DROP TRIGGER IF EXISTS mp2fa_lifecycle_failure');
        $verifyCleanup();
        break;

    case 'verify-cleanup':
        $verifyCleanup();
        break;

    default:
        throw new InvalidArgumentException('Unknown lifecycle action: ' . $action);
}

echo 'Lifecycle check passed: ' . $action . PHP_EOL;
