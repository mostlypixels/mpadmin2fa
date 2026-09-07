<?php

declare(strict_types=1);

// Run in a child process so these boundary doubles cannot replace services in other tests.
namespace Mpadmin2fa\Repository {
    final class SecurityRepository
    {
        public function factor(int $employeeId): array
        {
            return ['status' => 'active', 'secret_ciphertext' => 'fixture', 'key_version' => 1, 'last_counter' => null];
        }
        public function advanceCounter(int $employeeId, ?int $previous, int $counter): bool { return true; }
        public function challengeFailuresSinceLastSuccess(int $employeeId): array
        {
            return ['failures' => 5, 'first_failure_at' => gmdate('Y-m-d H:i:s', \Mpadmin2fa\Security\time() - 300)];
        }
        public function audit(?int $employeeId, string $event, ?string $ip, array $metadata = []): void {}
    }
}

namespace Mpadmin2fa\Security {
    function time(): int { return $GLOBALS['fixture_now']; }
    final class KeyManager { public function decrypt(string $ciphertext, int $version): string { return 'fixture'; } }
    final class TotpService { public function verifyNewer(string $secret, string $code, ?int $previous): int { return 123; } }
    final class RecoveryCodeService {}
    final class RateLimiter
    {
        public function assertAllowed(string $scope, int $employeeId, ?string $ip): void {}
        public function success(string $scope, int $employeeId, ?string $ip): void {}
    }
    final class SecurityAlertService
    {
        public $notifications = [];
        public function notifySecurityRecipients(?int $employeeId, string $event, array $metadata): void
        {
            $this->notifications[] = ['event' => $event, 'metadata' => $metadata];
        }
    }
}

namespace {
    require dirname(__DIR__, 2) . '/src/Security/MfaManager.php';
    $results = [];
    foreach (['2026-03-29 01:02:00 UTC', '2026-10-25 01:02:00 UTC'] as $instant) {
        $GLOBALS['fixture_now'] = strtotime($instant);
        foreach (['UTC', 'Europe/Brussels', 'America/New_York', 'Asia/Kathmandu'] as $timezone) {
            date_default_timezone_set($timezone);
            $alerts = new \Mpadmin2fa\Security\SecurityAlertService();
            $manager = new \Mpadmin2fa\Security\MfaManager(
                new \Mpadmin2fa\Repository\SecurityRepository(),
                new \Mpadmin2fa\Security\KeyManager(),
                new \Mpadmin2fa\Security\TotpService(),
                new \Mpadmin2fa\Security\RecoveryCodeService(),
                new \Mpadmin2fa\Security\RateLimiter(),
                $alerts
            );
            $results[] = [
                'timezone' => $timezone,
                'verified' => $manager->verifyTotp(42, '123456', '127.0.0.1'),
                'notifications' => $alerts->notifications,
            ];
        }
    }
    echo json_encode($results);
}
