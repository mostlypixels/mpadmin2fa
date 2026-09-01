<?php

declare(strict_types=1);

namespace Mpadmin2fa\EventSubscriber;

use Mpadmin2fa\Exception\MfaSecurityException;
use Mpadmin2fa\Http\LoginHttpsGuard;
use Mpadmin2fa\Http\StepUpResponseFactory;
use Mpadmin2fa\Security\AdminMfaAccessPolicy;
use Mpadmin2fa\Security\MfaManager;
use Mpadmin2fa\Security\Policy;
use Mpadmin2fa\Security\ReturnTargetPolicy;
use Mpadmin2fa\Security\SecurityAlertService;
use Mpadmin2fa\Security\SessionState;
use PrestaShopBundle\Entity\Employee\Employee;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class AdminMfaSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly RouterInterface $router,
        private readonly MfaManager $mfa,
        private readonly Policy $policy,
        private readonly ReturnTargetPolicy $returnTargets,
        private readonly SessionState $sessionState,
        private readonly SecurityAlertService $alerts,
        private readonly StepUpResponseFactory $stepUpResponses,
        private readonly LoginHttpsGuard $loginHttpsGuard,
        private readonly AdminMfaAccessPolicy $accessPolicy,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => ['onLoginSuccess', -64],
            LogoutEvent::class => 'onLogout',
            KernelEvents::REQUEST => ['onKernelRequest', 5],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getAuthenticatedToken()->getUser();
        if (!$user instanceof Employee) {
            return;
        }

        $this->sessionState->resetForLogin($user->getId());

        try {
            if ($this->loginHttpsGuard->shouldReject(
                $event->getRequest(),
                $this->mfa->active($user->getId()),
                $this->policy->requiresLoginMfa($user)
            )) {
                $event->setResponse($this->loginHttpsGuard->reject($event->getRequest()));
            }
        } catch (MfaSecurityException $exception) {
            $event->setResponse($this->securityFailureResponse($user->getId(), $exception));
        }
    }

    public function onLogout(): void
    {
        $this->sessionState->clear();
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $employee = $this->security->getUser();
        if (!$employee instanceof Employee) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        if ('admin_logout' === $route) {
            return;
        }

        try {
            $active = $this->mfa->active($employee->getId());
            $requiresLoginMfa = $this->policy->requiresLoginMfa($employee);

            if ($this->loginHttpsGuard->shouldReject($request, $active, $requiresLoginMfa)) {
                $event->setResponse($this->loginHttpsGuard->reject($request));

                return;
            }

            $recoveryRestricted = $this->sessionState->isRecoveryRestricted($employee->getId());
            $decision = $this->accessPolicy->decide(
                $employee->getId(),
                $requiresLoginMfa,
                $active,
                $this->sessionState->isVerified($employee->getId()),
                $recoveryRestricted,
                $this->sessionState->hasFreshVerification($employee->getId(), $this->policy->stepUpSeconds()),
                $request->isMethodSafe(),
                $route,
                (string) $request->attributes->get('_legacy_controller', ''),
                $this->requestAction($request),
            );

            if (AdminMfaAccessPolicy::ALLOW === $decision) {
                return;
            }

            if (AdminMfaAccessPolicy::DENY === $decision) {
                $event->setResponse(new Response('Access denied.', Response::HTTP_FORBIDDEN));

                return;
            }

            if (AdminMfaAccessPolicy::REQUIRE_STEP_UP === $decision) {
                $this->sessionState->setReturnTarget($this->safeReturnTarget($request));
            }
            $event->setResponse($this->stepUpResponses->create(
                $request,
                $active && !$recoveryRestricted
                    ? $this->router->generate('mpadmin2fa_challenge', [
                        'step_up' => AdminMfaAccessPolicy::REQUIRE_STEP_UP === $decision ? 1 : 0,
                    ])
                    : $this->router->generate('mpadmin2fa_enroll'),
            ));
        } catch (MfaSecurityException $exception) {
            $event->setResponse($this->securityFailureResponse($employee->getId(), $exception));
        }
    }

    private function securityFailureResponse(int $employeeId, MfaSecurityException $exception): Response
    {
        $this->alerts->notify($employeeId, 'encryption_key.failure', [
            'message' => $exception->getMessage(),
        ]);

        return new Response(
            'Two-factor authentication is unavailable because its encryption key failed validation. Contact the site operator.',
            Response::HTTP_SERVICE_UNAVAILABLE,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }

    private function requestAction(\Symfony\Component\HttpFoundation\Request $request): string
    {
        $requestParameters = $request->request->all();
        $queryParameters = $request->query->all();
        foreach (['action', 'submitAction'] as $parameter) {
            $value = $requestParameters[$parameter] ?? $queryParameters[$parameter] ?? null;
            if (is_scalar($value) && '' !== (string) $value) {
                return (string) $value;
            }
        }

        foreach (array_keys(array_merge($queryParameters, $requestParameters)) as $parameter) {
            if (preg_match('/^(?:submit|bulk|delete|disable|enable|import|install|reset|uninstall|update|upgrade)/i', $parameter)) {
                return $parameter;
            }
        }

        return '';
    }

    private function safeReturnTarget(\Symfony\Component\HttpFoundation\Request $request): string
    {
        $returnTarget = $this->returnTargets->fromReferer(
            $request->headers->get('referer'),
            $request->getHost(),
            $request->getBasePath()
        );
        if (null !== $returnTarget) {
            return $returnTarget;
        }

        return str_starts_with((string) $request->attributes->get('_route'), 'admin_themes_')
            ? $this->router->generate('admin_themes_index')
            : $this->router->generate('admin_module_manage');
    }
}
