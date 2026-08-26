<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Mpadmin2fa\Repository\SecurityRepository;
use Mpadmin2fa\Security\DashboardActivityWindow;
use Mpadmin2fa\Security\SecurityActivityAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class DashboardSecurityActivityTest extends TestCase
{
    public function testMondayUsesNinetySixHoursAndOtherDaysUseFortyEight(): void
    {
        $window = new DashboardActivityWindow();
        $timezone = new DateTimeZone('Europe/Brussels');

        $monday = new DateTimeImmutable('2026-08-24 09:00:00', $timezone);
        $tuesday = new DateTimeImmutable('2026-08-25 09:00:00', $timezone);

        self::assertSame(96, $window->hours($monday));
        self::assertSame('2026-08-20 09:00:00', $window->since($monday)->format('Y-m-d H:i:s'));
        self::assertSame(48, $window->hours($tuesday));
        self::assertSame('2026-08-23 09:00:00', $window->since($tuesday)->format('Y-m-d H:i:s'));
    }

    public function testActivityAccessUsesTheNativeActivityLogReadPermission(): void
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::once())
            ->method('isGranted')
            ->with('read', 'AdminMpAdmin2faSecurityActivity')
            ->willReturn(true);

        self::assertTrue((new SecurityActivityAccess($checker))->canRead());
    }

    public function testRepositoryGroupsIdenticalImportantEvents(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('quoteIdentifier')
            ->willReturnCallback(static function (string $identifier): string { return '`' . $identifier . '`'; });
        $connection->expects(self::once())
            ->method('fetchAll')
            ->with(
                self::callback(static function (string $sql): bool {
                    self::assertStringContainsString('WHERE a.date_add >= ?', $sql);
                    self::assertStringContainsString('a.event IN (', $sql);
                    self::assertStringContainsString('COUNT(*) AS occurrences', $sql);
                    self::assertStringContainsString(
                        'GROUP BY a.id_employee, a.event, a.metadata_json',
                        $sql
                    );
                    self::assertStringContainsString('ORDER BY date_add DESC', $sql);

                    return true;
                }),
                self::callback(static function (array $parameters): bool {
                    self::assertSame('2026-08-24 07:00:00', $parameters[0]);
                    self::assertContains('challenge.failed', $parameters);
                    self::assertContains('factor.reset', $parameters);
                    self::assertNotContains('challenge.verified', $parameters);

                    return true;
                })
            )
            ->willReturn([[
                'date_add' => '2026-08-24 08:30:00',
                'employee' => 'Kathy Smith',
                'event_label' => 'Sign-in 2FA failed',
                'occurrences' => '5',
            ]]);

        $events = (new SecurityRepository($connection, 'ps_'))->importantDashboardEventsSince(
            new DateTimeImmutable('2026-08-24 09:00:00', new DateTimeZone('Europe/Brussels'))
        );

        self::assertSame(5, $events[0]['occurrences']);
        self::assertSame('Kathy Smith', $events[0]['employee']);
    }

    public function testBothDashboardTemplatesGateTheActivitySectionByPermission(): void
    {
        $moduleRoot = dirname(__DIR__, 2);
        $module = file_get_contents($moduleRoot . '/mpadmin2fa.php');
        $twig = file_get_contents($moduleRoot . '/views/templates/hook/dashboard_enrollment.html.twig');
        $smarty = file_get_contents($moduleRoot . '/views/templates/hook/dashboard_enrollment.tpl');

        self::assertIsString($module);
        self::assertStringContainsString('importantDashboardEventsSince', $module);
        self::assertStringContainsString("'%count% times'", $module);
        self::assertStringContainsString("generate('mpadmin2fa_security_activity')", $module);
        self::assertStringNotContainsString("getAdminLink('AdminMpAdmin2faSecurityActivity')", $module);
        self::assertIsString($twig);
        self::assertStringContainsString('{% if can_view_security_activity %}', $twig);
        self::assertStringContainsString('table table-bordered table-sm', $twig);
        self::assertStringContainsString('style="width: 1%;"', $twig);
        self::assertStringContainsString('{{ event.employee }} · {{ event.date_add }}', $twig);
        self::assertStringNotContainsString('event.ip', $twig);
        self::assertStringNotContainsString('badge-warning', $twig);
        self::assertStringContainsString('{{ security_activity_see_all }}', $twig);
        self::assertStringContainsString('<div class="text-right mt-3">', $twig);
        self::assertIsString($smarty);
        self::assertStringContainsString('{if $can_view_security_activity}', $smarty);
        self::assertStringContainsString('table table-bordered table-condensed', $smarty);
        self::assertStringContainsString('white-space: nowrap', $smarty);
        self::assertStringNotContainsString('event.ip', $smarty);
        self::assertStringNotContainsString('label-warning', $smarty);
        self::assertStringContainsString('{$security_activity_see_all|escape:', $smarty);
        self::assertStringContainsString('<div class="text-right">', $smarty);
    }
}
