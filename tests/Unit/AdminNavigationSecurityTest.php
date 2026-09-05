<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

$moduleLoader = require dirname(__DIR__, 2) . '/vendor/autoload.php';
class_exists(\PHPUnit\Framework\TestCase::class);
interface_exists(\PHPUnit\Framework\Test::class);
require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
spl_autoload_register(static function ($class) use ($moduleLoader): void {
    if (0 === strpos($class, 'PHPUnit\\')) {
        $moduleLoader->loadClass($class);
    }
}, true, true);

use Mpadmin2fa\Controller\Admin\MfaController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use ReflectionMethod;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

final class AdminNavigationSecurityTest extends TestCase
{
    public static function securedActions(): iterable
    {
        yield 'settings resolver' => ['settings', 'read'];
        yield 'authenticator page' => ['authenticator', 'read'];
        yield 'employee enrollment list' => ['enrollmentEmployees', 'read'];
        yield 'pending approvals' => ['enrollmentApprovals', 'read'];
        yield 'approve enrollment' => ['approveEnrollment', 'read'];
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

    public function testDashboardRedirectFallsBackToNativeAdminHomepage(): void
    {
        $routes = new RouteCollection();
        $routes->add('admin_homepage', new Route('/'));
        $router = $this->createMock(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($routes);
        $router->expects(self::once())
            ->method('generate')
            ->with('admin_homepage')
            ->willReturn('/admin-dev/index.php/');

        $method = new ReflectionMethod(MfaController::class, 'dashboardUrl');
        $method->setAccessible(true);

        self::assertSame(
            '/admin-dev/index.php/',
            $method->invoke(new MfaController(), $router)
        );
    }

    public function testActiveFactorIsRedirectedBeforeNewEnrollmentApprovalIsConsidered(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2) . '/src/Controller/Admin/MfaController.php');

        self::assertIsString($controller);
        $activeGuard = strpos(
            $controller,
            '$mfa->active($employee->getId()) && !$authorizedReplacement'
        );
        $approvalGuard = strpos($controller, '$policy->requiresEnrollmentApproval($employee)');
        self::assertIsInt($activeGuard);
        self::assertIsInt($approvalGuard);
        self::assertLessThan($approvalGuard, $activeGuard);
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
        self::assertSame(3, substr_count($template, 'class="card d-flex flex-column h-100"'));
        self::assertSame(3, substr_count($template, 'class="card-footer d-flex justify-content-end mt-auto"'));
    }

    public function testOverviewDisplaysAuthenticatorStatusAsContextualGuidance(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/views/templates/admin/settings.html.twig');

        self::assertIsString($template);
        self::assertStringContainsString(
            "authenticator_active ? 'Manage and strengthen 2FA' : 'Get started'",
            $template
        );
        self::assertStringContainsString('1. Your account is protected', $template);
        self::assertStringContainsString('1. Protect your account', $template);
        self::assertStringContainsString(
            "authenticator_active ? 'Manage your authenticator' : 'Set up your authenticator'",
            $template
        );
    }

    public function testOverviewWarnsWhenHttpsIsNotFullyEnabled(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2) . '/src/Controller/Admin/MfaController.php');
        $template = file_get_contents(dirname(__DIR__, 2) . '/views/templates/admin/settings.html.twig');

        self::assertIsString($controller);
        self::assertIsString($template);
        self::assertStringContainsString("get('PS_SSL_ENABLED')", $controller);
        self::assertStringContainsString("'https_active' => \$request->isSecure()", $controller);
        self::assertStringContainsString('{% if not https_configured or not https_active %}', $template);
        self::assertStringContainsString('HTTPS needs attention', $template);
    }

    public function testAuthenticatorFormsUseSeparateCards(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/views/templates/admin/authenticator.html.twig');

        self::assertIsString($template);
        self::assertSame(3, substr_count($template, 'class="card mb-3"'));
        self::assertStringContainsString('<h3 class="card-header">Set up a new authenticator</h3>', $template);
        self::assertStringContainsString('<h3 class="card-header">Create new recovery codes</h3>', $template);
        self::assertStringContainsString('<h3 class="card-header">Turn off two-factor authentication</h3>', $template);
    }

    public function testEnrollmentUsesNativeHeaderTabs(): void
    {
        $layout = file_get_contents(dirname(__DIR__, 2) . '/views/templates/admin/enrollment/layout.html.twig');

        self::assertIsString($layout);
        self::assertStringContainsString('{% set headerTabContent = [enrollmentTabs] %}', $layout);
        self::assertStringContainsString('class="nav nav-pills"', $layout);
        self::assertStringContainsString("'active current'", $layout);
        self::assertStringNotContainsString('nav-tabs', $layout);
    }

    public function testSecurityPolicyGroupsFieldsIntoCoherentSections(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/views/templates/admin/security/policy.html.twig');

        self::assertIsString($template);
        self::assertStringContainsString('<h3 class="card-header">Who must use two-factor authentication</h3>', $template);
        self::assertStringContainsString('<h3 class="card-header">Other security settings</h3>', $template);
        self::assertLessThan(
            strpos($template, 'policy_form.step_up_seconds'),
            strpos($template, 'policy_form.approval_profiles')
        );
        self::assertStringNotContainsString('form_widget(policy_form)', $template);
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
