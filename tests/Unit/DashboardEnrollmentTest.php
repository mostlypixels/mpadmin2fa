<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Doctrine\DBAL\Connection;
use Mpadmin2fa\Repository\SecurityRepository;
use PHPUnit\Framework\TestCase;

final class DashboardEnrollmentTest extends TestCase
{
    public function testSummaryCountsOnlyActiveEmployeesWithoutAnActiveFactor(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('quoteIdentifier')
            ->willReturnCallback(static function (string $identifier): string { return '`' . $identifier . '`'; });
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->with(self::callback(static function (string $sql): bool {
                self::assertStringContainsString('LEFT JOIN `ps_mp2fa_employee` f', $sql);
                self::assertStringContainsString('f.status = "active"', $sql);
                self::assertStringContainsString('WHERE e.active = 1', $sql);

                return true;
            }))
            ->willReturn(['not_enrolled' => '3', 'total' => '8']);

        $summary = (new SecurityRepository($connection, 'ps_'))->activeEmployeeEnrollmentSummary();

        self::assertSame(['not_enrolled' => 3, 'total' => 8], $summary);
    }

    public function testDashboardHooksAndMessageArePresent(): void
    {
        $module = file_get_contents(dirname(__DIR__, 2) . '/mpadmin2fa.php');

        self::assertIsString($module);
        self::assertStringContainsString("registerHook('dashboardZoneOne')", $module);
        self::assertStringContainsString("registerHook('displayAdminDashboardZoneOne')", $module);
        self::assertStringContainsString(
            '%not_enrolled% out of your %total% employees are not enrolled in the',
            $module
        );
        self::assertStringContainsString("getAdminLink('AdminMpAdmin2faEnrollment')", $module);

        $twigTemplate = file_get_contents(
            dirname(__DIR__, 2) . '/views/templates/hook/dashboard_enrollment.html.twig'
        );
        $legacyTemplate = file_get_contents(
            dirname(__DIR__, 2) . '/views/templates/hook/dashboard_enrollment.tpl'
        );

        self::assertIsString($twigTemplate);
        self::assertStringContainsString(
            '<a class="alert-link" href="{{ enrollment_url }}">{{ enrollment_link_label }}</a>.',
            $twigTemplate
        );
        self::assertIsString($legacyTemplate);
        self::assertStringContainsString(
            '<a class="alert-link" href="{$enrollment_url|escape:\'html\':\'UTF-8\'}">',
            $legacyTemplate
        );
    }
}
