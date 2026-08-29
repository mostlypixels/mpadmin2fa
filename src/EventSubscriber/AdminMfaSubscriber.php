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
use PrestaShopBundle\Security\Admin\Employee;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;

final class AdminMfaSubscriber implements EventSubscriberInterface
{
    /** @var TokenStorageInterface */
    private $tokenStorage;

    /** @var RouterInterface */
    private $router;

    /** @var MfaManager */
    private $mfa;

    /** @var Policy */
    private $policy;

    /** @var ReturnTargetPolicy */
    private $returnTargets;

    /** @var SessionState */
    private $sessionState;

    /** @var SecurityAlertService */
    private $alerts;

    /** @var StepUpResponseFactory */
    private $stepUpResponses;

    /** @var LoginHttpsGuard */
    private $loginHttpsGuard;

    /** @var AdminMfaAccessPolicy */
    private $accessPolicy;

    public function __construct(
        TokenStorageInterface $tokenStorage,
        RouterInterface $router,
        MfaManager $mfa,
        Policy $policy,
        ReturnTargetPolicy $returnTargets,
        SessionState $sessionState,
        SecurityAlertService $alerts,
        StepUpResponseFactory $stepUpResponses,
        LoginHttpsGuard $loginHttpsGuard,
        AdminMfaAccessPolicy $accessPolicy
    ) {
        $this->tokenStorage = $tokenStorage;
        $this->router = $router;
        $this->mfa = $mfa;
        $this->policy = $policy;
        $this->returnTargets = $returnTargets;
        $this->sessionState = $sessionState;
        $this->alerts = $alerts;
        $this->stepUpResponses = $stepUpResponses;
        $this->loginHttpsGuard = $loginHttpsGuard;
        $this->accessPolicy = $accessPolicy;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SecurityEvents::INTERACTIVE_LOGIN => ['onLoginSuccess', -64],
            KernelEvents::REQUEST => ['onKernelRequest', 5],
        ];
    }

    public function onLoginSuccess(InteractiveLoginEvent $event): void
    {
        $user = $event->getAuthenticationToken()->getUser();
        if ($user instanceof Employee) {
            $this->sessionState->resetForLogin($user->getId());
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMasterRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        $employee = null !== $token ? $token->getUser() : null;
        if (!$employee instanceof Employee) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        if ('admin_logout' === $route) {
            $this->sessionState->clear();

            return;
        }

        try {
            $active = $this->mfa->active($employee->getId());

            if ($this->loginHttpsGuard->shouldReject(
                $request,
                $active,
                $this->policy->requiresLoginMfa($employee)
            )) {
                $event->setResponse($this->loginHttpsGuard->reject($request));

                return;
            }

            $recoveryRestricted = $this->sessionState->isRecoveryRestricted($employee->getId());
            $decision = $this->accessPolicy->decide(
                $employee->getId(),
                $this->policy->requiresLoginMfa($employee),
                $active,
                $this->sessionState->isVerified($employee->getId()),
                $recoveryRestricted,
                $this->sessionState->hasFreshVerification($employee->getId(), $this->policy->stepUpSeconds()),
                $request->isMethodSafe(),
                $route,
                (string) $request->attributes->get('_legacy_controller'),
                (string) $request->get('action', $request->get('submitAction', ''))
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
                    : $this->router->generate('mpadmin2fa_enroll')
            ));
        } catch (MfaSecurityException $exception) {
            $this->alerts->notify($employee->getId(), 'encryption_key.failure', [
                'message' => $exception->getMessage(),
            ]);
            $event->setResponse(new Response(
                'Two-factor authentication is unavailable because its encryption key failed validation. Contact the site operator.',
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            ));
        }
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

        return 0 === strpos((string) $request->attributes->get('_route'), 'admin_themes_')
            ? $this->router->generate('admin_themes_index')
            : $this->router->generate('admin_module_manage');
    }
}
