<?php

declare(strict_types=1);

// Run only against an explicitly disposable, installed PS9 shop and its scoped package.
$root = getenv('MP2FA_PS_ROOT');
$runtime = getenv('MP2FA_REQUEST_RUNTIME');
$testEmail = getenv('MP2FA_TEST_EMAIL');
$testPassword = getenv('MP2FA_TEST_PASSWORD');
if ('1' !== getenv('MP2FA_INTEGRATION') || !$root || !$runtime || !$testEmail || !$testPassword || 'cli' !== PHP_SAPI) {
    throw new RuntimeException('The HTTPS request harness requires an explicitly disposable shop.');
}

define('_PS_ADMIN_DIR_', $root . '/admin-dev');
require_once $root . '/config/config.inc.php';
require_once $root . '/app/AppKernel.php';
require_once $root . '/app/AdminKernel.php';
if (!defined('_PS_APP_ID_')) {
    define('_PS_APP_ID_', AdminKernel::APP_ID);
}
$kernel = new AdminKernel('prod', false);
$kernel->boot();
Module::getInstanceByName('mpadmin2fa');

$repository = $kernel->getContainer()->get(Mpadmin2fa\Repository\SecurityRepository::class);
$keys = new Mpadmin2fa\Security\KeyManager(
    $repository,
    new Mpadmin2fa\Security\CookieKeyProvider()
);
$employeeId = (int) Db::getInstance()->getValue(
    'SELECT id_employee FROM ' . _DB_PREFIX_ . 'employee WHERE email = "' . pSQL($testEmail) . '"'
);
if ($employeeId <= 0 || !Module::isInstalled('mpadmin2fa')) {
    throw new RuntimeException('Expected the disposable CI employee and installed package.');
}

$repository->resetEmployee($employeeId);
Configuration::updateValue('PS_SSL_ENABLED', 1);
Configuration::updateValue('PS_SSL_ENABLED_EVERYWHERE', 1);
Configuration::updateValue('PS_COOKIE_CHECKIP', 0);

$secret = (new Mpadmin2fa\Security\TotpService())->generateSecret();
$encrypted = $keys->encrypt($secret);
$repository->savePendingEnrollment($employeeId, $encrypted['ciphertext'], $encrypted['key_version']);
$recoveryCode = 'A1B2C-D3E4F-56789-ABCDE';
$repository->activateEnrollment(
    $employeeId,
    (int) floor(time() / 30) - 2,
    [password_hash($recoveryCode, PASSWORD_DEFAULT)]
);

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
        throw new RuntimeException('Request failed: ' . curl_error($client));
    }
    $status = (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE);
    file_put_contents($runtime . '/last-response.html', $body);
    if ($status >= 500 && preg_match('/<title>(.*?)<\/title>/s', $body, $match)) {
        // Never dump cookies, tokens, secrets, or complete debug pages into CI output.
        echo 'HTTP ' . $status . ' response: '
            . html_entity_decode(strip_tags($match[1]), ENT_QUOTES) . PHP_EOL;
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
        $position = strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $character);
        if (false === $position) {
            throw new RuntimeException('The generated TOTP secret is not valid Base32.');
        }
        $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
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
$login = static function (bool $secure = true) use ($request, $testEmail, $testPassword): array {
    return $request('/admin-dev/login', [
        'email' => $testEmail,
        'passwd' => $testPassword,
    ], false, $secure);
};

$response = $login();
$check(302 === $response['status'], 'real password login succeeds over HTTPS');
if (!empty($failures)) {
    throw new RuntimeException('Password login failed; inspect the private request-test logs.');
}

$modern = '/admin-dev/index.php/modules/mpadmin2fa/settings?token=' . Tools::getAdminToken($employeeId);
$legacy = '/admin-dev/index.php?controller=AdminDashboard&token='
    . Tools::getAdminToken('AdminDashboard' . (int) Tab::getIdFromClassName('AdminDashboard') . $employeeId);
foreach (['modern' => $modern, 'legacy' => $legacy] as $kind => $url) {
    $response = $request($url);
    $check(302 === $response['status'] && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge'),
        $kind . ' admin read is blocked until MFA verification');
    $response = $request($url, null, true);
    $check(403 === $response['status'] && false !== strpos($response['headers']['x-mpadmin2fa-redirect'] ?? '', '/mpadmin2fa/challenge'),
        $kind . ' AJAX request is blocked until MFA verification');
}

$challengeUrl = '/admin-dev/index.php/modules/mpadmin2fa/challenge?token=' . Tools::getAdminToken($employeeId);
$response = $request($challengeUrl);
$check(200 === $response['status'], 'the actual challenge form renders');
$document = new DOMDocument();
@$document->loadHTML($response['body']);
$xpath = new DOMXPath($document);
$csrf = $xpath->evaluate('string(//input[@name="one_time_code[_token]"]/@value)');
$check('' !== $csrf, 'the challenge supplies a real CSRF token');
if ('' === $csrf) {
    throw new RuntimeException('Challenge rendering failed; inspect the private request-test logs.');
}

// Independent RFC 6238 fixture; do not use the module verifier to produce its input.
$authenticator = $totp($secret);
$response = $request($challengeUrl, ['one_time_code' => [
    'code' => $authenticator['code'],
    '_token' => 'invalid',
    'submit' => '',
]]);
$check(200 === $response['status']
    && (int) $repository->factor($employeeId)['last_counter'] < $authenticator['counter'],
    'invalid form CSRF cannot consume a valid authenticator code');
$response = $request($challengeUrl, ['one_time_code' => [
    'code' => $authenticator['code'],
    '_token' => $csrf,
    'submit' => '',
]]);
$check(302 === $response['status']
    && false === strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge')
    && (int) $repository->factor($employeeId)['last_counter'] === $authenticator['counter'],
    'valid authenticator code completes the challenge and returns to the admin');
foreach (['modern' => $modern, 'legacy' => $legacy] as $kind => $url) {
    $response = $request($url);
    $check(200 === $response['status'], $kind . ' admin read succeeds after MFA');
}

$enrollUrl = '/admin-dev/index.php/modules/mpadmin2fa/enroll?token=' . Tools::getAdminToken($employeeId);
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
$check(200 === $response['status']
    && false === strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge'),
    'fresh MFA admits a modern sensitive POST before its invalid form can mutate policy');
$response = $request($legacySensitive);
$check($response['status'] < 500
    && false === strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge'),
    'fresh MFA admits a legacy sensitive action using a deliberately missing module');

Configuration::updateValue('MP2FA_STEP_UP_SECONDS', 60);
echo 'Waiting for the configured step-up window to expire.' . PHP_EOL;
sleep(61);
foreach (['modern' => [$securityPolicy, []], 'legacy' => [$legacySensitive, null]] as $kind => $sensitiveRequest) {
    $response = $request($sensitiveRequest[0], $sensitiveRequest[1]);
    $check(302 === $response['status']
        && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge')
        && false !== strpos($response['headers']['location'] ?? '', 'step_up=1'),
        'expired MFA blocks the ' . $kind . ' sensitive action with a step-up challenge');
}
$response = $request($securityPolicy, [], true);
$check(403 === $response['status']
    && false !== strpos($response['headers']['x-mpadmin2fa-redirect'] ?? '', '/mpadmin2fa/challenge')
    && false !== strpos($response['headers']['x-mpadmin2fa-redirect'] ?? '', 'step_up=1'),
    'expired MFA blocks an AJAX-sensitive mutation with the step-up response contract');

$stepUpUrl = $challengeUrl . '&step_up=1';
$response = $request($stepUpUrl);
$document = new DOMDocument();
@$document->loadHTML($response['body']);
$xpath = new DOMXPath($document);
$stepUpCsrf = $xpath->evaluate('string(//input[@name="one_time_code[_token]"]/@value)');
$check(200 === $response['status'] && '' !== $stepUpCsrf,
    'the expired-session step-up form renders with CSRF protection');
$stepUpAuthenticator = $totp($secret);
$response = $request($stepUpUrl, ['one_time_code' => [
    'code' => $stepUpAuthenticator['code'],
    '_token' => $stepUpCsrf,
    'submit' => '',
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

$response = $request($challengeUrl, ['one_time_code' => [
    'code' => $authenticator['code'],
    '_token' => $csrf,
    'submit' => '',
]]);
$check(200 === $response['status'] && false !== strpos($response['body'], 'already been used'),
    'reusing the same authenticator code is rejected');

$response = $login();
$check(302 === $response['status'], 'a fresh password login succeeds');
foreach (['modern' => $modern, 'legacy' => $legacy] as $kind => $url) {
    $response = $request($url);
    $check(302 === $response['status'] && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge'),
        $kind . ' does not reuse MFA verification from the previous login');
}
$response = $request('/admin-dev/logout');
$check($response['status'] < 400
    && false === strpos($response['headers']['location'] ?? '', '/mpadmin2fa/challenge'),
    'native logout remains available before MFA');

curl_setopt($client, CURLOPT_COOKIELIST, 'ALL');
$response = $login(false);
$check(302 === $response['status']
    && false !== strpos($response['headers']['location'] ?? '', '/login'),
    'insecure password login is rejected by the MFA HTTPS guard');
$response = $request($legacy);
$check(302 === $response['status']
    && false !== strpos($response['headers']['location'] ?? '', '/login'),
    'rejected insecure login leaves no authenticated native session');


curl_setopt($client, CURLOPT_COOKIELIST, 'ALL');
$response = $login();
$check(302 === $response['status'], 'a secure password login starts the recovery scenario');
$response = $request($challengeUrl);
$document = new DOMDocument();
@$document->loadHTML($response['body']);
$xpath = new DOMXPath($document);
$recoveryCsrf = $xpath->evaluate('string(//input[@name="recovery_code_challenge[_token]"]/@value)');
$check(200 === $response['status'] && '' !== $recoveryCsrf,
    'the real recovery-code challenge renders with CSRF protection');
$response = $request($challengeUrl, ['recovery_code_challenge' => [
    'recovery_code' => $recoveryCode,
    '_token' => 'invalid',
    'submit' => '',
]]);
$usedRecoveryCodes = (int) Db::getInstance()->getValue(
    'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'mp2fa_recovery_code'
    . ' WHERE id_employee = ' . $employeeId . ' AND used_at IS NOT NULL'
);
$check(200 === $response['status'] && 0 === $usedRecoveryCodes,
    'invalid recovery-form CSRF cannot consume a valid backup code');
$response = $request($challengeUrl, ['recovery_code_challenge' => [
    'recovery_code' => $recoveryCode,
    '_token' => $recoveryCsrf,
    'submit' => '',
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
    $check(302 === $response['status']
        && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/enroll'),
        'the recovery-restricted session blocks ' . $kind . ' admin access');
}
$response = $request($challengeUrl);
$check(302 === $response['status']
    && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/enroll'),
    'the recovery-restricted session cannot return to the ordinary challenge');
$replaceUrl = '/admin-dev/index.php/modules/mpadmin2fa/factor/replace?token=' . Tools::getAdminToken($employeeId);
$response = $request($replaceUrl, []);
$check(302 === $response['status']
    && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/enroll'),
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
    $check(302 === $response['status']
        && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/enroll'),
        $kind . ' admin access remains blocked while replacement is pending');
}
$replacementAuthenticator = $totp($replacementSecret);
$response = $request($enrollUrl, ['one_time_code' => [
    'code' => $replacementAuthenticator['code'],
    '_token' => 'invalid',
    'submit' => '',
]]);
$check(200 === $response['status'] && 'pending' === $repository->factor($employeeId)['status'],
    'invalid enrollment CSRF cannot activate the replacement factor');
$response = $request($enrollUrl, ['one_time_code' => [
    'code' => $replacementAuthenticator['code'],
    '_token' => $enrollmentCsrf,
    'submit' => '',
]]);
$check(302 === $response['status']
    && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/recovery-codes')
    && 'active' === $repository->factor($employeeId)['status'],
    'valid CSRF and a code from the new secret activate the replacement factor');
foreach (['modern' => $modern, 'legacy' => $legacy] as $kind => $url) {
    $response = $request($url);
    $check(200 === $response['status'], $kind . ' admin access resumes only after replacement activation');
}
$recoveryCodesUrl = '/admin-dev/index.php/modules/mpadmin2fa/recovery-codes?token=' . Tools::getAdminToken($employeeId);
$response = $request($recoveryCodesUrl);
preg_match_all('/[A-F0-9]{5}(?:-[A-F0-9]{5}){3}/', $response['body'], $newRecoveryCodes);
$document = new DOMDocument();
@$document->loadHTML($response['body']);
$xpath = new DOMXPath($document);
$acknowledgementCsrf = $xpath->evaluate('string(//input[@name="recovery_code_acknowledgement[_token]"]/@value)');
$check(200 === $response['status']
    && 10 === count(array_unique($newRecoveryCodes[0]))
    && '' !== $acknowledgementCsrf,
    'replacement recovery codes are shown once with acknowledgement CSRF protection');
$response = $request($recoveryCodesUrl, ['recovery_code_acknowledgement' => [
    'saved' => '1',
    '_token' => $acknowledgementCsrf,
    'submit' => '',
]]);
$check(302 === $response['status']
    && false === strpos($response['headers']['location'] ?? '', '/mpadmin2fa/recovery-codes'),
    'acknowledging the replacement recovery codes returns to the native admin');
$response = $request($recoveryCodesUrl);
$check(302 === $response['status']
    && false !== strpos($response['headers']['location'] ?? '', '/mpadmin2fa/authenticator'),
    'acknowledged recovery codes cannot be displayed a second time');
curl_close($client);
if ($failures) {
    throw new RuntimeException(count($failures) . ' live-request checks failed out of ' . $count . '.');
}
echo 'Live-request checks passed: ' . $count . PHP_EOL;
