<?php

declare(strict_types=1);

// Isolated framework/storage doubles; execute the real confirmation, manager and limiter.
namespace Mpadmin2fa\Repository {
    interface FailureCounterInterface
    {
        public function rateLimit(string $scope, string $subject): ?array;
        public function incrementFailure(string $scope, string $subject): int;
        public function clearFailures(string $scope, string $subject): void;
    }
    final class SecurityRepository implements FailureCounterInterface
    {
        public $counts = [];
        public $events = [];
        public function rateLimit(string $scope, string $subject): ?array
        {
            return ['blocked_until' => ($this->counts[$scope . $subject] ?? 0) >= 5
                ? gmdate('Y-m-d H:i:s', time() + 60) : null];
        }
        public function incrementFailure(string $scope, string $subject, int $free = 5, int $cap = 3600): int
        {
            $key = $scope . $subject;
            return $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        }
        public function clearFailures(string $scope, string $subject): void { unset($this->counts[$scope . $subject]); }
        public function factor(int $employeeId): array
        {
            return ['status' => 'active', 'secret_ciphertext' => 'fixture', 'key_version' => 1, 'last_counter' => null];
        }
        public function advanceCounter(int $employeeId, ?int $previous, int $counter): bool { return true; }
        public function audit(?int $employeeId, string $event, ?string $ip, array $metadata = []): void
        {
            $this->events[] = ['employee_id' => $employeeId, 'event' => $event, 'ip' => $ip, 'metadata' => $metadata];
        }
    }
}
namespace PrestaShopBundle\Entity\Employee {
    class Employee
    {
        private $id;
        public function __construct(int $id) { $this->id = $id; }
        public function getId(): int { return $this->id; }
    }
}
namespace Symfony\Component\PasswordHasher\Hasher {
    interface UserPasswordHasherInterface { public function isPasswordValid($employee, string $password): bool; }
}
namespace Symfony\Component\Security\Core\Encoder {
    interface UserPasswordEncoderInterface { public function isPasswordValid($employee, string $password): bool; }
}
namespace Mpadmin2fa\Security {
    final class KeyManager { public function decrypt(string $ciphertext, int $version): string { return 'fixture'; } }
    final class TotpService
    {
        public $calls = 0;
        public function verifyNewer(string $secret, string $code, ?int $previous)
        {
            ++$this->calls;
            return '123456' === $code ? 123 : false;
        }
    }
    final class RecoveryCodeService {}
    final class SecurityAlertService
    {
        public $events = [];
        public function notify(?int $employeeId, string $event, array $metadata = []): void
        {
            $this->events[] = $event;
        }
    }
    final class SessionState
    {
        public $fresh = false;
        public function authenticatedAt(int $employeeId): ?int { return $this->fresh ? time() : null; }
    }
    final class Policy { public function passwordMaximumAge(): int { return 900; } }
}
namespace {
    class_alias(\PrestaShopBundle\Entity\Employee\Employee::class, 'PrestaShopBundle\Security\Admin\Employee');
    final class PasswordChecker implements
        \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface,
        \Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface
    {
        public $calls = 0;
        public function isPasswordValid($employee, string $password): bool
        {
            ++$this->calls;
            return 'fixture-password' === $password;
        }
    }
    require dirname(__DIR__, 2) . '/src/Security/RateLimiter.php';
    require dirname(__DIR__, 2) . '/src/Security/MfaManager.php';
    require dirname(__DIR__, 2) . '/src/Security/FactorConfirmationService.php';
    $repository = new \Mpadmin2fa\Repository\SecurityRepository();
    $limiter = new \Mpadmin2fa\Security\RateLimiter($repository);
    $totp = new \Mpadmin2fa\Security\TotpService();
    $alerts = new \Mpadmin2fa\Security\SecurityAlertService();
    $mfa = new \Mpadmin2fa\Security\MfaManager($repository, new \Mpadmin2fa\Security\KeyManager(), $totp, new \Mpadmin2fa\Security\RecoveryCodeService(), $limiter, $alerts);
    $checker = new PasswordChecker();
    $session = new \Mpadmin2fa\Security\SessionState();
    $service = new \Mpadmin2fa\Security\FactorConfirmationService($checker, $session, new \Mpadmin2fa\Security\Policy(), $mfa);
    $employee = new \PrestaShopBundle\Entity\Employee\Employee(42);
    $result = [];
    for ($attempt = 0; $attempt < 5; ++$attempt) {
        $result['password_failures'][] = $service->verify($employee, ['password' => 'wrong-secret', 'code' => '123456'], '127.0.0.1');
    }
    foreach ([[42, '127.0.0.2'], [43, '127.0.0.1']] as $identity) {
        try {
            $service->verify(new \PrestaShopBundle\Entity\Employee\Employee($identity[0]), ['password' => 'fixture-password', 'code' => '123456'], $identity[1]);
            $result['blocked'][] = false;
        } catch (\RuntimeException $exception) {
            $result['blocked'][] = true;
        }
    }
    $result['checks_before_reset'] = [$checker->calls, $totp->calls];
    $result['password_audits'] = $repository->events;
    $result['alerts'] = $alerts->events;
    $limiter->success('factor_change', 42, '127.0.0.1');
    $result['wrong_totp'] = $service->verify($employee, ['password' => 'fixture-password', 'code' => '000000'], '127.0.0.1');
    $result['counts_after_wrong_totp'] = array_values($repository->counts);
    $result['both_valid'] = $service->verify($employee, ['password' => 'fixture-password', 'code' => '123456'], '127.0.0.1');
    $result['counts_after_success'] = array_values($repository->counts);
    $session->fresh = true;
    $calls = $checker->calls;
    $result['fresh_session'] = $service->verify($employee, ['password' => '', 'code' => '123456'], '127.0.0.1');
    $result['fresh_password_checks'] = $checker->calls - $calls;
    echo json_encode($result);
}
