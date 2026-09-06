<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$path = getenv('MP2FA_CLEAN_SHAPE');
if (!$path) {
    throw new RuntimeException('Set MP2FA_CLEAN_SHAPE to the private clean-install reference file.');
}
$db = Db::getInstance();
$read = static function (string $sql) use ($db): array {
    $rows = $db->executeS($sql, true, false);
    if (false === $rows) {
        throw new RuntimeException('Cannot inspect installation structure.');
    }

    return $rows;
};
$prefix = _DB_PREFIX_;
$shape = [];
foreach (['employee', 'keyring', 'recovery_code', 'rate_limit', 'audit', 'approval'] as $table) {
    $name = $prefix . 'mp2fa_' . $table;
    // Column order and generated IDs may differ after ALTER TABLE; their definitions must agree.
    $shape['columns_' . $table] = $read('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA'
        . ' FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = "' . pSQL($name) . '" ORDER BY COLUMN_NAME');
    $shape['indexes_' . $table] = $read('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART'
        . ' FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = "' . pSQL($name) . '" ORDER BY INDEX_NAME, SEQ_IN_INDEX');
}
$shape['tabs'] = $read('SELECT t.class_name, parent.class_name AS parent_class, t.active, t.route_name'
    . ' FROM ' . $prefix . 'tab t LEFT JOIN ' . $prefix . 'tab parent ON parent.id_tab = t.id_parent'
    . ' WHERE t.module = "mpadmin2fa" ORDER BY t.class_name');
$shape['hooks'] = $read('SELECT DISTINCT h.name FROM ' . $prefix . 'hook h'
    . ' INNER JOIN ' . $prefix . 'hook_module hm ON hm.id_hook = h.id_hook'
    . ' INNER JOIN ' . $prefix . 'module m ON m.id_module = hm.id_module'
    . ' WHERE m.name = "mpadmin2fa" ORDER BY h.name');
$shape['configuration_keys'] = $read('SELECT name FROM ' . $prefix . 'configuration WHERE name LIKE "MP2FA_%" ORDER BY name');
$shape['roles'] = $read('SELECT slug FROM ' . $prefix . 'authorization_role'
    . ' WHERE slug LIKE "ROLE_MOD_TAB_ADMINMPADMIN2FA%" OR slug LIKE "ROLE_MOD_MODULE_MPADMIN2FA_%" ORDER BY slug');
$shape['access'] = $read('SELECT DISTINCT (a.id_profile = ' . (int) _PS_ADMIN_PROFILE_ . ') AS superadmin, r.slug'
    . ' FROM ' . $prefix . 'access a INNER JOIN ' . $prefix . 'authorization_role r ON r.id_authorization_role = a.id_authorization_role'
    . ' WHERE r.slug LIKE "ROLE_MOD_TAB_ADMINMPADMIN2FA%" ORDER BY superadmin, r.slug');

if ('snapshot' === ($argv[1] ?? '')) {
    file_put_contents($path, json_encode($shape));
    chmod($path, 0600);
    echo 'PASS: recorded the clean-install structure.' . PHP_EOL;
} elseif ('verify' === ($argv[1] ?? '')) {
    $expected = json_decode(file_get_contents($path), true);
    foreach ($shape as $label => $actual) {
        if (!isset($expected[$label]) || $expected[$label] !== $actual) {
            throw new RuntimeException('Upgraded installation differs from clean installation: ' . $label);
        }
    }
    echo 'PASS: all ' . count($shape) . ' upgraded structure checks match clean installation.' . PHP_EOL;
} else {
    throw new InvalidArgumentException('Unknown structure verification phase.');
}
