<?php

declare(strict_types=1);

namespace Mpadmin2fa\Http;

use Mpadmin2fa\Exception\MfaSecurityException;
use Mpadmin2fa\Repository\SecurityRepository;
use Mpadmin2fa\Security\AdminMfaAccessPolicy;
use Mpadmin2fa\Security\MfaManager;
use Mpadmin2fa\Security\Policy;
use Mpadmin2fa\Security\ReturnTargetPolicy;
use Mpadmin2fa\Security\SecurityAlertService;
use Mpadmin2fa\Security\SessionState;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Routing\RouterInterface;

final class LegacyAdminMfaAdapter
{
    /** @var RequestStack */
    private $requestStack;

    /** @var SessionInterface */
    private $session;

    /** @var TokenStorageInterface */
    private $tokenStorage;

    /** @var UserProviderInterface */
    private $userProvider;

    /** @var RouterInterface */
    private $router;

    /** @var MfaManager */
    private $mfa;

    /** @var Policy */
    private $policy;

    /** @var AdminMfaAccessPolicy */
    private $accessPolicy;

    /** @var SessionState */
    private $sessionState;

    /** @var ReturnTargetPolicy */
    private $returnTargets;

    /** @var StepUpResponseFactory */
    private $responses;

    /** @var SecurityRepository */
    private $repository;

    /** @var LoginHttpsGuard */
    private $loginHttpsGuard;

    /** @var SecurityAlertService */
    private $alerts;

    public function __construct(
        RequestStack $requestStack,
        SessionInterface $session,
        TokenStorageInterface $tokenStorage,
        UserProviderInterface $userProvider,
        RouterInterface $router,
        MfaManager $mfa,
        Policy $policy,
        AdminMfaAccessPolicy $accessPolicy,
        SessionState $sessionState,
        ReturnTargetPolicy $returnTargets,
        StepUpResponseFactory $responses,
        SecurityRepository $repository,
        SecurityAlertService $alerts,
        LoginHttpsGuard $loginHttpsGuard
    ) {
        $this->requestStack = $requestStack;
        $this->session = $session;
        $this->tokenStorage = $tokenStorage;
        $this->userProvider = $userProvider;
        $this->router = $router;
        $this->mfa = $mfa;
        $this->policy = $policy;
        $this->accessPolicy = $accessPolicy;
        $this->sessionState = $sessionState;
        $this->returnTargets = $returnTargets;
        $this->responses = $responses;
        $this->repository = $repository;
        $this->alerts = $alerts;
        $this->loginHttpsGuard = $loginHttpsGuard;
    }

    public function enforce(\Context $context, int $controllerType): ?Response
    {
        if ($controllerType !== \Dispatcher::FC_ADMIN) {
            return null;
        }

        $employee = $context->employee;
        if (!\Validate::isLoadedObject($employee) || (int) $employee->id <= 0 || !$employee->isLoggedBack()) {
            return null;
        }

        $request = $this->requestStack->getCurrentRequest() ?? Request::createFromGlobals();
        if (!$request->hasSession()) {
            $request->setSession($this->session);
        }
        // PS8's 404-to-legacy fallback never reaches its native security listener.
        // Restore the same token from the already authenticated native cookie.
        if (null === $this->tokenStorage->getToken()) {
            $user = $this->userProvider->loadUserByUsername($employee->email);
            $this->tokenStorage->setToken(new UsernamePasswordToken($user, null, 'admin', $user->getRoles()));
        }

        $employeeId = (int) $employee->id;
        $profileId = (int) $employee->id_profile;
        $controller = $this->controller($request);
        $action = $this->action($request);
        if (0 === strcasecmp($controller, 'AdminLogin') && $request->query->has('logout')) {
            $this->sessionState->clear();

            return null;
        }

        try {
            $active = $this->mfa->active($employeeId);
            if ($this->loginHttpsGuard->shouldReject($request, $active, $this->policy->requiresLoginMfaForProfile($profileId))) {
                return $this->loginHttpsGuard->reject($request);
            }
            $decision = $this->accessPolicy->decide(
                $employeeId,
                $this->policy->requiresLoginMfaForProfile($profileId),
                $active,
                $this->sessionState->isVerified($employeeId),
                $this->sessionState->isRecoveryRestricted($employeeId),
                $this->sessionState->hasFreshVerification($employeeId, $this->policy->stepUpSeconds()),
                $request->isMethodSafe(),
                (string) $request->attributes->get('_route'),
                $controller,
                $action
            );

            if (AdminMfaAccessPolicy::ALLOW === $decision) {
                return null;
            }

            if (AdminMfaAccessPolicy::DENY === $decision) {
                $this->audit($employeeId, 'access.denied', $request, $decision, $controller, $action);

                return new Response('Access denied.', Response::HTTP_FORBIDDEN);
            }

            if (AdminMfaAccessPolicy::REQUIRE_STEP_UP === $decision) {
                $this->sessionState->setReturnTarget($this->safeReturnTarget($request));
            }
            $this->audit($employeeId, 'access.redirected', $request, $decision, $controller, $action);

            return $this->responses->create(
                $request,
                $active && !$this->sessionState->isRecoveryRestricted($employeeId)
                    ? $this->router->generate('mpadmin2fa_challenge', [
                        'step_up' => AdminMfaAccessPolicy::REQUIRE_STEP_UP === $decision ? 1 : 0,
                    ])
                    : $this->router->generate('mpadmin2fa_enroll')
            );
        } catch (MfaSecurityException $exception) {
            $this->alerts->notify($employeeId, 'encryption_key.failure', [
                'message' => $exception->getMessage(),
            ]);

            return new Response(
                'Two-factor authentication is unavailable because its encryption key failed validation. Contact the site operator.',
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }
    }

    public function onLogin(\Context $context): ?Response
    {
        $employee = $context->employee;
        $this->sessionState->resetForLogin((int) $employee->id);
        $request = $this->requestStack->getCurrentRequest() ?? Request::createFromGlobals();
        if (!$request->hasSession()) {
            $request->setSession($this->session);
        }
        try {
            if ($this->loginHttpsGuard->shouldReject(
                $request,
                $this->mfa->active((int) $employee->id),
                $this->policy->requiresLoginMfaForProfile((int) $employee->id_profile)
            )) {
                $response = $this->loginHttpsGuard->reject($request);

                return $request->isXmlHttpRequest()
                    ? new \Symfony\Component\HttpFoundation\JsonResponse([
                        'hasErrors' => true,
                        'errors' => [LoginHttpsGuard::ERROR_MESSAGE],
                    ], Response::HTTP_FORBIDDEN)
                    : $response;
            }
        } catch (MfaSecurityException $exception) {
            $this->loginHttpsGuard->reject($request);

            return new Response('Two-factor authentication is unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return null;
    }

    private function controller(Request $request): string
    {
        return (string) (
            $request->attributes->get('_legacy_controller')
            ?: $request->request->get('controller')
            ?: $request->query->get('controller')
            ?: ''
        );
    }

    private function action(Request $request): string
    {
        foreach (['action', 'submitAction'] as $parameter) {
            $value = $request->request->get($parameter, $request->query->get($parameter));
            if (is_scalar($value) && '' !== (string) $value) {
                return (string) $value;
            }
        }

        foreach (array_keys(array_merge($request->query->all(), $request->request->all())) as $parameter) {
            if (preg_match('/^(?:submit|bulk|delete|disable|enable|import|install|reset|uninstall|update|upgrade)/i', $parameter)) {
                return $parameter;
            }
        }

        return '';
    }

    private function safeReturnTarget(Request $request): string
    {
        $target = $this->returnTargets->fromReferer(
            $request->headers->get('referer'),
            $request->getHost(),
            $request->getBasePath()
        );

        return null !== $target ? $target : $this->router->generate('admin_module_manage');
    }

    private function audit(
        int $employeeId,
        string $event,
        Request $request,
        string $decision,
        string $controller,
        string $action
    ): void {
        $this->repository->audit($employeeId, $event, $request->getClientIp(), [
            'action' => $action,
            'controller' => $controller,
            'decision' => $decision,
        ]);
    }
}
