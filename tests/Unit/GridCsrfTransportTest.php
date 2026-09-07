<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class GridCsrfTransportTest extends TestCase
{
    public function testGridTokensAreTransportedOnlyInPostBodies(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root . '/src/Controller/Admin/MfaController.php');
        $employeeGrid = (string) file_get_contents($root . '/src/Grid/Definition/Factory/EmployeeFactorGridDefinitionFactory.php');
        $approvalGrid = (string) file_get_contents($root . '/src/Grid/Definition/Factory/PendingApprovalGridDefinitionFactory.php');
        $template = (string) file_get_contents($root . '/views/PrestaShop/Admin/Common/Grid/Actions/Row/mp2fa_secure_submit.html.twig');

        self::assertStringNotContainsString("query->get('token')", $controller);
        self::assertStringContainsString("request->get('mp2fa_csrf_token')", $controller);
        self::assertStringContainsString("'csrf_token_field' => 'reset_token'", $employeeGrid);
        self::assertStringContainsString("'csrf_token_field' => 'approval_token'", $approvalGrid);
        self::assertStringContainsString('data-csrf-token=', $template);
        self::assertStringContainsString('data-url=', $template);
        self::assertStringNotContainsString('token:', $template);
    }
}
