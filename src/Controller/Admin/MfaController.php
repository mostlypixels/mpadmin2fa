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
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Admin\Employee;
use RuntimeException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Routing\RouterInterface;

final class MfaController extends FrameworkBundleAdminController
{
    /** @var TokenStorageInterface */
    private $tokenStorage;

    /** @var RouterInterface */
    private $router;

    /** @var MfaManager */
    private $mfa;

    /** @var TotpService */
    private $totp;

    /** @var SessionState */
    private $sessionState;

    /** @var Policy */
    private $policy;

    /** @var SecurityRepository */
    private $repository;

    /** @var ConfigurationInterface */
    private $moduleConfiguration;

    /** @var UserPasswordEncoderInterface */
    private $passwordEncoder;

    public function __construct(
        TokenStorageInterface $tokenStorage,
        RouterInterface $router,
        MfaManager $mfa,
        TotpService $totp,
        SessionState $sessionState,
        Policy $policy,
        SecurityRepository $repository,
        ConfigurationInterface $moduleConfiguration,
        UserPasswordEncoderInterface $passwordEncoder)
    {
        $this->tokenStorage = $tokenStorage;
        $this->router = $router;
        $this->mfa = $mfa;
        $this->totp = $totp;
        $this->sessionState = $sessionState;
        $this->policy = $policy;
        $this->repository = $repository;
        $this->moduleConfiguration = $moduleConfiguration;
        $this->passwordEncoder = $passwordEncoder;
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

            return $this->redirectToRoute('mpadmin2fa_settings');
        }

        try {
            try {
                $secret = $this->mfa->pendingSecret($employee->getId());
            } catch (MfaSecurityException $exception) {
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

        $uri = $this->totp->provisioningUri('PrestaShop Admin', $employee->getUsername(), $secret);

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
            return $this->redirectToRoute('mpadmin2fa_settings');
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

            return $this->redirectToRoute('mpadmin2fa_settings');
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

            return $this->redirectToRoute('mpadmin2fa_settings');
        }

        if (!$this->passwordStepSatisfied($employee, $request)
            || !$this->mfa->verifyTotp($employee->getId(), (string) $request->request->get('code'), $request->getClientIp(), 'factor_change')
        ) {
            $this->addFlash('error', 'The password or authentication code was invalid.');

            return $this->redirectToRoute('mpadmin2fa_settings');
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

                return $this->redirectToRoute('mpadmin2fa_settings');
            }
            $codes = $this->mfa->regenerateRecoveryCodes($employee->getId(), $request->getClientIp());
        } catch (RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('mpadmin2fa_settings');
        }

        $request->getSession()->set('mp2fa_recovery_plaintext', $codes);
        $this->sessionState->markVerified($employee->getId());

        return $this->redirectToRoute('mpadmin2fa_recovery_codes');
    }

    public function settings(Request $request): Response
    {
        $employee = $this->employee();
        if ($request->isMethod('POST')) {
            $this->assertFreshSuperAdmin($employee);
            $this->requirePostAndCsrf($request, 'mp2fa_settings');

            $mode = (string) $request->request->get('mode');
            if (!in_array($mode, ['superadmins', 'profiles', 'all'], true)) {
                throw $this->createAccessDeniedException('Invalid enforcement mode.');
            }

            $profiles = implode(',', array_unique(array_filter(array_map(
                'intval',
                preg_split('/[^0-9]+/', (string) $request->request->get('profiles')) ?: []
            ))));
            $this->moduleConfiguration->set(Policy::CONFIG_MODE, $mode);
            $this->moduleConfiguration->set(Policy::CONFIG_PROFILES, $profiles);
            $this->moduleConfiguration->set(Policy::CONFIG_STEP_UP_SECONDS, max(60, (int) $request->request->get('step_up_seconds')));
            $this->moduleConfiguration->set(Policy::CONFIG_PASSWORD_MAX_AGE, max(60, (int) $request->request->get('password_max_age')));
            $this->moduleConfiguration->set(Policy::CONFIG_AUDIT_DAYS, max(1, (int) $request->request->get('audit_days')));
            $this->moduleConfiguration->set(Policy::CONFIG_APPROVAL_PROFILES, implode(',', array_unique(array_filter(array_map(
                'intval',
                preg_split('/[^0-9]+/', (string) $request->request->get('approval_profiles')) ?: []
            )))));
            $this->moduleConfiguration->set(Policy::CONFIG_SECURITY_RECIPIENTS, trim((string) $request->request->get('security_recipients')));
            $this->repository->audit($employee->getId(), 'policy.updated', $request->getClientIp());
            $this->addFlash('success', 'Two-factor authentication policy updated.');

            return $this->redirectToRoute('mpadmin2fa_settings');
        }

        return $this->render('@Modules/mpadmin2fa/views/templates/admin/settings.html.twig', [
            'layoutTitle' => 'Admin 2FA',
            'active' => $this->mfa->active($employee->getId()),
            'is_superadmin' => $this->isSuperAdmin($employee),
            'mode' => (string) ($this->moduleConfiguration->get(Policy::CONFIG_MODE) ?: 'superadmins'),
            'profiles' => (string) $this->moduleConfiguration->get(Policy::CONFIG_PROFILES),
            'step_up_seconds' => $this->policy->stepUpSeconds(),
            'password_max_age' => $this->policy->passwordMaximumAge(),
            'password_required' => !$this->nativePasswordAuthenticationFresh($employee),
            'audit_days' => $this->policy->auditDays(),
            'approval_profiles' => (string) $this->moduleConfiguration->get(Policy::CONFIG_APPROVAL_PROFILES),
            'pending_approvals' => $this->isSuperAdmin($employee) ? $this->repository->pendingApprovals() : [],
            'security_recipients' => (string) $this->moduleConfiguration->get(Policy::CONFIG_SECURITY_RECIPIENTS),
            'employees' => $this->isSuperAdmin($employee) ? $this->repository->employeeStatuses() : [],
            'audit_events' => $this->isSuperAdmin($employee) ? $this->repository->auditEvents() : [],
        ]);
    }

    public function approveEnrollment(Request $request, int $employeeId): Response
    {
        $actor = $this->employee();
        $this->assertFreshSuperAdmin($actor);
        $this->requirePostAndCsrf($request, 'mp2fa_approve_' . $employeeId);
        if ($actor->getId() === $employeeId) {
            throw $this->createAccessDeniedException('A SuperAdmin cannot approve their own enrollment.');
        }

        $this->repository->approveEnrollment($employeeId, $actor->getId());
        $this->repository->audit($actor->getId(), 'enrollment.approved', $request->getClientIp(), [
            'target_employee_id' => $employeeId,
        ]);
        $this->addFlash('success', 'Enrollment approved.');

        return $this->redirectToRoute('mpadmin2fa_settings');
    }

    public function adminReset(Request $request, int $employeeId): Response
    {
        $actor = $this->employee();
        $this->assertFreshSuperAdmin($actor);
        $this->requirePostAndCsrf($request, 'mp2fa_admin_reset_' . $employeeId);
        if ($actor->getId() === $employeeId) {
            throw $this->createAccessDeniedException('A SuperAdmin cannot use the administrative reset on their own factor.');
        }

        $this->mfa->reset($employeeId, $actor->getId(), $request->getClientIp(), 'superadmin-reset');
        $this->addFlash('success', 'The employee factor was reset.');

        return $this->redirectToRoute('mpadmin2fa_settings');
    }

    private function passwordStepSatisfied(Employee $employee, Request $request): bool
    {
        if ($this->nativePasswordAuthenticationFresh($employee)) {
            return true;
        }

        return $this->passwordEncoder->isPasswordValid($employee, (string) $request->request->get('password'));
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
        $token = $this->tokenStorage->getToken();
        $employee = null !== $token ? $token->getUser() : null;
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

    private function assertFreshSuperAdmin(Employee $employee): void
    {
        if (!$this->isSuperAdmin($employee)
            || !$this->sessionState->hasFreshVerification($employee->getId(), $this->policy->stepUpSeconds())
        ) {
            throw $this->createAccessDeniedException('A fresh SuperAdmin verification is required.');
        }
    }

    private function isSuperAdmin(Employee $employee): bool
    {
        return (int) $employee->getData()->id_profile === (defined('_PS_ADMIN_PROFILE_') ? (int) _PS_ADMIN_PROFILE_ : 1);
    }
}
