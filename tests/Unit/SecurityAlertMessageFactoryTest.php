<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use Mpadmin2fa\Security\SecurityAlertCatalog;
use Mpadmin2fa\Security\SecurityAlertMessageFactory;
use PHPUnit\Framework\TestCase;

final class SecurityAlertMessageFactoryTest extends TestCase
{
    /** @var SecurityAlertCatalog */
    private $catalog;
    /** @var SecurityAlertMessageFactory */
    private $factory;

    protected function setUp(): void
    {
        $this->catalog = new SecurityAlertCatalog();
        $this->factory = new SecurityAlertMessageFactory($this->catalog);
    }

    public function testSuccessfulLoginAfterFailuresMessageIsHumanReadable(): void
    {
        $message = $this->factory->create(
            'authentication.succeeded_after_failures',
            ['name' => 'Jane Doe', 'email' => 'jane@example.test'],
            [
                'elapsed_seconds' => 8100,
                'failures' => 8,
                'first_failure_at' => '2026-08-23 08:00:00',
                'ip' => '192.0.2.10',
            ]
        );

        self::assertSame('Security alert: Jane Doe logged in after 8 failed attempts', $message['subject']);
        self::assertStringContainsString('Employee: Jane Doe (jane@example.test)', $message['details']);
        self::assertStringContainsString('Failed attempts: 8', $message['details']);
        self::assertStringContainsString('Time between first failure and login: 2 hours 15 minutes', $message['details']);
        self::assertStringContainsString('Successful login IP: 192.0.2.10', $message['details']);
        self::assertStringContainsString('First failed attempt: 2026-08-23 08:00:00 UTC', $message['details']);
        self::assertStringContainsString('<strong>What it means</strong>', $message['details_html']);
        self::assertStringContainsString('<strong>What to do</strong>', $message['details_html']);
        self::assertStringContainsString('<strong>Details</strong>', $message['details_html']);
        self::assertStringContainsString('<th align="left"', $message['details_html']);

        $guidance = $this->catalog->find('authentication.succeeded_after_failures');
        self::assertNotNull($guidance);
        self::assertStringContainsString("What it means:\n" . $guidance['meaning'], $message['details']);
        self::assertStringContainsString("What to do:\n" . $guidance['action'], $message['details']);
    }

    public function testExistingAlertsIncludeSharedGuidance(): void
    {
        $message = $this->factory->create(
            'factor.reset',
            ['name' => 'Jane Doe', 'email' => 'jane@example.test'],
            ['reason' => 'admin_reset']
        );

        self::assertSame('PrestaShop back-office security alert', $message['subject']);
        $guidance = $this->catalog->find('factor.reset');
        self::assertNotNull($guidance);
        self::assertStringContainsString("What it means:\n" . $guidance['meaning'], $message['details']);
        self::assertStringContainsString("What to do:\n" . $guidance['action'], $message['details']);
        self::assertStringContainsString('Reason: admin_reset', $message['details']);
        self::assertStringNotContainsString('{', $message['details']);
        self::assertStringContainsString('<th align="left"', $message['details_html']);
        self::assertStringContainsString('Reason', $message['details_html']);
        self::assertStringContainsString('admin_reset', $message['details_html']);
    }
}
