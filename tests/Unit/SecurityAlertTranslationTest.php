<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Mpadmin2fa\Security\SecurityAlertCatalog;
use Mpadmin2fa\Security\SecurityAlertMessageFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatorInterface;

final class SecurityAlertTranslationTest extends TestCase
{
    public function testAlertTextUsesTheShopLanguageTranslator(): void
    {
        $catalog = new SecurityAlertCatalog();
        $factory = new SecurityAlertMessageFactory($catalog);
        $translator = new class() implements TranslatorInterface {
            public function trans($id, array $parameters = [], $domain = null, $locale = null)
            {
                return strtr('Modules.Mpadmin2fa.Emails' === $domain ? '[fr] ' . $id : $id, $parameters);
            }

            public function transChoice($id, $number, array $parameters = [], $domain = null, $locale = null)
            {
                return $this->trans($id, $parameters, $domain, $locale);
            }

            public function setLocale($locale)
            {
            }

            public function getLocale()
            {
                return 'fr-FR';
            }
        };

        $message = $factory->withTranslator($translator)->create(
            'authentication.succeeded_after_failures',
            ['name' => 'Jane Doe', 'email' => 'jane@example.test'],
            ['elapsed_seconds' => 3660, 'failures' => 1, 'first_failure_at' => '2026-08-23 08:00:00', 'ip' => '192.0.2.10']
        );
        $guidance = $catalog->find('authentication.succeeded_after_failures');

        self::assertNotNull($guidance);
        self::assertSame('[fr] Security alert: Jane Doe logged in after 1 failed attempt', $message['subject']);
        self::assertStringContainsString("[fr] What it means:\n[fr] " . $guidance['meaning'], $message['details']);
        self::assertStringContainsString('[fr] Failed attempts: 1', $message['details']);
        self::assertStringContainsString('[fr] Time between first failure and login: [fr] 1 hour [fr] 1 minute', $message['details']);
        self::assertStringContainsString('<strong>[fr] Details</strong>', $message['details_html']);

        $english = $factory->create('factor.reset', null, ['reason' => 'admin_reset']);
        self::assertSame('PrestaShop back-office security alert', $english['subject']);
        self::assertStringContainsString('Reason: admin_reset', $english['details']);
    }
}
