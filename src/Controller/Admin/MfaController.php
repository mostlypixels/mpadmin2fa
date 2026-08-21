<?php

declare(strict_types=1);

namespace Mpadmin2fa\Controller\Admin;

use Mpadmin2fa\Exception\MfaSecurityException;
use Mpadmin2fa\Repository\SecurityRepository;
use Mpadmin2fa\Security\MfaManager;
use Mpadmin2fa\Security\Policy;
use Mpadmin2fa\Security\SessionState;
use Mpadmin2fa\Security\TotpService;
use PrestaShop\PrestaShop\Core\ConfigurationInterface;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Entity\Employee\Employee;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use PrestaShopBundle\Security\Attribute\DemoRestricted;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;

final class MfaController extends PrestaShopAdminController
{
    public function __construct(
        private readonly Security $security,
        private readonly RouterInterface $router,
        private readonly MfaManager $mfa,
        private readonly TotpService $totp,
        private readonly SessionState $sessionState,
        private readonly Policy $policy,
        private readonly SecurityRepository $repository,
        private readonly ConfigurationInterface $configuration,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function challenge(Request $request): Response
    {
        $employee = $this->employee();
        if (!$this->mfa->active($employee->getId())) {
            return $this->redirectToRoute('mpadmin2fa_enroll');
        }

        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mp2fa_challenge', (string) $request->request->get('mp2fa_csrf_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            try {
                $recoveryCode = trim((string) $request->request->get('recovery_code'));
                if ('' !== $recoveryCode) {
                    if ($this->mfa->useRecoveryCode($employee->getId(), $recoveryCode, $request->getClientIp())) {
                        $this->sessionState->markVerified($employee->getId(), true);

                        return $this->redirectToRoute('mpadmin2fa_enroll');
                    }
                } elseif ($this->mfa->verifyTotp(
                    $employee->getId(),
                    (string) $request->request->get('code'),
                    $request->getClientIp(),
                    $request->query->getBoolean('step_up') ? 'step_up' : 'challenge'
                )) {
                    $this->sessionState->markVerified($employee->getId());

                    return new RedirectResponse($this->sessionState->consumeReturnTarget()
                        ?? $this->dashboardUrl());
                }
                $error = 'The supplied authentication code is invalid or has already been used.';
            } catch (MfaSecurityException|RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }

        return $this->render('@Modules/mpadmin2fa/views/templates/admin/challenge.html.twig', [
            'error' => $error,
            'layoutTitle' => 'Two-factor authentication',
            'step_up' => $request->query->getBoolean('step_up'),
        ]);
    }

    public function enroll(Request $request): Response
    {
        $this->requireHttps($request);
        $employee = $this->employee();
        $recoveryReplacement = $this->sessionState->isRecoveryRestricted($employee->getId());
        $authorizedReplacement = $recoveryReplacement
            || $this->sessionState->isEnrollmentReplacementAuthorized($employee->getId());

        if (!$authorizedReplacement
            && $this->policy->requiresEnrollmentApproval($employee)
            && $this->repository->hasActiveSuperAdminFactor(defined('_PS_ADMIN_PROFILE_') ? (int) _PS_ADMIN_PROFILE_ : 1)
            && 'approved' !== $this->repository->enrollmentApprovalStatus($employee->getId())
        ) {
            $this->repository->requestEnrollmentApproval($employee->getId());

            return $this->render('@Modules/mpadmin2fa/views/templates/admin/approval_pending.html.twig', [
                'layoutTitle' => 'Enrollment approval required',
            ]);
        }

        if ($this->mfa->active($employee->getId()) && !$recoveryReplacement) {
            $this->addFlash('info', 'Your authenticator is already active.');

            return $this->redirectToRoute('mpadmin2fa_authenticator');
        }

        try {
            try {
                $secret = $this->mfa->pendingSecret($employee->getId());
            } catch (MfaSecurityException) {
                $secret = $this->mfa->beginEnrollment($employee->getId());
            }

            if ($request->isMethod('POST')) {
                if (!$this->isCsrfTokenValid('mp2fa_enroll', (string) $request->request->get('mp2fa_csrf_token'))) {
                    throw $this->createAccessDeniedException('Invalid CSRF token.');
                }
                $codes = $this->mfa->confirmEnrollment(
                    $employee->getId(),
                    (string) $request->request->get('code'),
                    $request->getClientIp()
                );
                $request->getSession()->set('mp2fa_recovery_plaintext', $codes);
                $this->sessionState->markVerified($employee->getId());
                $this->sessionState->clearEnrollmentReplacementAuthorization();

                return $this->redirectToRoute('mpadmin2fa_recovery_codes');
            }
        } catch (MfaSecurityException|RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('mpadmin2fa_enroll');
        }

        $uri = $this->totp->provisioningUri('PrestaShop Admin', $employee->getEmail(), $secret);

        return $this->render('@Modules/mpadmin2fa/views/templates/admin/enroll.html.twig', [
            'layoutTitle' => 'Set up two-factor authentication',
            'secret' => $secret,
            'qr_data_uri' => $this->totp->qrDataUri($uri),
        ]);
    }

    public function recoveryCodes(Request $request): Response
    {
        $employee = $this->employee();
        $codes = $request->getSession()->get('mp2fa_recovery_plaintext');
        if (!is_array($codes)) {
            return $this->redirectToRoute('mpadmin2fa_authenticator');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('mp2fa_recovery_ack', (string) $request->request->get('mp2fa_csrf_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }
            if ('1' !== $request->request->get('saved')) {
                $this->addFlash('error', 'Confirm that the recovery codes were saved before continuing.');

                return $this->redirectToRoute('mpadmin2fa_recovery_codes');
            }
            $request->getSession()->remove('mp2fa_recovery_plaintext');

            return new RedirectResponse($this->dashboardUrl());
        }

        return $this->render('@Modules/mpadmin2fa/views/templates/admin/recovery_codes.html.twig', [
            'layoutTitle' => 'Save your recovery codes',
            'recovery_codes' => $codes,
        ]);
    }

    public function replace(Request $request): Response
    {
        $employee = $this->employee();
        $this->requirePostAndCsrf($request, 'mp2fa_replace');

        if (!$this->passwordStepSatisfied($employee, $request)
            || !$this->mfa->verifyTotp($employee->getId(), (string) $request->request->get('code'), $request->getClientIp(), 'factor_change')
        ) {
            $this->addFlash('error', 'The password or authentication code was invalid.');

            return $this->redirectToRoute('mpadmin2fa_authenticator');
        }

        $this->sessionState->authorizeEnrollmentReplacement();
        $this->mfa->beginEnrollment($employee->getId());
        $this->sessionState->markVerified($employee->getId());

        return $this->redirectToRoute('mpadmin2fa_enroll');
    }

    public function disable(Request $request): Response
    {
        $employee = $this->employee();
        $this->requirePostAndCsrf($request, 'mp2fa_disable');
        if ($this->policy->requiresLoginMfa($employee)) {
            $this->addFlash('error', 'Policy requires two-factor authentication for your account.');

            return $this->redirectToRoute('mpadmin2fa_authenticator');
        }

        if (!$this->passwordStepSatisfied($employee, $request)
            || !$this->mfa->verifyTotp($employee->getId(), (string) $request->request->get('code'), $request->getClientIp(), 'factor_change')
        ) {
            $this->addFlash('error', 'The password or authentication code was invalid.');

            return $this->redirectToRoute('mpadmin2fa_authenticator');
        }

        $this->mfa->reset($employee->getId(), $employee->getId(), $request->getClientIp(), 'self-disable');
        $this->sessionState->clear();

        return $this->redirectToRoute('admin_logout');
    }

    public function regenerateRecoveryCodes(Request $request): Response
    {
        $employee = $this->employee();
        $this->requirePostAndCsrf($request, 'mp2fa_recovery_regenerate');

        try {
            if (!$this->passwordStepSatisfied($employee, $request)
                || !$this->mfa->verifyTotp(
                    $employee->getId(),
                    (string) $request->request->get('code'),
                    $request->getClientIp(),
                    'factor_change'
                )
            ) {
                $this->addFlash('error', 'The password or authentication code was invalid.');

                return $this->redirectToRoute('mpadmin2fa_authenticator');
            }
            $codes = $this->mfa->regenerateRecoveryCodes($employee->getId(), $request->getClientIp());
        } catch (RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('mpadmin2fa_authenticator');
        }

        $request->getSession()->set('mp2fa_recovery_plaintext', $codes);
        $this->sessionState->markVerified($employee->getId());

        return $this->redirectToRoute('mpadmin2fa_recovery_codes');
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    public function settings(): Response
    {
        foreach ([
            'AdminMpAdmin2faAuthenticator' => 'mpadmin2fa_authenticator',
            'AdminMpAdmin2faEnrollment' => 'mpadmin2fa_enrollment_employees',
            'AdminMpAdmin2faSecurity' => 'mpadmin2fa_security_policy',
        ] as $legacyController => $routeName) {
            if ($this->isGranted('read', $legacyController)) {
                return $this->redirectToRoute($routeName);
            }
        }

        throw $this->createAccessDeniedException('No Admin 2FA section is available for your profile.');
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    public function authenticator(): Response
    {
        $employee = $this->employee();

        return $this->render('@Modules/mpadmin2fa/views/templates/admin/authenticator.html.twig', [
            'layoutTitle' => 'Your authenticator',
            'active' => $this->mfa->active($employee->getId()),
            'password_required' => !$this->nativePasswordAuthenticationFresh($employee),
        ]);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    public function enrollmentEmployees(): Response
    {
        $employee = $this->employee();

        return $this->render('@Modules/mpadmin2fa/views/templates/admin/enrollment/employees.html.twig', [
            'layoutTitle' => 'Enrollment',
            'employees' => $this->repository->employeeStatuses(
                $employee->getDefaultLanguage()?->getId() ?? (int) $this->configuration->get('PS_LANG_DEFAULT')
            ),
            'can_reset' => $this->isGranted('delete', 'AdminMpAdmin2faEnrollment'),
        ]);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    public function enrollmentApprovals(): Response
    {
        return $this->render('@Modules/mpadmin2fa/views/templates/admin/enrollment/approvals.html.twig', [
            'layoutTitle' => 'Enrollment',
            'pending_approvals' => $this->repository->pendingApprovals(),
            'can_approve' => $this->isGranted('update', 'AdminMpAdmin2faEnrollment'),
        ]);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    public function securityPolicy(): Response
    {
        return $this->render('@Modules/mpadmin2fa/views/templates/admin/security/policy.html.twig', [
            'layoutTitle' => 'Security',
            'mode' => (string) ($this->configuration->get(Policy::CONFIG_MODE) ?: 'superadmins'),
            'profiles' => (string) $this->configuration->get(Policy::CONFIG_PROFILES),
            'step_up_seconds' => $this->policy->stepUpSeconds(),
            'password_max_age' => $this->policy->passwordMaximumAge(),
            'audit_days' => $this->policy->auditDays(),
            'approval_profiles' => (string) $this->configuration->get(Policy::CONFIG_APPROVAL_PROFILES),
            'security_recipients' => (string) $this->configuration->get(Policy::CONFIG_SECURITY_RECIPIENTS),
            'can_update' => $this->isGranted('update', 'AdminMpAdmin2faSecurity'),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    #[DemoRestricted(redirectRoute: 'mpadmin2fa_security_policy')]
    public function updateSecurityPolicy(Request $request): Response
    {
        $employee = $this->employee();
        $this->assertFreshVerification($employee);
        $this->requirePostAndCsrf($request, 'mp2fa_policy');

        $mode = (string) $request->request->get('mode');
        if (!in_array($mode, ['superadmins', 'profiles', 'all'], true)) {
            throw $this->createAccessDeniedException('Invalid enforcement mode.');
        }

        $profiles = implode(',', array_unique(array_filter(array_map(
            'intval',
            preg_split('/[^0-9]+/', (string) $request->request->get('profiles')) ?: []
        ))));
        $this->configuration->set(Policy::CONFIG_MODE, $mode);
        $this->configuration->set(Policy::CONFIG_PROFILES, $profiles);
        $this->configuration->set(Policy::CONFIG_STEP_UP_SECONDS, max(60, (int) $request->request->get('step_up_seconds')));
        $this->configuration->set(Policy::CONFIG_PASSWORD_MAX_AGE, max(60, (int) $request->request->get('password_max_age')));
        $this->configuration->set(Policy::CONFIG_AUDIT_DAYS, max(1, (int) $request->request->get('audit_days')));
        $this->configuration->set(Policy::CONFIG_APPROVAL_PROFILES, implode(',', array_unique(array_filter(array_map(
            'intval',
            preg_split('/[^0-9]+/', (string) $request->request->get('approval_profiles')) ?: []
        )))));
        $this->configuration->set(Policy::CONFIG_SECURITY_RECIPIENTS, trim((string) $request->request->get('security_recipients')));
        $this->repository->audit($employee->getId(), 'policy.updated', $request->getClientIp());
        $this->addFlash('success', 'Two-factor authentication policy updated.');

        return $this->redirectToRoute('mpadmin2fa_security_policy');
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    public function securityActivity(): Response
    {
        return $this->render('@Modules/mpadmin2fa/views/templates/admin/security/activity.html.twig', [
            'layoutTitle' => 'Security',
            'audit_events' => $this->repository->auditEvents(),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    #[DemoRestricted(redirectRoute: 'mpadmin2fa_enrollment_approvals')]
    public function approveEnrollment(Request $request, int $employeeId): Response
    {
        $actor = $this->employee();
        $this->assertFreshVerification($actor);
        $this->requirePostAndCsrf($request, 'mp2fa_approve_' . $employeeId);
        if ($actor->getId() === $employeeId) {
            throw $this->createAccessDeniedException('An employee cannot approve their own enrollment.');
        }

        $this->repository->approveEnrollment($employeeId, $actor->getId());
        $this->repository->audit($actor->getId(), 'enrollment.approved', $request->getClientIp(), [
            'target_employee_id' => $employeeId,
        ]);
        $this->addFlash('success', 'Enrollment approved.');

        return $this->redirectToRoute('mpadmin2fa_enrollment_approvals');
    }

    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    #[DemoRestricted(redirectRoute: 'mpadmin2fa_enrollment_employees')]
    public function adminReset(Request $request, int $employeeId): Response
    {
        $actor = $this->employee();
        $this->assertFreshVerification($actor);
        $this->requirePostAndCsrf($request, 'mp2fa_admin_reset_' . $employeeId);
        if ($actor->getId() === $employeeId) {
            throw $this->createAccessDeniedException('An employee cannot use the administrative reset on their own factor.');
        }

        $this->mfa->reset($employeeId, $actor->getId(), $request->getClientIp(), 'superadmin-reset');
        $this->addFlash('success', 'The employee factor was reset.');

        return $this->redirectToRoute('mpadmin2fa_enrollment_employees');
    }

    private function passwordStepSatisfied(Employee $employee, Request $request): bool
    {
        if ($this->nativePasswordAuthenticationFresh($employee)) {
            return true;
        }

        return $this->passwordHasher->isPasswordValid($employee, (string) $request->request->get('password'));
    }

    private function nativePasswordAuthenticationFresh(Employee $employee): bool
    {
        $authenticatedAt = $this->sessionState->authenticatedAt($employee->getId());

        return is_int($authenticatedAt) && $authenticatedAt >= time() - $this->policy->passwordMaximumAge();
    }

    private function dashboardUrl(): string
    {
        foreach (['admin_dashboard_index', 'admin_dashboard'] as $routeName) {
            if (null !== $this->router->getRouteCollection()->get($routeName)) {
                return $this->router->generate($routeName);
            }
        }

        throw new RuntimeException('No supported PrestaShop dashboard route is available.');
    }

    private function employee(): Employee
    {
        $employee = $this->security->getUser();
        if (!$employee instanceof Employee) {
            throw $this->createAccessDeniedException();
        }

        return $employee;
    }

    private function requireHttps(Request $request): void
    {
        if (!$request->isSecure()) {
            throw $this->createAccessDeniedException('HTTPS is required for authenticator enrollment.');
        }
    }

    private function requirePostAndCsrf(Request $request, string $tokenId): void
    {
        if (!$request->isMethod('POST')
            || !$this->isCsrfTokenValid($tokenId, (string) $request->request->get('mp2fa_csrf_token'))
        ) {
            throw $this->createAccessDeniedException('Invalid request.');
        }
    }

    private function assertFreshVerification(Employee $employee): void
    {
        if (!$this->sessionState->hasFreshVerification($employee->getId(), $this->policy->stepUpSeconds())) {
            throw $this->createAccessDeniedException('A fresh two-factor authentication verification is required.');
        }
    }
}
