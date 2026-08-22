<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Mpadmin2fa\Controller\Admin\MfaController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;

final class AdminNavigationSecurityTest extends TestCase
{
    public static function securedActions(): iterable
    {
        yield 'settings resolver' => ['settings', 'read'];
        yield 'authenticator page' => ['authenticator', 'read'];
        yield 'employee enrollment list' => ['enrollmentEmployees', 'read'];
        yield 'pending approvals' => ['enrollmentApprovals', 'read'];
        yield 'approve enrollment' => ['approveEnrollment', 'update'];
        yield 'reset employee factor' => ['adminReset', 'delete'];
        yield 'security policy' => ['securityPolicy', 'read'];
        yield 'update security policy' => ['updateSecurityPolicy', 'update'];
        yield 'security activity' => ['securityActivity', 'read'];
    }

    #[DataProvider('securedActions')]
    public function testAdministrativeActionsUseNativeProfilePermissions(string $method, string $permission): void
    {
        $attributes = (new ReflectionMethod(MfaController::class, $method))->getAttributes(AdminSecurity::class);

        self::assertCount(1, $attributes);
        self::assertSame(
            sprintf("is_granted('%s', request.get('_legacy_controller'))", $permission),
            $attributes[0]->newInstance()->getAttribute()
        );
    }

    public function testRoutesUseASeparatePermissionScopeForEachSidebarSection(): void
    {
        $routes = Yaml::parseFile(dirname(__DIR__, 2) . '/config/routes.yml');

        self::assertSame('AdminMpAdmin2fa', $routes['mpadmin2fa_settings']['defaults']['_legacy_controller']);
        self::assertSame('AdminMpAdmin2faAuthenticator', $routes['mpadmin2fa_authenticator']['defaults']['_legacy_controller']);

        foreach ([
            'mpadmin2fa_enrollment_employees',
            'mpadmin2fa_enrollment_employees_search',
            'mpadmin2fa_enrollment_approvals',
            'mpadmin2fa_enrollment_approvals_search',
            'mpadmin2fa_approve',
            'mpadmin2fa_admin_reset',
        ] as $route) {
            self::assertSame('AdminMpAdmin2faEnrollment', $routes[$route]['defaults']['_legacy_controller']);
        }

        foreach (['mpadmin2fa_security_policy', 'mpadmin2fa_security_policy_update'] as $route) {
            self::assertSame('AdminMpAdmin2faSecurity', $routes[$route]['defaults']['_legacy_controller']);
        }
        self::assertSame(
            'AdminMpAdmin2faSecurityActivity',
            $routes['mpadmin2fa_security_activity']['defaults']['_legacy_controller']
        );
        self::assertSame(
            'AdminMpAdmin2faSecurityActivity',
            $routes['mpadmin2fa_security_activity_search']['defaults']['_legacy_controller']
        );
    }

    public function testOverviewTutorialLinksToEverySidebarSection(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/views/templates/admin/settings.html.twig');

        self::assertIsString($template);
        self::assertStringContainsString("path('mpadmin2fa_authenticator')", $template);
        self::assertStringContainsString("path('mpadmin2fa_enrollment_employees')", $template);
        self::assertStringContainsString("path('mpadmin2fa_security_policy')", $template);
    }

    public function testSecurityNavigationUsesRegisteredAdminTabs(): void
    {
        $module = file_get_contents(dirname(__DIR__, 2) . '/mpadmin2fa.php');
        $layout = file_get_contents(dirname(__DIR__, 2) . '/views/templates/admin/security/layout.html.twig');

        self::assertIsString($module);
        self::assertIsString($layout);
        self::assertStringContainsString("'class_name' => 'AdminMpAdmin2faSecurityActivity'", $module);
        self::assertStringContainsString("'parent_class_name' => 'AdminMpAdmin2faSecurity'", $module);
        self::assertStringNotContainsString('nav-tabs', $layout);
    }

    public function testControllerUsesActionInjectionInsteadOfAServiceConstructor(): void
    {
        self::assertNull((new \ReflectionClass(MfaController::class))->getConstructor());
    }

    public function testAdminTemplatesDoNotContainHandWrittenFormControls(): void
    {
        $templates = glob(dirname(__DIR__, 2) . '/views/templates/admin/*.html.twig');
        $templates = array_merge(
            $templates ?: [],
            glob(dirname(__DIR__, 2) . '/views/templates/admin/*/*.html.twig') ?: []
        );

        self::assertNotEmpty($templates);
        foreach ($templates as $templatePath) {
            $template = file_get_contents($templatePath);

            self::assertIsString($template);
            self::assertDoesNotMatchRegularExpression(
                '/<(?:form|input|select|textarea|button)\b/i',
                $template,
                $templatePath
            );
        }
    }

    public function testTabularAdminPagesUseRegisteredNativeGrids(): void
    {
        $services = file_get_contents(dirname(__DIR__, 2) . '/config/admin/services.yml');

        self::assertIsString($services);
        self::assertSame(3, substr_count($services, "tags: ['core.grid_definition_factory']"));
        self::assertStringContainsString('grid_panel.html.twig', (string) file_get_contents(
            dirname(__DIR__, 2) . '/views/templates/admin/enrollment/employees.html.twig'
        ));
        self::assertStringContainsString('grid_panel.html.twig', (string) file_get_contents(
            dirname(__DIR__, 2) . '/views/templates/admin/enrollment/approvals.html.twig'
        ));
        self::assertStringContainsString('grid_panel.html.twig', (string) file_get_contents(
            dirname(__DIR__, 2) . '/views/templates/admin/security/activity.html.twig'
        ));
    }
}
