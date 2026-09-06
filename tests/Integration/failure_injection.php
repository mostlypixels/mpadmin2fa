<?php

declare(strict_types=1);

$root = getenv('MP2FA_PS_ROOT');
$runtime = getenv('MP2FA_LIFECYCLE_RUNTIME');
if ('1' !== getenv('MP2FA_INTEGRATION') || !$root || !$runtime || 'cli' !== PHP_SAPI) {
    throw new RuntimeException('Failure injection requires the disposable package lifecycle harness.');
}
$installed = realpath($root . '/modules/mpadmin2fa');
if (!$installed || $installed === realpath(dirname(__DIR__, 2)) || !is_file($installed . '/SHA256SUMS')) {
    throw new RuntimeException('Refusing to instrument a source checkout or an unpackaged module.');
}
require_once $root . '/config/config.inc.php';
$entrypoint = $installed . '/mpadmin2fa.php';
$backup = $runtime . '/entrypoint.php';
$snapshot = static function (): array {
    $result = [];
    foreach ([
        'access', 'authorization_role', 'employee', 'employee_shop', 'hook', 'hook_module',
        'hook_module_exceptions', 'module', 'module_access', 'module_country', 'module_currency',
        'module_group', 'module_shop', 'profile', 'profile_lang', 'tab', 'tab_lang',
    ] as $table) {
        $rows = Db::getInstance()->executeS('SELECT * FROM ' . _DB_PREFIX_ . $table, true, false);
        if (false === $rows) {
            throw new RuntimeException('Snapshot failed for ' . $table);
        }
        $rows = array_map('json_encode', $rows);
        sort($rows);
        $result[$table] = hash('sha256', implode("\n", $rows));
    }
    $rows = Db::getInstance()->executeS('SELECT * FROM ' . _DB_PREFIX_ . 'configuration WHERE name LIKE "MP2FA_%"', true, false);
    $result['configuration'] = hash('sha256', json_encode($rows));
    $result['schema'] = (int) Db::getInstance()->getValue(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
        . ' AND LEFT(table_name, ' . strlen(_DB_PREFIX_ . 'mp2fa_') . ') = "' . pSQL(_DB_PREFIX_ . 'mp2fa_') . '"',
        false
    );

    return $result;
};
$action = $argv[1] ?? '';
if ('prepare' === $action) {
    $stage = $argv[2] ?? '';
    $markers = [
        'before-parent' => '            $parentAttempted = true;',
        'after-schema' => '            if (!$this->installConfiguration()) {',
        'after-configuration' => '            if (!$this->registerRequiredHooks()) {',
        'after-hooks' => '            if (!$this->reconcileAdminTabs()) {',
        'after-tabs' => '            return true;',
    ];
    if (!isset($markers[$stage]) || is_file($backup) || Module::isInstalled('mpadmin2fa')) {
        throw new RuntimeException('Invalid failure-injection starting state.');
    }
    $source = file_get_contents($entrypoint);
    $start = strpos($source, '    public function install(): bool');
    $end = strpos($source, '    public function postInstall(): bool', $start);
    $body = substr($source, $start, $end - $start);
    if (1 !== substr_count($body, $markers[$stage])) {
        throw new RuntimeException('Installation stages changed; update the injection harness.');
    }
    file_put_contents($backup, $source);
    chmod($backup, 0600);
    file_put_contents($runtime . '/before.json', json_encode($snapshot()));
    $body = str_replace($markers[$stage],
        '            throw new RuntimeException("injected mp2fa ' . $stage . ' failure");' . "\n" . $markers[$stage],
        $body
    );
    file_put_contents($entrypoint, substr($source, 0, $start) . $body . substr($source, $end));
} elseif ('restore' === $action || 'verify' === $action) {
    if (is_file($backup)) {
        copy($backup, $entrypoint);
        unlink($backup);
    }
    if ('verify' === $action) {
        $before = json_decode(file_get_contents($runtime . '/before.json'), true);
        $after = $snapshot();
        foreach ($before as $label => $expected) {
            if ($expected !== $after[$label]) {
                throw new RuntimeException('Rollback did not restore pre-install ' . $label . ' state.');
            }
        }
        echo 'PASS: all ' . count($before) . ' pre-install state checks restored.' . PHP_EOL;
    }
} else {
    throw new InvalidArgumentException('Unknown failure-injection command.');
}
