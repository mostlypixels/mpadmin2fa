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
$secret = (new Mpadmin2fa\Security\TotpService())->generateSecret();
$encrypted = $keys->encrypt($secret);
$repository->savePendingEnrollment($employeeId, $encrypted['ciphertext'], $encrypted['key_version']);
$repository->activateEnrollment($employeeId, (int) floor(time() / 30) - 2, []);
Configuration::updateValue('PS_SSL_ENABLED', 1);
Configuration::updateValue('PS_SSL_ENABLED_EVERYWHERE', 1);
Configuration::updateValue('PS_COOKIE_CHECKIP', 0);

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
    file_put_contents($runtime . '/last-response.html', $body);
    return ['status' => (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body];
};
$check = static function (bool $condition, string $label) use (&$count, &$failures): void {
    ++$count;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
    if (!$condition) {
        $failures[] = $label;
    }
};
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
// Independent RFC 6238 fixture; do not call the module verifier to produce its input.
$bits = '';
foreach (str_split($secret) as $character) {
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
$code = str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
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
curl_close($client);
if ($failures) {
    throw new RuntimeException(count($failures) . ' live-request checks failed out of ' . $count . '.');
}
echo 'Live-request checks passed: ' . $count . PHP_EOL;
