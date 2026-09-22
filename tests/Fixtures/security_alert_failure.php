<?php

declare(strict_types=1);

namespace PrestaShop\PrestaShop\Core {
    interface ConfigurationInterface
    {
        public function get($key);
    }
}

namespace Mpadmin2fa\Repository {
    final class SecurityRepository
    {
        /** @var array<int, array<string, mixed>> */
        public $audits = [];

        /** @var int */
        public $auditAttempts = 0;

        /** @var bool */
        private $failAudit;

        public function __construct(bool $failAudit)
        {
            $this->failAudit = $failAudit;
        }

        public function employeeEmail(int $employeeId): ?string
        {
            return 'employee@example.test';
        }

        public function employeeIdentity(int $employeeId): string
        {
            return 'Fixture Employee';
        }

        public function audit(?int $employeeId, string $event, ?string $ip, array $metadata = []): void
        {
            ++$this->auditAttempts;
            if ($this->failAudit) {
                throw new \RuntimeException('database password=do-not-store');
            }

            $this->audits[] = [
                'employee_id' => $employeeId,
                'event' => $event,
                'ip' => $ip,
                'metadata' => $metadata,
            ];
        }
    }
}

namespace Mpadmin2fa\Security {
    final class Policy
    {
        public const CONFIG_SECURITY_RECIPIENTS = 'MPADMIN2FA_SECURITY_RECIPIENTS';
    }

    final class SecurityAlertMessageFactory
    {
        public function create(string $event, ?string $employeeIdentity, array $metadata): array
        {
            return [
                'subject' => 'Fixture alert',
                'details' => 'Fixture details',
                'details_html' => 'Fixture details',
            ];
        }
    }
}

namespace {
    final class Language
    {
        public static function getIsoById(int $languageId): string
        {
            return 'en';
        }

        public static function getIdByIso(string $iso): int
        {
            return 1;
        }
    }

    final class Mail
    {
        public static function send(...$arguments): bool
        {
            if ('exception' === $GLOBALS['fixture_failure_mode']) {
                throw new \RuntimeException('smtp://user:secret@mail.example recipient@example.test');
            }

            return false;
        }
    }

    final class FixtureConfiguration implements \PrestaShop\PrestaShop\Core\ConfigurationInterface
    {
        public function get($key)
        {
            if (\Mpadmin2fa\Security\Policy::CONFIG_SECURITY_RECIPIENTS === $key) {
                return 'security@example.test';
            }

            return 1;
        }
    }

    $GLOBALS['fixture_failure_mode'] = isset($argv[1]) ? $argv[1] : 'returned_false';
    $failAudit = 'audit_exception' === $GLOBALS['fixture_failure_mode'];
    require dirname(__DIR__, 2) . '/src/Security/SecurityAlertService.php';

    $repository = new \Mpadmin2fa\Repository\SecurityRepository($failAudit);
    $service = new \Mpadmin2fa\Security\SecurityAlertService(
        $repository,
        new FixtureConfiguration(),
        new \Mpadmin2fa\Security\SecurityAlertMessageFactory()
    );

    $completed = true;
    try {
        $service->notify(42, 'authentication.succeeded_after_failures');
    } catch (\Throwable $exception) {
        $completed = false;
    }

    echo json_encode([
        'completed' => $completed,
        'audit_attempts' => $repository->auditAttempts,
        'audits' => $repository->audits,
    ]);
}
