<?php

declare(strict_types=1);

// Run only against an explicitly disposable, installed shop and its scoped package.
$root = getenv('MP2FA_PS_ROOT');
$runtime = getenv('MP2FA_REQUEST_RUNTIME');
if ('1' !== getenv('MP2FA_INTEGRATION') || !$root || !$runtime || 'cli' !== PHP_SAPI) {
    throw new RuntimeException('The HTTPS request harness requires an explicitly disposable shop.');
}
define('_PS_ADMIN_DIR_', $root . '/admin-dev');
require_once $root . '/config/config.inc.php';
require_once $root . '/app/AppKernel.php';
$kernel = new AppKernel('prod', false);
$kernel->boot();
$module = Module::getInstanceByName('mpadmin2fa');
$repository = $kernel->getContainer()->get(Mpadmin2fa\Repository\SecurityRepository::class);
$keys = new Mpadmin2fa\Security\KeyManager(
    $repository,
    new Mpadmin2fa\Security\CookieKeyProvider(),
    new Mpadmin2fa\Security\ProtectedKeyRewrapper()
);
$employeeId = (int) Db::getInstance()->getValue('SELECT id_employee FROM ' . _DB_PREFIX_ . 'employee WHERE email = "demo@prestashop.com"');
if ($employeeId <= 0 || !Module::isInstalled('mpadmin2fa')) {
    throw new RuntimeException('Expected the disposable CI employee and installed package.');
}
$fixtureEmployee = static function (string $email, string $firstname, int $profileId = 1) use ($employeeId, $repository): int {
    $existingId = (int) Db::getInstance()->getValue(
        'SELECT id_employee FROM ' . _DB_PREFIX_ . 'employee WHERE email = "' . pSQL($email) . '"'
    );
    if ($existingId > 0) {
        $repository->resetEmployee($existingId);
        if (!(new Employee($existingId))->delete()) {
            throw new RuntimeException('Could not remove an earlier disposable approval employee.');
        }
    }

    $source = new Employee($employeeId);
    $employee = new Employee();
    $employee->firstname = $firstname;
    $employee->lastname = 'Request Fixture';
    $employee->email = $email;
    $employee->passwd = $source->passwd;
    $employee->last_passwd_gen = $source->last_passwd_gen;
    $employee->bo_theme = $source->bo_theme ?: 'default';
    $employee->default_tab = $source->default_tab ?: (int) Tab::getIdFromClassName('AdminDashboard');
    $employee->active = true;
    $employee->id_profile = $profileId;
    $employee->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
    $employee->bo_menu = true;
    if (!$employee->add()) {
        throw new RuntimeException('Could not create the disposable approval employee.');
    }
    Db::getInstance()->execute(
        'INSERT IGNORE INTO ' . _DB_PREFIX_ . 'employee_shop (id_employee, id_shop) VALUES ('
        . (int) $employee->id . ', ' . (int) Configuration::get('PS_SHOP_DEFAULT') . ')'
    );

    return (int) $employee->id;
};
$bootstrapEmployeeId = $fixtureEmployee('mp2fa-bootstrap@example.test', 'Bootstrap');
$approvalEmployeeId = $fixtureEmployee('mp2fa-approval@example.test', 'Approval');
$delegatedEmployeeId = $fixtureEmployee('mp2fa-delegated@example.test', 'Delegated', 2);
$delegatedTargetEmployeeId = $fixtureEmployee('mp2fa-delegated-target@example.test', 'Delegated Target');
$recoveryCode = 'A1B2C-D3E4F-56789-ABCDE';
$repository->resetEmployee($employeeId);
Configuration::updateValue('PS_SSL_ENABLED', 1);
Configuration::updateValue('PS_SSL_ENABLED_EVERYWHERE', 1);
Configuration::updateValue('PS_COOKIE_CHECKIP', 0);
Configuration::updateValue('MP2FA_STEP_UP_SECONDS', 60);

$client = curl_init();
curl_setopt_array($client, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEFILE => '',
    CURLOPT_COOKIEJAR => $runtime . '/cookies.txt',
    CURLOPT_CAINFO => $runtime . '/server.crt',
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 90,
]);
$count = 0;
$failures = [];
$request = static function (string $path, ?array $post = null, bool $ajax = false, bool $secure = true) use ($client, $runtime): array {
    $headers = [];
    curl_setopt($client, CURLOPT_URL, ($secure ? 'https://localhost:8443' : 'http://localhost:8080') . $path);
    curl_setopt($client, CURLOPT_HTTPHEADER, $ajax ? ['X-Requested-With: XMLHttpRequest'] : []);
    curl_setopt($client, CURLOPT_POST, null !== $post);
    if (null !== $post) {
        curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    curl_setopt($client, CURLOPT_HEADERFUNCTION, static function ($handle, string $line) use (&$headers): int {
        $parts = explode(':', $line, 2);
        if (2 === count($parts)) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return strlen($line);
    });
    $body = curl_exec($client);
    if (false === $body) {
        throw new RuntimeException('HTTPS request failed: ' . curl_error($client));
    }
    $status = (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE);
    file_put_contents($runtime . '/last-response.html', $body);
    if ($status >= 500 && preg_match('/<title>(.*?)<\/title>/s', $body, $match)) {
        // Only the error title: never dump cookies, form tokens, or complete debug pages.
        echo 'HTTP ' . $status . ' response: ' . html_entity_decode(strip_tags($match[1]), ENT_QUOTES) . PHP_EOL;
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $body];
};
$check = static function (bool $condition, string $label) use (&$count, &$failures): void {
    ++$count;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
    if (!$condition) {
        $failures[] = $label;
    }
};
$totp = static function (string $totpSecret): array {
    $bits = '';
    foreach (str_split($totpSecret) as $character) {
        $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $character)), 5, '0', STR_PAD_LEFT);
    }
    $binary = '';
    foreach (str_split($bits, 8) as $byte) {
        if (8 === strlen($byte)) {
            $binary .= chr(bindec($byte));
        }
    }
    $counter = (int) floor(time() / 30);
    $hash = hash_hmac('sha1', pack('N2', 0, $counter), $binary, true);
    $offset = ord(substr($hash, -1)) & 15;
    $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;

    return [
        'code' => str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT),
        'counter' => $counter,
    ];
};
$loginEmployee = static function (string $email) use ($request): array {
    return $request('/admin-dev/index.php?controller=AdminLogin', [
        'email' => $email,
        'passwd' => 'Pr3st4Sh0P',
        'submitLogin' => '1',
        'ajax' => '1',
    ], true);
};
$clearBrowserSession = static function () use ($client): void {
    curl_setopt($client, CURLOPT_COOKIELIST, 'ALL');
};
$approvalCount = static function (int $targetEmployeeId): int {
    return (int) Db::getInstance()->getValue(
        'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'mp2fa_approval WHERE id_employee = ' . $targetEmployeeId
    );
};

$login = $loginEmployee('mp2fa-bootstrap@example.test');
$data = json_decode($login['body'], true);
$check(200 === $login['status'] && false === ($data['hasErrors'] ?? null),
    'the first SuperAdmin can sign in before any authenticator exists');
$bootstrapEnrollUrl = '/admin-dev/index.php/modules/mpadmin2fa/enroll?token=' . Tools::getAdminToken($bootstrapEmployeeId);
$response = $request($bootstrapEnrollUrl);
$check(200 === $response['status']
    && false !== strpos($response['body'], 'Set up two-factor authentication')
    && 'pending' === $repository->factor($bootstrapEmployeeId)['status']
    && null === $repository->enrollmentApprovalStatus($bootstrapEmployeeId),
    'the first SuperAdmin can bootstrap enrollment without approval');
$repository->resetEmployee($bootstrapEmployeeId);
$clearBrowserSession();

$secret = (new Mpadmin2fa\Security\TotpService())->generateSecret();
$encrypted = $keys->encrypt($secret);
$repository->savePendingEnrollment($employeeId, $encrypted['ciphertext'], $encrypted['key_version']);
$repository->activateEnrollment(
    $employeeId,
    (int) floor(time() / 30) - 2,
    [password_hash($recoveryCode, PASSWORD_DEFAULT)]
);
$delegatedSecret = (new Mpadmin2fa\Security\TotpService())->generateSecret();
$delegatedEncrypted = $keys->encrypt($delegatedSecret);
$repository->savePendingEnrollment(
    $delegatedEmployeeId,
    $delegatedEncrypted['ciphertext'],
    $delegatedEncrypted['key_version']
);
$repository->activateEnrollment($delegatedEmployeeId, (int) floor(time() / 30) - 2, []);

$login = $loginEmployee('mp2fa-approval@example.test');
$data = json_decode($login['body'], true);
$check(200 === $login['status'] && false === ($data['hasErrors'] ?? null),
    'a second SuperAdmin can sign in to request enrollment approval');
$approvalEnrollUrl = '/admin-dev/index.php/modules/mpadmin2fa/enroll?token=' . Tools::getAdminToken($approvalEmployeeId);
$response = $request($approvalEnrollUrl);
$check(200 === $response['status']
    && false !== strpos($response['body'], 'Waiting for approval')
    && null === $repository->factor($approvalEmployeeId)
    && 'pending' === $repository->enrollmentApprovalStatus($approvalEmployeeId),
    'a second SuperAdmin is held at the approval boundary before a secret is created');
$response = $request($approvalEnrollUrl);
$check(200 === $response['status'] && 1 === $approvalCount($approvalEmployeeId),
    'rechecking a pending enrollment does not create duplicate approval requests');
$clearBrowserSession();

$login = $request('/admin-dev/index.php?controller=AdminLogin', [
    'email' => 'demo@prestashop.com',
    'passwd' => 'Pr3st4Sh0P',
    'submitLogin' => '1',
    'ajax' => '1',
], true);
$data = json_decode($login['body'], true);
$check(200 === $login['status'] && false === ($data['hasErrors'] ?? null), 'real password login succeeds over HTTPS');
if (!empty($failures)) {
    throw new RuntimeException('Password login failed; inspect the private request-test logs.');
}

$modern = '/admin-dev/index.php/modules/mpadmin2fa/settings?token=' . Tools::getAdminToken($employeeId);
$legacy = '/admin-dev/index.php?controller=AdminDashboard&token='
    . Tools::getAdminToken('AdminDashboard' . (int) Tab::getIdFromClassName('AdminDashboard') . $employeeId);
foreach (['modern' => $modern, 'legacy' => $legacy] as $kind => $url) {
    $response = $request($url);
    $check(302 === $response['status'] && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge'),
        $kind . ' admin read is blocked until MFA verification (HTTP ' . $response['status'] . ')');
    $response = $request($url, null, true);
    $check(403 === $response['status'] && false !== strpos($response['headers']['x-mpadmin2fa-redirect'] ?? '', '/mpadmin2fa/challenge'),
        $kind . ' AJAX request is blocked until MFA verification (HTTP ' . $response['status'] . ')');
}
$challengeUrl = '/admin-dev/index.php/modules/mpadmin2fa/challenge?token=' . Tools::getAdminToken($employeeId);
$enrollUrl = '/admin-dev/index.php/modules/mpadmin2fa/enroll?token=' . Tools::getAdminToken($employeeId);
$response = $request($challengeUrl);
$check(200 === $response['status'], 'the actual challenge form renders (HTTP ' . $response['status'] . ')');
$document = new DOMDocument();
@$document->loadHTML($response['body']);
$xpath = new DOMXPath($document);
$csrf = $xpath->evaluate('string(//input[@name="one_time_code[_token]"]/@value)');
$check('' !== $csrf, 'the challenge supplies a real CSRF token');
if ('' === $csrf) {
    throw new RuntimeException('Challenge rendering failed; inspect the private request-test logs.');
}
// Independent RFC 6238 fixture; do not call the module verifier to produce its input.
$authenticatorCode = $totp($secret);
$counter = $authenticatorCode['counter'];
$code = $authenticatorCode['code'];
$response = $request($challengeUrl, ['one_time_code' => ['code' => $code, '_token' => 'invalid', 'submit' => '']]);
$check(200 === $response['status'] && (int) $repository->factor($employeeId)['last_counter'] < $counter,
    'invalid form CSRF cannot consume a valid authenticator code');
$response = $request($challengeUrl, ['one_time_code' => ['code' => $code, '_token' => $csrf, 'submit' => '']]);
$check(302 === $response['status'] && false !== strpos($response['headers']['location'] ?? '', 'AdminDashboard')
    && (int) $repository->factor($employeeId)['last_counter'] === $counter,
    'valid authenticator code completes the challenge and redirects to the native dashboard');
foreach (['modern' => $modern, 'legacy' => $legacy] as $kind => $url) {
    $response = $request($url);
    $check(200 === $response['status'], $kind . ' admin read succeeds after MFA (HTTP ' . $response['status'] . ')');
}
$response = $request($enrollUrl);
$check(302 === $response['status']
    && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/authenticator')
    && 'active' === $repository->factor($employeeId)['status'],
    'an active factor cannot bypass replacement confirmation through direct enrollment');
$securityPolicy = '/admin-dev/index.php/modules/mpadmin2fa/security?token=' . Tools::getAdminToken($employeeId);
$legacySensitive = '/admin-dev/index.php?controller=AdminModules&token='
    . Tools::getAdminToken('AdminModules' . (int) Tab::getIdFromClassName('AdminModules') . $employeeId)
    . '&action=install&module_name=mp2fa_missing_request_fixture';
$response = $request($securityPolicy, []);
$check(200 === $response['status'] && false === strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge'),
    'fresh MFA admits a modern sensitive POST before its invalid form can mutate policy');
$response = $request($legacySensitive);
$check($response['status'] < 500 && false === strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge'),
    'fresh MFA admits a legacy sensitive action using a deliberately missing module');

$approvalsUrl = '/admin-dev/index.php/modules/mpadmin2fa/enrollment/pending-approvals?token='
    . Tools::getAdminToken($employeeId);
$response = $request($approvalsUrl);
$document = new DOMDocument();
@$document->loadHTML($response['body']);
$xpath = new DOMXPath($document);
$approvalActionUrl = $xpath->evaluate(
    'string(//a[contains(@data-url, "/employees/' . $approvalEmployeeId . '/approve")]/@data-url)'
);
$check(200 === $response['status']
    && '' !== $approvalActionUrl
    && false !== strpos($response['body'], 'mp2fa-approval@example.test'),
    'a verified SuperAdmin with native read and update access sees the pending approval action');
if ('' === $approvalActionUrl) {
    throw new RuntimeException('Approval action rendering failed; inspect the private request-test logs.');
}

echo 'Waiting for the configured step-up window to expire.' . PHP_EOL;
sleep(61);
foreach (['modern' => [$securityPolicy, []], 'legacy' => [$legacySensitive, null]] as $kind => $sensitiveRequest) {
    $response = $request($sensitiveRequest[0], $sensitiveRequest[1]);
    $check(302 === $response['status']
        && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge')
        && false !== strpos($response['headers']['location'] ?? '', 'step_up=1'),
        'expired MFA blocks the ' . $kind . ' sensitive action with a step-up challenge');
}
$response = $request($approvalActionUrl, []);
$check(302 === $response['status']
    && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge')
    && false !== strpos($response['headers']['location'] ?? '', 'step_up=1')
    && 'pending' === $repository->enrollmentApprovalStatus($approvalEmployeeId),
    'expired MFA cannot approve enrollment and requires a fresh step-up');
$response = $request($securityPolicy, [], true);
$check(403 === $response['status']
    && false !== strpos($response['headers']['x-mpadmin2fa-redirect'] ?? '', '/mpadmin2fa/challenge')
    && false !== strpos($response['headers']['x-mpadmin2fa-redirect'] ?? '', 'step_up=1'),
    'expired MFA blocks an AJAX sensitive mutation with the step-up response contract');
$stepUpUrl = $challengeUrl . '&step_up=1';
$response = $request($stepUpUrl);
$document = new DOMDocument();
@$document->loadHTML($response['body']);
$xpath = new DOMXPath($document);
$stepUpCsrf = $xpath->evaluate('string(//input[@name="one_time_code[_token]"]/@value)');
$check(200 === $response['status'] && '' !== $stepUpCsrf, 'the expired-session step-up form renders with CSRF protection');
$stepUpAuthenticator = $totp($secret);
$response = $request($stepUpUrl, ['one_time_code' => [
    'code' => $stepUpAuthenticator['code'], '_token' => $stepUpCsrf, 'submit' => '',
]]);
$check(302 === $response['status']
    && false === strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge')
    && (int) $repository->factor($employeeId)['last_counter'] === $stepUpAuthenticator['counter'],
    'a new authenticator code completes expired-session step-up and returns to the admin');
foreach (['modern' => [$securityPolicy, []], 'legacy' => [$legacySensitive, null]] as $kind => $sensitiveRequest) {
    $response = $request($sensitiveRequest[0], $sensitiveRequest[1]);
    $check($response['status'] < 500
        && false === strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge'),
        'fresh step-up admits the ' . $kind . ' sensitive action without performing a real mutation');
}
$response = $request($approvalActionUrl, ['mp2fa_csrf_token' => 'invalid']);
$check(302 === $response['status']
    && 'pending' === $repository->enrollmentApprovalStatus($approvalEmployeeId),
    'invalid CSRF cannot approve a pending enrollment');

$access = new Access();
$superAdminProfileId = defined('_PS_ADMIN_PROFILE_') ? (int) _PS_ADMIN_PROFILE_ : 1;
$enrollmentTabId = (int) Tab::getIdFromClassName('AdminMpAdmin2faEnrollment');
$deniedAuditBefore = (int) Db::getInstance()->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'mp2fa_audit'
    . ' WHERE event = "enrollment.approval_denied"'
    . ' AND metadata_json LIKE "%Native update permission is required%"'
);
if ('ok' !== $access->updateLgcAccess($superAdminProfileId, $enrollmentTabId, 'edit', false, false)) {
    throw new RuntimeException('Could not remove the disposable native update permission.');
}
try {
    $response = $request($approvalActionUrl, []);
} finally {
    if ('ok' !== $access->updateLgcAccess($superAdminProfileId, $enrollmentTabId, 'edit', true, false)) {
        throw new RuntimeException('Could not restore the native update permission.');
    }
}
$deniedAuditAfter = (int) Db::getInstance()->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'mp2fa_audit'
    . ' WHERE event = "enrollment.approval_denied"'
    . ' AND metadata_json LIKE "%Native update permission is required%"'
);
$check(302 === $response['status']
    && 'pending' === $repository->enrollmentApprovalStatus($approvalEmployeeId)
    && $deniedAuditAfter > $deniedAuditBefore,
    'a SuperAdmin without native update permission cannot approve and the denial is audited');

$approvalAuditBefore = (int) Db::getInstance()->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'mp2fa_audit WHERE event = "enrollment.approval_denied"'
);
if ('ok' !== $access->updateLgcAccess($superAdminProfileId, $enrollmentTabId, 'view', false, false)) {
    throw new RuntimeException('Could not remove the disposable native read permission.');
}
try {
    $response = $request($approvalActionUrl, []);
} finally {
    if ('ok' !== $access->updateLgcAccess($superAdminProfileId, $enrollmentTabId, 'view', true, false)) {
        throw new RuntimeException('Could not restore the native read permission.');
    }
}
$approvalAuditAfter = (int) Db::getInstance()->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'mp2fa_audit WHERE event = "enrollment.approval_denied"'
);
$check(302 === $response['status']
    && 'pending' === $repository->enrollmentApprovalStatus($approvalEmployeeId)
    && $approvalAuditAfter === $approvalAuditBefore,
    'native read denial blocks approval before the module controller runs');

$response = $request($approvalActionUrl, []);
$approval = Db::getInstance()->getRow(
    'SELECT status, approved_by FROM ' . _DB_PREFIX_ . 'mp2fa_approval'
    . ' WHERE id_employee = ' . $approvalEmployeeId . ' ORDER BY id_approval DESC'
);
$check(302 === $response['status']
    && 'approved' === ($approval['status'] ?? null)
    && $employeeId === (int) ($approval['approved_by'] ?? 0),
    'a freshly verified SuperAdmin with native read and update permission approves the request');

$response = $request($challengeUrl, ['one_time_code' => ['code' => $code, '_token' => $csrf, 'submit' => '']]);
$check(200 === $response['status'] && false !== strpos($response['body'], 'already been used'),
    'reusing the same authenticator code is rejected');
$login = $request('/admin-dev/index.php?controller=AdminLogin', [
    'email' => 'demo@prestashop.com', 'passwd' => 'Pr3st4Sh0P', 'submitLogin' => '1', 'ajax' => '1',
], true);
$data = json_decode($login['body'], true);
$check(false === ($data['hasErrors'] ?? null), 'a fresh password login succeeds');
foreach (['modern' => $modern, 'legacy' => $legacy] as $kind => $url) {
    $response = $request($url);
    $check(302 === $response['status'] && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge'),
        $kind . ' does not reuse MFA verification from the previous login');
}
$response = $request('/admin-dev/index.php?controller=AdminLogin&logout=1');
$check(false === strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge') && $response['status'] < 400,
    'native logout remains available before MFA');
curl_setopt($client, CURLOPT_COOKIELIST, 'ALL');
$response = $request('/admin-dev/index.php?controller=AdminLogin', [
    'email' => 'demo@prestashop.com', 'passwd' => 'Pr3st4Sh0P', 'submitLogin' => '1', 'ajax' => '1',
], true, false);
$data = json_decode($response['body'], true);
$check(403 === $response['status'] && true === ($data['hasErrors'] ?? null),
    'insecure AJAX password login is rejected without a missing-route error');
$response = $request($legacy);
$check(302 === $response['status'] && false !== strpos($response['headers']['location'] ?? '', 'AdminLogin'),
    'rejected insecure login leaves no authenticated native session');

curl_setopt($client, CURLOPT_COOKIELIST, 'ALL');
$login = $request('/admin-dev/index.php?controller=AdminLogin', [
    'email' => 'demo@prestashop.com', 'passwd' => 'Pr3st4Sh0P', 'submitLogin' => '1', 'ajax' => '1',
], true);
$data = json_decode($login['body'], true);
$check(200 === $login['status'] && false === ($data['hasErrors'] ?? null),
    'a secure password login starts the recovery scenario');
$response = $request($challengeUrl);
$document = new DOMDocument();
@$document->loadHTML($response['body']);
$xpath = new DOMXPath($document);
$recoveryCsrf = $xpath->evaluate('string(//input[@name="recovery_code_challenge[_token]"]/@value)');
$check(200 === $response['status'] && '' !== $recoveryCsrf,
    'the real recovery-code challenge renders with CSRF protection');
$response = $request($challengeUrl, ['recovery_code_challenge' => [
    'recovery_code' => $recoveryCode, '_token' => 'invalid', 'submit' => '',
]]);
$unusedRecoveryCodes = (int) Db::getInstance()->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'mp2fa_recovery_code'
    . ' WHERE id_employee = ' . $employeeId . ' AND used_at IS NOT NULL'
);
$check(200 === $response['status'] && 0 === $unusedRecoveryCodes,
    'invalid recovery-form CSRF cannot consume a valid backup code');
$response = $request($challengeUrl, ['recovery_code_challenge' => [
    'recovery_code' => $recoveryCode, '_token' => $recoveryCsrf, 'submit' => '',
]]);
$usedRecoveryCodes = (int) Db::getInstance()->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'mp2fa_recovery_code'
    . ' WHERE id_employee = ' . $employeeId . ' AND used_at IS NOT NULL'
);
$check(302 === $response['status']
    && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/enroll')
    && 1 === $usedRecoveryCodes,
    'a valid recovery code is consumed once and redirects immediately to replacement enrollment');
foreach (['modern' => $modern, 'legacy' => $legacy] as $kind => $url) {
    $response = $request($url);
    $check(302 === $response['status'] && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/enroll'),
        'the recovery-restricted session blocks ' . $kind . ' admin access');
}
$response = $request($challengeUrl);
$check(302 === $response['status'] && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/enroll'),
    'the recovery-restricted session cannot return to the ordinary challenge');
$replaceUrl = '/admin-dev/index.php/modules/mpadmin2fa/factor/replace?token=' . Tools::getAdminToken($employeeId);
$response = $request($replaceUrl, []);
$check(302 === $response['status'] && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/enroll'),
    'the recovery-restricted session cannot bypass enrollment through the normal replacement action');
$response = $request($enrollUrl);
$document = new DOMDocument();
@$document->loadHTML($response['body']);
$xpath = new DOMXPath($document);
$replacementSecret = trim($xpath->evaluate('string(//p[strong[contains(., "Manual setup key")]]/code)'));
$enrollmentCsrf = $xpath->evaluate('string(//input[@name="one_time_code[_token]"]/@value)');
$check(200 === $response['status']
    && '' !== $replacementSecret
    && '' !== $enrollmentCsrf
    && 'pending' === $repository->factor($employeeId)['status'],
    'only replacement enrollment is available and it creates a pending factor');
foreach (['modern' => $modern, 'legacy' => $legacy] as $kind => $url) {
    $response = $request($url);
    $check(302 === $response['status'] && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/enroll'),
        $kind . ' admin access remains blocked while replacement is pending');
}
$replacementAuthenticator = $totp($replacementSecret);
$response = $request($enrollUrl, ['one_time_code' => [
    'code' => $replacementAuthenticator['code'], '_token' => 'invalid', 'submit' => '',
]]);
$check(200 === $response['status'] && 'pending' === $repository->factor($employeeId)['status'],
    'invalid enrollment CSRF cannot activate the replacement factor');
$response = $request($enrollUrl, ['one_time_code' => [
    'code' => $replacementAuthenticator['code'], '_token' => $enrollmentCsrf, 'submit' => '',
]]);
$check(302 === $response['status']
    && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/recovery-codes')
    && 'active' === $repository->factor($employeeId)['status'],
    'valid CSRF and a code from the new secret activate the replacement factor');
foreach (['modern' => $modern, 'legacy' => $legacy] as $kind => $url) {
    $response = $request($url);
    $check(200 === $response['status'],
        $kind . ' admin access resumes only after replacement activation');
}
$recoveryCodesUrl = '/admin-dev/index.php/modules/mpadmin2fa/recovery-codes?token=' . Tools::getAdminToken($employeeId);
$response = $request($recoveryCodesUrl);
preg_match_all('/[A-F0-9]{5}(?:-[A-F0-9]{5}){3}/', $response['body'], $newRecoveryCodes);
$document = new DOMDocument();
@$document->loadHTML($response['body']);
$xpath = new DOMXPath($document);
$acknowledgementCsrf = $xpath->evaluate('string(//input[@name="recovery_code_acknowledgement[_token]"]/@value)');
$check(200 === $response['status'] && 10 === count(array_unique($newRecoveryCodes[0])) && '' !== $acknowledgementCsrf,
    'replacement recovery codes are shown once with acknowledgement CSRF protection');
$response = $request($recoveryCodesUrl, ['recovery_code_acknowledgement' => [
    'saved' => '1', '_token' => $acknowledgementCsrf, 'submit' => '',
]]);
$check(302 === $response['status'] && false !== strpos($response['headers']['location'] ?? '', 'AdminDashboard'),
    'acknowledging the replacement recovery codes returns to the native dashboard');
$response = $request($recoveryCodesUrl);
$check(302 === $response['status'] && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/authenticator'),
    'acknowledged recovery codes cannot be displayed a second time');

$clearBrowserSession();
$login = $loginEmployee('mp2fa-approval@example.test');
$data = json_decode($login['body'], true);
$check(200 === $login['status'] && false === ($data['hasErrors'] ?? null),
    'the approved SuperAdmin can start a new authenticated session');
$response = $request($approvalEnrollUrl);
$check(200 === $response['status']
    && false !== strpos($response['body'], 'Set up two-factor authentication')
    && 'pending' === $repository->factor($approvalEmployeeId)['status']
    && 'approved' === $repository->enrollmentApprovalStatus($approvalEmployeeId),
    'an approved SuperAdmin can proceed to secret creation and enrollment');

$repository->requestEnrollmentApproval($delegatedTargetEmployeeId);
if ('ok' !== $access->updateLgcAccess(2, $enrollmentTabId, 'view', true, false)
    || 'ok' !== $access->updateLgcAccess(2, $enrollmentTabId, 'edit', true, false)
) {
    throw new RuntimeException('Could not grant the delegated profile its disposable native permissions.');
}
try {
    $clearBrowserSession();
    $login = $loginEmployee('mp2fa-delegated@example.test');
    $data = json_decode($login['body'], true);
    $check(200 === $login['status'] && false === ($data['hasErrors'] ?? null),
        'a delegated profile with native read and update permission can sign in');

    $delegatedChallengeUrl = '/admin-dev/index.php/modules/mpadmin2fa/challenge?token='
        . Tools::getAdminToken($delegatedEmployeeId);
    $response = $request($delegatedChallengeUrl);
    $document = new DOMDocument();
    @$document->loadHTML($response['body']);
    $xpath = new DOMXPath($document);
    $delegatedChallengeCsrf = $xpath->evaluate('string(//input[@name="one_time_code[_token]"]/@value)');
    $check(200 === $response['status'] && '' !== $delegatedChallengeCsrf,
        'the delegated actor must verify its active authenticator before approval');
    if ('' === $delegatedChallengeCsrf) {
        throw new RuntimeException('Delegated challenge rendering failed; inspect the private request-test logs.');
    }
    $delegatedAuthenticator = $totp($delegatedSecret);
    $response = $request($delegatedChallengeUrl, ['one_time_code' => [
        'code' => $delegatedAuthenticator['code'],
        '_token' => $delegatedChallengeCsrf,
        'submit' => '',
    ]]);
    $check(302 === $response['status']
        && (int) $repository->factor($delegatedEmployeeId)['last_counter'] === $delegatedAuthenticator['counter'],
        'the delegated actor completes MFA before attempting approval');

    $delegatedApprovalsUrl = '/admin-dev/index.php/modules/mpadmin2fa/enrollment/pending-approvals?token='
        . Tools::getAdminToken($delegatedEmployeeId);
    $response = $request($delegatedApprovalsUrl);
    $document = new DOMDocument();
    @$document->loadHTML($response['body']);
    $xpath = new DOMXPath($document);
    $delegatedApprovalActionUrl = $xpath->evaluate(
        'string(//a[contains(@data-url, "/employees/' . $delegatedTargetEmployeeId . '/approve")]/@data-url)'
    );
    $check(200 === $response['status'] && '' !== $delegatedApprovalActionUrl,
        'native read and update permission exposes the approval action to a delegated profile');
    if ('' === $delegatedApprovalActionUrl) {
        throw new RuntimeException('Delegated approval action rendering failed; inspect the private request-test logs.');
    }

    $delegatedDenialsBefore = (int) Db::getInstance()->getValue(
        'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'mp2fa_audit'
        . ' WHERE event = "enrollment.approval_denied"'
        . ' AND metadata_json LIKE "%Only a SuperAdmin can approve%"'
    );
    $response = $request($delegatedApprovalActionUrl, []);
    $delegatedDenialsAfter = (int) Db::getInstance()->getValue(
        'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'mp2fa_audit'
        . ' WHERE event = "enrollment.approval_denied"'
        . ' AND metadata_json LIKE "%Only a SuperAdmin can approve%"'
    );
    $check(302 === $response['status']
        && 'pending' === $repository->enrollmentApprovalStatus($delegatedTargetEmployeeId)
        && $delegatedDenialsAfter > $delegatedDenialsBefore,
        'native permissions cannot elevate a delegated profile to SuperAdmin approval authority');
} finally {
    $access->updateLgcAccess(2, $enrollmentTabId, 'edit', false, false);
    $access->updateLgcAccess(2, $enrollmentTabId, 'view', false, false);
}

curl_close($client);
if ($failures) {
    throw new RuntimeException(count($failures) . ' live-request checks failed out of ' . $count . '.');
}
echo 'Live-request checks passed: ' . $count . PHP_EOL;
