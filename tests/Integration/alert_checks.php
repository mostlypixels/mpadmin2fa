<?php

declare(strict_types=1);

// Capture the transport boundary in this CLI process. No test sends external mail.
final class Mail
{
    public static $messages = [];
    public static $throw = false;

    public static function send(...$arguments): bool
    {
        if (self::$throw) {
            throw new RuntimeException('Injected unavailable mail transport.');
        }
        self::$messages[] = $arguments;

        return true;
    }
}

require __DIR__ . '/bootstrap.php';
$connection = Doctrine\DBAL\DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => _DB_SERVER_, 'dbname' => _DB_NAME_,
    'user' => _DB_USER_, 'password' => _DB_PASSWD_,
]);
$repository = new Mpadmin2fa\Repository\SecurityRepository($connection, _DB_PREFIX_);
$keys = new Mpadmin2fa\Security\KeyManager($repository, new Mpadmin2fa\Security\CookieKeyProvider(), new Mpadmin2fa\Security\ProtectedKeyRewrapper());
$alerts = new Mpadmin2fa\Security\SecurityAlertService($repository, new PrestaShop\PrestaShop\Adapter\Configuration(),
    new Mpadmin2fa\Security\SecurityAlertMessageFactory(new Mpadmin2fa\Security\SecurityAlertCatalog()));
$limiter = new Mpadmin2fa\Security\RateLimiter($repository);
$mfa = new Mpadmin2fa\Security\MfaManager($repository, $keys, new Mpadmin2fa\Security\TotpService(),
    new Mpadmin2fa\Security\RecoveryCodeService(), $limiter, $alerts);
$employee = (int) $connection->fetchColumn('SELECT MIN(id_employee) FROM ' . _DB_PREFIX_ . 'employee');
$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Alert integration failed: ' . $label);
    }
    ++$checks;
};
$connection->beginTransaction();
try {
    $encrypted = $keys->encrypt((new Mpadmin2fa\Security\TotpService())->generateSecret());
    $repository->savePendingEnrollment($employee, $encrypted['ciphertext'], $encrypted['key_version']);
    $repository->activateEnrollment($employee, (int) floor(time() / 30) - 2, []);
    $limiter->success('challenge', $employee, '127.0.0.21');
    Mail::$messages = [];
    // A malformed code is guaranteed invalid; six random digits could rarely be valid.
    for ($count = 1; $count <= 5; ++$count) {
        $assert(false === $mfa->verifyTotp($employee, 'invalid', '127.0.0.21'), 'invalid TOTP rejected');
        $assert(count(Mail::$messages) === ($count < 5 ? 0 : 1), 'alert starts exactly at failure five');
    }
    $assert('authentication.repeated_failures' === Mail::$messages[0][3]['{event}'], 'correct threshold alert event');
    $assert(false !== stripos(Mail::$messages[0][3]['{details}'], 'failures: 5'), 'alert carries the atomic failure count');
    $blocked = false;
    try {
        $mfa->verifyTotp($employee, 'invalid', '127.0.0.21');
    } catch (RuntimeException $exception) {
        $blocked = false !== strpos($exception->getMessage(), 'Too many attempts');
    }
    $assert($blocked && 1 === count(Mail::$messages), 'blocked requests neither verify nor repeat an alert');
    for ($count = 6; $count <= 9; ++$count) {
        $connection->executeUpdate('UPDATE ' . _DB_PREFIX_ . 'mp2fa_rate_limit SET blocked_until = NULL WHERE scope = ?', ['challenge']);
        $assert(false === $mfa->verifyTotp($employee, 'invalid', '127.0.0.21'), 'post-block failure rejected');
        $assert(count(Mail::$messages) === ($count < 9 ? 2 : 3), 'repeat alerts occur at counts six and nine');
    }
    $limiter->success('challenge', $employee, '127.0.0.21');
    $assert(null === $repository->rateLimit('challenge', hash('sha256', 'employee:' . $employee)), 'success clears employee block');
    $assert(null === $repository->rateLimit('challenge', hash('sha256', 'ip:127.0.0.21')), 'success clears IP block');
    Mail::$throw = true;
    for ($count = 1; $count <= 5; ++$count) {
        $assert(false === $mfa->verifyTotp($employee, 'invalid', '127.0.0.21'), 'mail failure cannot change authentication result');
    }
    $assert(5 === (int) $repository->rateLimit('challenge', hash('sha256', 'employee:' . $employee))['failures'],
        'mail failure cannot discard the atomic count');
} finally {
    $connection->rollBack();
    $connection->close();
}
echo 'Alert integration checks passed: ' . $checks . PHP_EOL;
