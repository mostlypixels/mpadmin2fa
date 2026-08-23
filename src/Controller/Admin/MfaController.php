<?php

declare(strict_types=1);

namespace Mpadmin2fa\Controller\Admin;

use Mpadmin2fa\Exception\MfaSecurityException;
use Mpadmin2fa\Form\DisableFactorType;
use Mpadmin2fa\Form\OneTimeCodeType;
use Mpadmin2fa\Form\RecoveryCodeAcknowledgementType;
use Mpadmin2fa\Form\RecoveryCodeChallengeType;
use Mpadmin2fa\Form\RegenerateRecoveryCodesType;
use Mpadmin2fa\Form\ReplaceFactorType;
use Mpadmin2fa\Grid\Filters\AuditEventFilters;
use Mpadmin2fa\Grid\Filters\EmployeeFactorFilters;
use Mpadmin2fa\Grid\Filters\PendingApprovalFilters;
use Mpadmin2fa\Repository\SecurityRepository;
use Mpadmin2fa\Security\FactorConfirmationService;
use Mpadmin2fa\Security\MfaManager;
use Mpadmin2fa\Security\Policy;
use Mpadmin2fa\Security\SecurityAlertCatalog;
use Mpadmin2fa\Security\SessionState;
use Mpadmin2fa\Security\TotpService;
use PrestaShop\PrestaShop\Core\Form\FormHandlerInterface;
use PrestaShop\PrestaShop\Core\Grid\GridFactoryInterface;
use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use PrestaShopBundle\Entity\Employee\Employee;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use PrestaShopBundle\Security\Attribute\DemoRestricted;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class MfaController extends PrestaShopAdminController
{
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function challenge(
        Request $request,
        Security $security,
        MfaManager $mfa,
        SessionState $sessionState,
        RouterInterface $router,
    ): Response {
        $employee = $this->employee($security);
        if (!$mfa->active($employee->getId())) {
            return $this->redirectToRoute('mpadmin2fa_enroll');
        }

        $totpForm = $this->createForm(OneTimeCodeType::class, null, [
            'autofocus' => true,
            'csrf_token_id' => 'mp2fa_challenge_totp',
        ]);
        $recoveryForm = $this->createForm(RecoveryCodeChallengeType::class);
        $totpForm->handleRequest($request);
        $recoveryForm->handleRequest($request);

        try {
            if ($recoveryForm->isSubmitted() && $recoveryForm->isValid()) {
                if ($mfa->useRecoveryCode(
                    $employee->getId(),
                    (string) $recoveryForm->getData()['recovery_code'],
                    $request->getClientIp()
                )) {
                    $sessionState->markVerified($employee->getId(), true);

                    return $this->redirectToRoute('mpadmin2fa_enroll');
                }

                $this->addFlash('error', 'That recovery code is incorrect or has already been used.');
            }

            if ($totpForm->isSubmitted() && $totpForm->isValid()) {
                if ($mfa->verifyTotp(
                    $employee->getId(),
                    (string) $totpForm->getData()['code'],
                    $request->getClientIp(),
                    $request->query->getBoolean('step_up') ? 'step_up' : 'challenge'
                )) {
                    $sessionState->markVerified($employee->getId());

                    return new RedirectResponse($sessionState->consumeReturnTarget()
                        ?? $this->dashboardUrl($router));
                }

                $this->addFlash('error', 'That authenticator code is incorrect or has already been used.');
            }
        } catch (MfaSecurityException|RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->render('@Modules/mpadmin2fa/views/templates/admin/challenge.html.twig', [
            'layoutTitle' => 'Two-factor authentication',
            'recovery_form' => $recoveryForm->createView(),
            'step_up' => $request->query->getBoolean('step_up'),
            'totp_form' => $totpForm->createView(),
        ]);
    }

    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function enroll(
        Request $request,
        Security $security,
        MfaManager $mfa,
        TotpService $totp,
        SessionState $sessionState,
        Policy $policy,
        SecurityRepository $repository,
    ): Response {
        $this->requireHttps($request);
        $employee = $this->employee($security);
        $recoveryReplacement = $sessionState->isRecoveryRestricted($employee->getId());
        $authorizedReplacement = $recoveryReplacement
            || $sessionState->isEnrollmentReplacementAuthorized($employee->getId());

        if (!$authorizedReplacement
            && $policy->requiresEnrollmentApproval($employee)
            && $repository->hasActiveSuperAdminFactor(defined('_PS_ADMIN_PROFILE_') ? (int) _PS_ADMIN_PROFILE_ : 1)
            && 'approved' !== $repository->enrollmentApprovalStatus($employee->getId())
        ) {
            $repository->requestEnrollmentApproval($employee->getId());

            return $this->render('@Modules/mpadmin2fa/views/templates/admin/approval_pending.html.twig', [
                'layoutTitle' => 'Waiting for approval',
            ]);
        }

        if ($mfa->active($employee->getId()) && !$recoveryReplacement) {
            $this->addFlash('info', 'Your authenticator is already active.');

            return $this->redirectToRoute('mpadmin2fa_authenticator');
        }

        try {
            try {
                $secret = $mfa->pendingSecret($employee->getId());
            } catch (MfaSecurityException) {
                $secret = $mfa->beginEnrollment($employee->getId());
            }

            $form = $this->createForm(OneTimeCodeType::class, null, [
                'code_label' => 'Six-digit code',
                'csrf_token_id' => 'mp2fa_enroll',
                'submit_label' => 'Confirm and activate',
            ]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $codes = $mfa->confirmEnrollment(
                    $employee->getId(),
                    (string) $form->getData()['code'],
                    $request->getClientIp()
                );
                $request->getSession()->set('mp2fa_recovery_plaintext', $codes);
                $sessionState->markVerified($employee->getId());
                $sessionState->clearEnrollmentReplacementAuthorization();

                return $this->redirectToRoute('mpadmin2fa_recovery_codes');
            }
        } catch (MfaSecurityException|RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('mpadmin2fa_enroll');
        }

        $uri = $totp->provisioningUri('PrestaShop Admin', $employee->getEmail(), $secret);

        return $this->render('@Modules/mpadmin2fa/views/templates/admin/enroll.html.twig', [
            'confirmation_form' => $form->createView(),
            'layoutTitle' => 'Set up two-factor authentication',
            'qr_data_uri' => $totp->qrDataUri($uri),
            'secret' => $secret,
        ]);
    }

    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function recoveryCodes(Request $request, RouterInterface $router): Response
    {
        $codes = $request->getSession()->get('mp2fa_recovery_plaintext');
        if (!is_array($codes)) {
            return $this->redirectToRoute('mpadmin2fa_authenticator');
        }

        $form = $this->createForm(RecoveryCodeAcknowledgementType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $request->getSession()->remove('mp2fa_recovery_plaintext');

            return new RedirectResponse($this->dashboardUrl($router));
        }

        return $this->render('@Modules/mpadmin2fa/views/templates/admin/recovery_codes.html.twig', [
            'acknowledgement_form' => $form->createView(),
            'layoutTitle' => 'Save your recovery codes',
            'recovery_codes' => $codes,
        ]);
    }

    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function replace(
        Request $request,
        Security $security,
        FactorConfirmationService $confirmation,
        MfaManager $mfa,
        SessionState $sessionState,
    ): Response {
        $employee = $this->employee($security);
        $form = $this->createForm(ReplaceFactorType::class, null, [
            'password_required' => $confirmation->passwordRequired($employee),
        ]);
        $form->handleRequest($request);

        try {
            if (!$form->isSubmitted() || !$form->isValid()
                || !$confirmation->verify($employee, $form->getData(), $request->getClientIp())
            ) {
                return $this->renderAuthenticator($employee, $confirmation, ['replace_form' => $form]);
            }

            $sessionState->authorizeEnrollmentReplacement();
            $mfa->beginEnrollment($employee->getId());
            $sessionState->markVerified($employee->getId());

            return $this->redirectToRoute('mpadmin2fa_enroll');
        } catch (MfaSecurityException|RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->renderAuthenticator($employee, $confirmation, ['replace_form' => $form]);
        }
    }

    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function disable(
        Request $request,
        Security $security,
        FactorConfirmationService $confirmation,
        Policy $policy,
        MfaManager $mfa,
        SessionState $sessionState,
    ): Response {
        $employee = $this->employee($security);
        if ($policy->requiresLoginMfa($employee)) {
            $this->addFlash('error', "Your shop's security settings require two-factor authentication, so you cannot turn it off.");

            return $this->redirectToRoute('mpadmin2fa_authenticator');
        }

        $form = $this->createForm(DisableFactorType::class, null, [
            'password_required' => $confirmation->passwordRequired($employee),
        ]);
        $form->handleRequest($request);

        try {
            if (!$form->isSubmitted() || !$form->isValid()
                || !$confirmation->verify($employee, $form->getData(), $request->getClientIp())
            ) {
                return $this->renderAuthenticator($employee, $confirmation, ['disable_form' => $form]);
            }

            $mfa->reset($employee->getId(), $employee->getId(), $request->getClientIp(), 'self-disable');
            $sessionState->clear();

            return $this->redirectToRoute('admin_logout');
        } catch (MfaSecurityException|RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->renderAuthenticator($employee, $confirmation, ['disable_form' => $form]);
        }
    }

    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function regenerateRecoveryCodes(
        Request $request,
        Security $security,
        FactorConfirmationService $confirmation,
        MfaManager $mfa,
        SessionState $sessionState,
    ): Response {
        $employee = $this->employee($security);
        $form = $this->createForm(RegenerateRecoveryCodesType::class, null, [
            'password_required' => $confirmation->passwordRequired($employee),
        ]);
        $form->handleRequest($request);

        try {
            if (!$form->isSubmitted() || !$form->isValid()
                || !$confirmation->verify($employee, $form->getData(), $request->getClientIp())
            ) {
                return $this->renderAuthenticator($employee, $confirmation, ['recovery_form' => $form]);
            }

            $codes = $mfa->regenerateRecoveryCodes($employee->getId(), $request->getClientIp());
            $request->getSession()->set('mp2fa_recovery_plaintext', $codes);
            $sessionState->markVerified($employee->getId());

            return $this->redirectToRoute('mpadmin2fa_recovery_codes');
        } catch (MfaSecurityException|RuntimeException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->renderAuthenticator($employee, $confirmation, ['recovery_form' => $form]);
        }
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    public function settings(
        Request $request,
        Security $security,
        MfaManager $mfa,
        SecurityAlertCatalog $alertCatalog,
    ): Response {
        $employee = $this->employee($security);

        return $this->render('@Modules/mpadmin2fa/views/templates/admin/settings.html.twig', [
            'layoutTitle' => 'Admin 2FA',
            'authenticator_active' => $mfa->active($employee->getId()),
            'can_open_authenticator' => $this->isGranted('read', 'AdminMpAdmin2faAuthenticator'),
            'can_open_enrollment' => $this->isGranted('read', 'AdminMpAdmin2faEnrollment'),
            'can_open_security' => $this->isGranted('read', 'AdminMpAdmin2faSecurity'),
            'https_active' => $request->isSecure(),
            'https_configured' => 1 === (int) $this->getConfiguration()->get('PS_SSL_ENABLED'),
            'security_alerts' => $alertCatalog->all(),
        ]);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    public function authenticator(
        Security $security,
        FactorConfirmationService $confirmation,
        MfaManager $mfa,
    ): Response {
        $employee = $this->employee($security);
        if (!$mfa->active($employee->getId())) {
            return $this->render('@Modules/mpadmin2fa/views/templates/admin/authenticator.html.twig', [
                'active' => false,
                'layoutTitle' => 'Your authenticator',
            ]);
        }

        return $this->renderAuthenticator($employee, $confirmation);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    public function enrollmentEmployees(
        EmployeeFactorFilters $filters,
        #[Autowire(service: 'mpadmin2fa.grid.factory.employee_factor')]
        GridFactoryInterface $gridFactory,
    ): Response {
        return $this->render('@Modules/mpadmin2fa/views/templates/admin/enrollment/employees.html.twig', [
            'employeeFactorGrid' => $this->presentGrid($gridFactory->getGrid($filters)),
            'layoutTitle' => 'Employee 2FA',
        ]);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    public function enrollmentApprovals(
        PendingApprovalFilters $filters,
        #[Autowire(service: 'mpadmin2fa.grid.factory.pending_approval')]
        GridFactoryInterface $gridFactory,
    ): Response {
        return $this->render('@Modules/mpadmin2fa/views/templates/admin/enrollment/approvals.html.twig', [
            'layoutTitle' => 'Employee 2FA',
            'pendingApprovalGrid' => $this->presentGrid($gridFactory->getGrid($filters)),
        ]);
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    public function securityPolicy(
        #[Autowire(service: 'mpadmin2fa.security_policy.form_handler')]
        FormHandlerInterface $formHandler,
    ): Response {
        $form = $formHandler->getForm();

        return $this->render('@Modules/mpadmin2fa/views/templates/admin/security/policy.html.twig', [
            'layoutTitle' => 'Security',
            'policy_form' => $form->createView(),
            'can_update' => $this->isGranted('update', 'AdminMpAdmin2faSecurity'),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    #[DemoRestricted(redirectRoute: 'mpadmin2fa_security_policy')]
    public function updateSecurityPolicy(
        Request $request,
        Security $security,
        SecurityRepository $repository,
        Policy $policy,
        SessionState $sessionState,
        #[Autowire(service: 'mpadmin2fa.security_policy.form_handler')]
        FormHandlerInterface $formHandler,
    ): Response {
        $employee = $this->employee($security);
        $this->assertFreshVerification($employee, $sessionState, $policy);
        $form = $formHandler->getForm();
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('@Modules/mpadmin2fa/views/templates/admin/security/policy.html.twig', [
                'layoutTitle' => 'Security',
                'policy_form' => $form->createView(),
                'can_update' => true,
            ]);
        }

        $errors = $formHandler->save($form->getData());
        if (!empty($errors)) {
            $this->addFlashErrors($errors);

            return $this->render('@Modules/mpadmin2fa/views/templates/admin/security/policy.html.twig', [
                'layoutTitle' => 'Security',
                'policy_form' => $form->createView(),
                'can_update' => true,
            ]);
        }

        $repository->audit($employee->getId(), 'policy.updated', $request->getClientIp());
        $this->addFlash('success', 'Two-factor authentication settings saved.');

        return $this->redirectToRoute('mpadmin2fa_security_policy');
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    public function securityActivity(
        AuditEventFilters $filters,
        #[Autowire(service: 'mpadmin2fa.grid.factory.audit_event')]
        GridFactoryInterface $gridFactory,
    ): Response {
        return $this->render('@Modules/mpadmin2fa/views/templates/admin/security/activity.html.twig', [
            'auditEventGrid' => $this->presentGrid($gridFactory->getGrid($filters)),
            'layoutTitle' => 'Security',
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    #[DemoRestricted(redirectRoute: 'mpadmin2fa_enrollment_approvals')]
    public function approveEnrollment(
        Request $request,
        int $employeeId,
        Security $security,
        SecurityRepository $repository,
        SessionState $sessionState,
        Policy $policy,
    ): Response {
        $actor = $this->employee($security);
        $this->assertFreshVerification($actor, $sessionState, $policy);
        $this->requirePostAndCsrf($request, 'mp2fa_approve_' . $employeeId);
        if ($actor->getId() === $employeeId) {
            throw $this->createAccessDeniedException('Employees cannot approve their own 2FA setup.');
        }

        $repository->approveEnrollment($employeeId, $actor->getId());
        $repository->audit($actor->getId(), 'enrollment.approved', $request->getClientIp(), [
            'target_employee_id' => $employeeId,
        ]);
        $this->addFlash('success', "The employee's 2FA setup was approved.");

        return $this->redirectToRoute('mpadmin2fa_enrollment_approvals');
    }

    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", redirectRoute: 'admin_homepage')]
    #[DemoRestricted(redirectRoute: 'mpadmin2fa_enrollment_employees')]
    public function adminReset(
        Request $request,
        int $employeeId,
        Security $security,
        MfaManager $mfa,
        SessionState $sessionState,
        Policy $policy,
    ): Response {
        $actor = $this->employee($security);
        $this->assertFreshVerification($actor, $sessionState, $policy);
        $this->requirePostAndCsrf($request, 'mp2fa_admin_reset_' . $employeeId);
        if ($actor->getId() === $employeeId) {
            throw $this->createAccessDeniedException('Employees cannot reset their own two-factor authentication from the employee list.');
        }

        $mfa->reset($employeeId, $actor->getId(), $request->getClientIp(), 'superadmin-reset');
        $this->addFlash('success', 'Two-factor authentication was reset for this employee.');

        return $this->redirectToRoute('mpadmin2fa_enrollment_employees');
    }

    private function renderAuthenticator(
        Employee $employee,
        FactorConfirmationService $confirmation,
        array $forms = [],
    ): Response {
        $passwordRequired = $confirmation->passwordRequired($employee);
        $replaceForm = $forms['replace_form'] ?? $this->createForm(ReplaceFactorType::class, null, [
            'password_required' => $passwordRequired,
        ]);
        $recoveryForm = $forms['recovery_form'] ?? $this->createForm(RegenerateRecoveryCodesType::class, null, [
            'password_required' => $passwordRequired,
        ]);
        $disableForm = $forms['disable_form'] ?? $this->createForm(DisableFactorType::class, null, [
            'password_required' => $passwordRequired,
        ]);

        return $this->render('@Modules/mpadmin2fa/views/templates/admin/authenticator.html.twig', [
            'active' => true,
            'disable_form' => $disableForm->createView(),
            'layoutTitle' => 'Your authenticator',
            'recovery_form' => $recoveryForm->createView(),
            'replace_form' => $replaceForm->createView(),
        ]);
    }

    private function dashboardUrl(RouterInterface $router): string
    {
        foreach (['admin_dashboard_index', 'admin_dashboard'] as $routeName) {
            if (null !== $router->getRouteCollection()->get($routeName)) {
                return $router->generate($routeName);
            }
        }

        throw new RuntimeException('No supported PrestaShop dashboard route is available.');
    }

    private function employee(Security $security): Employee
    {
        $employee = $security->getUser();
        if (!$employee instanceof Employee) {
            throw $this->createAccessDeniedException();
        }

        return $employee;
    }

    private function requireHttps(Request $request): void
    {
        if (!$request->isSecure()) {
            throw $this->createAccessDeniedException('You need a secure HTTPS connection to set up an authenticator.');
        }
    }

    private function requirePostAndCsrf(Request $request, string $tokenId): void
    {
        if (!$request->isMethod('POST')
            || !$this->isCsrfTokenValid(
                $tokenId,
                (string) ($request->request->get('mp2fa_csrf_token') ?: $request->query->get('token'))
            )
        ) {
            throw $this->createAccessDeniedException('Invalid request.');
        }
    }

    private function assertFreshVerification(
        Employee $employee,
        SessionState $sessionState,
        Policy $policy,
    ): void {
        if (!$sessionState->hasFreshVerification($employee->getId(), $policy->stepUpSeconds())) {
            throw $this->createAccessDeniedException('Confirm your identity with two-factor authentication again before continuing.');
        }
    }
}
