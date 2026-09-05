<?php

declare(strict_types=1);

use Mpadmin2fa\Http\LegacyAdminMfaAdapter;
use Mpadmin2fa\Install\AdminTabLifecycle;
use Mpadmin2fa\Install\SchemaInstaller;
use Mpadmin2fa\Mail\MailThemeLayoutRegistrar;
use Mpadmin2fa\Repository\SecurityRepository;
use Mpadmin2fa\Security\DashboardActivityWindow;
use Mpadmin2fa\Security\SecurityActivityAccess;
use PrestaShop\PrestaShop\Core\MailTemplate\ThemeCatalogInterface;
use PrestaShop\PrestaShop\Core\MailTemplate\ThemeCollectionInterface;

/*
 * Mpadmin2fa
 *
 * @license MIT
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

if (is_file(__DIR__ . '/vendor-scoped/autoload.php')) {
    require_once __DIR__ . '/vendor-scoped/autoload.php';
} elseif (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class Mpadmin2fa extends Module
{
    /** @var array<int, array<string, mixed>> */
    private array $declaredTabs = [];

    public function __construct()
    {
        $this->name = 'mpadmin2fa';
        $this->tab = 'administration';
        $this->version = '0.2.8';
        $this->author = 'A vibe coder';
        $this->need_instance = 0;
        $this->bootstrap = true;

        $tabNames = [];
        foreach (Language::getLanguages(true) as $language) {
            $locale = (string) $language['locale'];
            $tabNames['parent'][$locale] = $this->trans('Admin 2FA', [], 'Modules.Mpadmin2fa.Admin', $locale);
            $tabNames['authenticator'][$locale] = $this->trans('Your authenticator', [], 'Modules.Mpadmin2fa.Admin', $locale);
            $tabNames['enrollment'][$locale] = $this->trans('Employee 2FA', [], 'Modules.Mpadmin2fa.Admin', $locale);
            $tabNames['security'][$locale] = $this->trans('Security', [], 'Modules.Mpadmin2fa.Admin', $locale);
            $tabNames['activity'][$locale] = $this->trans('Activity log', [], 'Modules.Mpadmin2fa.Admin', $locale);
        }
        $this->declaredTabs = [
            [
                'route_name' => 'mpadmin2fa_settings',
                'class_name' => 'AdminMpAdmin2fa',
                'visible' => true,
                'name' => $tabNames['parent'],
                'icon' => 'security',
                'wording' => 'Admin 2FA',
                'wording_domain' => 'Modules.Mpadmin2fa.Admin',
            ],
            [
                'route_name' => 'mpadmin2fa_authenticator',
                'class_name' => 'AdminMpAdmin2faAuthenticator',
                'visible' => true,
                'name' => $tabNames['authenticator'],
                'parent_class_name' => 'AdminMpAdmin2fa',
                'wording' => 'Your authenticator',
                'wording_domain' => 'Modules.Mpadmin2fa.Admin',
            ],
            [
                'route_name' => 'mpadmin2fa_enrollment_employees',
                'class_name' => 'AdminMpAdmin2faEnrollment',
                'visible' => true,
                'name' => $tabNames['enrollment'],
                'parent_class_name' => 'AdminMpAdmin2fa',
                'wording' => 'Employee 2FA',
                'wording_domain' => 'Modules.Mpadmin2fa.Admin',
            ],
            [
                'route_name' => 'mpadmin2fa_security_policy',
                'class_name' => 'AdminMpAdmin2faSecurity',
                'visible' => true,
                'name' => $tabNames['security'],
                'parent_class_name' => 'AdminMpAdmin2fa',
                'wording' => 'Security',
                'wording_domain' => 'Modules.Mpadmin2fa.Admin',
            ],
            [
                'route_name' => 'mpadmin2fa_security_activity',
                'class_name' => 'AdminMpAdmin2faSecurityActivity',
                'visible' => true,
                'name' => $tabNames['activity'],
                'parent_class_name' => 'AdminMpAdmin2faSecurity',
                'wording' => 'Activity log',
                'wording_domain' => 'Modules.Mpadmin2fa.Admin',
            ],
        ];
        $this->tabs = [];

        parent::__construct();

        $this->displayName = $this->trans('Admin 2FA', [], 'Modules.Mpadmin2fa.Admin');
        $this->description = $this->trans(
            'TOTP two-factor authentication and step-up protection for sensitive back-office operations.',
            [],
            'Modules.Mpadmin2fa.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Uninstalling permanently removes all enrolled factors, recovery codes, encryption keys, policy, and audit records.',
            [],
            'Modules.Mpadmin2fa.Admin'
        );
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.1.99'];
    }

    public function install(): bool
    {
        if (!is_file(__DIR__ . '/vendor/autoload.php') && !is_file(__DIR__ . '/vendor-scoped/autoload.php')) {
            $this->_errors[] = $this->trans('The production dependencies are missing.', [], 'Modules.Mpadmin2fa.Admin');

            return false;
        }

        $parentAttempted = false;
        try {
            $parentAttempted = true;
            if (!parent::install()) {
                throw new RuntimeException('PrestaShop could not install the module.');
            }
            if (!(new SchemaInstaller())->install()) {
                throw new RuntimeException('The 2FA database schema could not be installed.');
            }
            if (!$this->installConfiguration()) {
                throw new RuntimeException('The 2FA configuration could not be installed.');
            }
            if (!$this->registerRequiredHooks()) {
                throw new RuntimeException('The 2FA hooks could not be registered.');
            }
            if (!$this->reconcileAdminTabs()) {
                throw new RuntimeException('The 2FA admin tabs or profile access could not be installed.');
            }

            return true;
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();
            $this->rollbackInstall($parentAttempted);

            return false;
        }
    }

    public function postInstall(): bool
    {
        return $this->reconcileAdminTabs();
    }

    public function uninstall(): bool
    {
        $cleaned = true;
        try {
            $cleaned = (new AdminTabLifecycle())->remove($this->name, $this->declaredTabs) && $cleaned;
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();
            $cleaned = false;
        }
        try {
            $cleaned = $this->deleteConfiguration() && $cleaned;
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();
            $cleaned = false;
        }
        try {
            $cleaned = (new SchemaInstaller())->uninstall() && $cleaned;
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();
            $cleaned = false;
        }
        try {
            $cleaned = parent::uninstall() && $cleaned;
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();
            $cleaned = false;
        }

        return $cleaned;
    }

    /**
     * Reconcile the visible tab hierarchy when upgrading an existing installation.
     */
    public function upgradeAdminTabs(): bool
    {
        return $this->reconcileAdminTabs();
    }

    public function reconcileAdminTabs(): bool
    {
        return (new AdminTabLifecycle())->reconcile($this->name, $this->declaredTabs);
    }

    public function getContent(): string
    {
        Tools::redirectAdmin($this->get('router')->generate('mpadmin2fa_settings'));

        return '';
    }

    /**
     * Load the listener that turns an AJAX step-up response into a full-page challenge.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionAdminControllerSetMedia(array $params): void
    {
        if (!method_exists($this->context->controller, 'addJS')) {
            return;
        }

        $this->context->controller->addJS($this->_path . 'views/js/admin-step-up.js');
    }

    /**
     * Stop legacy back-office requests before their controller action runs.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionDispatcherBefore(array $params): void
    {
        $response = $this->get(LegacyAdminMfaAdapter::class)->enforce(
            $this->context,
            (int) ($params['controller_type'] ?? -1),
        );
        if (null === $response) {
            return;
        }

        $response->send();
        exit;
    }

    /**
     * Remove deleted profile IDs from the 2FA policy settings.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionObjectProfileDeleteAfter(array $params): void
    {
        $profile = $params['object'] ?? null;
        if (!$profile instanceof Profile || (int) $profile->id <= 0) {
            return;
        }

        foreach ([
            Mpadmin2fa\Security\Policy::CONFIG_PROFILES,
            Mpadmin2fa\Security\Policy::CONFIG_APPROVAL_PROFILES,
        ] as $configurationKey) {
            $currentValue = (string) Configuration::get($configurationKey);
            $cleanedValue = Mpadmin2fa\Security\ProfilePolicyCleaner::removeFromList(
                $currentValue,
                (int) $profile->id
            );
            if ($cleanedValue !== $currentValue) {
                Configuration::updateValue($configurationKey, $cleanedValue);
            }
        }
    }

    /**
     * Add the Admin 2FA alert layout to PrestaShop's built-in mail themes.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionListMailThemes(array $params): void
    {
        $themes = $params['mailThemes'] ?? null;
        if (!$themes instanceof ThemeCollectionInterface) {
            return;
        }

        (new MailThemeLayoutRegistrar())->register($themes, $this->name);
    }

    /**
     * Display the enrollment summary on the migrated dashboard.
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayAdminDashboardZoneOne(array $params): string
    {
        $twig = $this->getTwig();
        if (null === $twig) {
            return '';
        }

        return $twig->render(
            '@Modules/mpadmin2fa/views/templates/hook/dashboard_enrollment.html.twig',
            $this->dashboardEnrollmentTemplateData()
        );
    }

    /**
     * Display the enrollment summary on the legacy dashboard.
     *
     * @param array<string, mixed> $params
     */
    public function hookDashboardZoneOne(array $params): string
    {
        $this->context->smarty->assign($this->dashboardEnrollmentTemplateData());

        return $this->display(__FILE__, 'dashboard_enrollment.tpl');
    }

    /**
     * @return array{
     *     title: string,
     *     message_prefix: string,
     *     enrollment_link_label: string,
     *     enrollment_url: string,
     *     has_unenrolled: bool,
     *     can_view_security_activity: bool,
     *     security_activity_title: string,
     *     security_activity_empty: string,
     *     security_activity_see_all: string,
     *     security_activity_url: string,
     *     security_event_column_label: string,
     *     security_occurrences_column_label: string,
     *     security_window_hours: int,
     *     security_events: list<array{
     *         date_add: string,
     *         employee: string,
     *         event_label: string,
     *         occurrences: int,
     *         occurrence_label: string
     *     }>
     * }
     */
    private function dashboardEnrollmentTemplateData(): array
    {
        $repository = $this->get(SecurityRepository::class);
        $summary = [
            'not_enrolled' => 0,
            'total' => 0,
        ];
        if ($repository instanceof SecurityRepository) {
            $summary = $repository->activeEmployeeEnrollmentSummary();
        }

        $access = $this->get(SecurityActivityAccess::class);
        $canViewSecurityActivity = $access instanceof SecurityActivityAccess && $access->canRead();
        $windowHours = 48;
        $securityEvents = [];
        if ($canViewSecurityActivity && $repository instanceof SecurityRepository) {
            $timezoneName = (string) Configuration::get('PS_TIMEZONE');
            try {
                $timezone = new DateTimeZone('' !== $timezoneName ? $timezoneName : 'UTC');
            } catch (Throwable) {
                $timezone = new DateTimeZone('UTC');
            }
            $now = new DateTimeImmutable('now', $timezone);
            $window = new DashboardActivityWindow();
            $windowHours = $window->hours($now);
            $securityEvents = $repository->importantDashboardEventsSince($window->since($now));
            foreach ($securityEvents as &$event) {
                $event['occurrence_label'] = $event['occurrences'] > 1
                    ? $this->trans(
                        '%count% times',
                        ['%count%' => (string) $event['occurrences']],
                        'Modules.Mpadmin2fa.Admin'
                    )
                    : '';
            }
            unset($event);
        }

        return [
            'title' => $this->trans('Two-factor authentication', [], 'Modules.Mpadmin2fa.Admin'),
            'message_prefix' => $this->trans(
                '%not_enrolled% out of your %total% employees are not enrolled in the',
                [
                    '%not_enrolled%' => (string) $summary['not_enrolled'],
                    '%total%' => (string) $summary['total'],
                ],
                'Modules.Mpadmin2fa.Admin'
            ),
            'enrollment_link_label' => $this->trans(
                'two factor system',
                [],
                'Modules.Mpadmin2fa.Admin'
            ),
            'enrollment_url' => $this->context->link->getAdminLink('AdminMpAdmin2faEnrollment'),
            'has_unenrolled' => $summary['not_enrolled'] > 0,
            'can_view_security_activity' => $canViewSecurityActivity,
            'security_activity_title' => $this->trans(
                'Important security activity from the last %hours% hours',
                ['%hours%' => (string) $windowHours],
                'Modules.Mpadmin2fa.Admin'
            ),
            'security_activity_empty' => $this->trans(
                'No important security events occurred during this period.',
                [],
                'Modules.Mpadmin2fa.Admin'
            ),
            'security_activity_see_all' => $this->trans(
                'See all',
                [],
                'Modules.Mpadmin2fa.Admin'
            ),
            'security_activity_url' => $this->get('router')->generate('mpadmin2fa_security_activity'),
            'security_event_column_label' => $this->trans(
                'Security event',
                [],
                'Modules.Mpadmin2fa.Admin'
            ),
            'security_occurrences_column_label' => $this->trans(
                'Occurrences',
                [],
                'Modules.Mpadmin2fa.Admin'
            ),
            'security_window_hours' => $windowHours,
            'security_events' => $securityEvents,
        ];
    }

    /**
     * @return string[]
     */
    private function configurationKeys(): array
    {
        return [
            Mpadmin2fa\Security\Policy::CONFIG_APPROVAL_PROFILES,
            Mpadmin2fa\Security\Policy::CONFIG_AUDIT_DAYS,
            Mpadmin2fa\Security\Policy::CONFIG_MODE,
            Mpadmin2fa\Security\Policy::CONFIG_PASSWORD_MAX_AGE,
            Mpadmin2fa\Security\Policy::CONFIG_PROFILES,
            Mpadmin2fa\Security\Policy::CONFIG_SECURITY_RECIPIENTS,
            Mpadmin2fa\Security\Policy::CONFIG_STEP_UP_SECONDS,
        ];
    }

    private function deleteConfiguration(): bool
    {
        $cleaned = true;
        foreach ($this->configurationKeys() as $key) {
            $cleaned = Configuration::deleteByName($key) && $cleaned;
        }

        return $cleaned;
    }

    private function installConfiguration(): bool
    {
        return Configuration::updateValue(Mpadmin2fa\Security\Policy::CONFIG_MODE, 'superadmins')
            && Configuration::updateValue(Mpadmin2fa\Security\Policy::CONFIG_PROFILES, '')
            && Configuration::updateValue(Mpadmin2fa\Security\Policy::CONFIG_STEP_UP_SECONDS, 300)
            && Configuration::updateValue(Mpadmin2fa\Security\Policy::CONFIG_PASSWORD_MAX_AGE, 900)
            && Configuration::updateValue(Mpadmin2fa\Security\Policy::CONFIG_AUDIT_DAYS, 90)
            && Configuration::updateValue(Mpadmin2fa\Security\Policy::CONFIG_APPROVAL_PROFILES, '')
            && Configuration::updateValue(Mpadmin2fa\Security\Policy::CONFIG_SECURITY_RECIPIENTS, '');
    }

    private function registerRequiredHooks(): bool
    {
        foreach ([
            'actionAdminControllerSetMedia',
            'actionDispatcherBefore',
            'actionObjectProfileDeleteAfter',
            'dashboardZoneOne',
            'displayAdminDashboardZoneOne',
            ThemeCatalogInterface::LIST_MAIL_THEMES_HOOK,
        ] as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }

    private function rollbackInstall(bool $parentAttempted): void
    {
        try {
            (new AdminTabLifecycle())->remove($this->name, $this->declaredTabs);
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();
        }
        try {
            $this->deleteConfiguration();
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();
        }
        try {
            (new SchemaInstaller())->uninstall();
        } catch (Throwable $exception) {
            $this->_errors[] = $exception->getMessage();
        }
        if ($parentAttempted && (int) $this->id > 0) {
            try {
                parent::uninstall();
            } catch (Throwable $exception) {
                $this->_errors[] = $exception->getMessage();
            }
        }
    }
}
