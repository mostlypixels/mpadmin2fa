<?php

declare(strict_types=1);

namespace Mpadmin2fa\Http;

use Context;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Mpadmin2fa\Repository\SecurityRepository;
use Mpadmin2fa\Security\AdminMfaAccessPolicy;
use Mpadmin2fa\Security\MfaManager;
use Mpadmin2fa\Security\Policy;
use Mpadmin2fa\Security\ReturnTargetPolicy;
use Mpadmin2fa\Security\SessionState;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

final class LegacyAdminMfaAdapter
{
    /** @var AdminMfaAccessPolicy */
    private $accessPolicy;

    /** @var MfaManager */
    private $mfa;

    /** @var Policy */
    private $policy;

    /** @var SecurityRepository */
    private $repository;

    /** @var RequestStack */
    private $requestStack;

    /** @var ReturnTargetPolicy */
    private $returnTargets;

    /** @var RouterInterface */
    private $router;

    /** @var SessionState */
    private $sessionState;

    /** @var StepUpResponseFactory */
    private $stepUpResponses;

    /** @var LegacyAdminRequestNormalizer */
    private $normalizer;

    /** @var SessionInterface */
    private $session;
    /** @var TokenStorageInterface */
    private $tokenStorage;
    /** @var UserProviderInterface */
    private $userProvider;
    /** @var LoginHttpsGuard */
    private $loginHttpsGuard;

    public function __construct(
        AdminMfaAccessPolicy $accessPolicy,
        MfaManager $mfa,
        Policy $policy,
        SecurityRepository $repository,
        RequestStack $requestStack,
        ReturnTargetPolicy $returnTargets,
        RouterInterface $router,
        SessionState $sessionState,
        StepUpResponseFactory $stepUpResponses,
        LegacyAdminRequestNormalizer $normalizer,
        SessionInterface $session,
        TokenStorageInterface $tokenStorage,
        UserProviderInterface $userProvider,
        LoginHttpsGuard $loginHttpsGuard
    ) {
        $this->accessPolicy = $accessPolicy;
        $this->mfa = $mfa;
        $this->policy = $policy;
        $this->repository = $repository;
        $this->requestStack = $requestStack;
        $this->returnTargets = $returnTargets;
        $this->router = $router;
        $this->sessionState = $sessionState;
        $this->stepUpResponses = $stepUpResponses;
        $this->normalizer = $normalizer;
        $this->session = $session;
        $this->tokenStorage = $tokenStorage;
        $this->userProvider = $userProvider;
        $this->loginHttpsGuard = $loginHttpsGuard;
    }

    public function enforce(): ?Response
    {
        $context = Context::getContext();
        $employee = $context->employee;
        $employeeId = isset($employee->id) ? (int) $employee->id : 0;
        if ($employeeId <= 0 || !$employee->isLoggedBack()) {
            return null;
        }

        $request = $this->request();
        // PS 1.7 also dispatches this hook for routed Symfony controllers.
        // Those requests have already passed AdminMfaSubscriber.
        if ('' !== (string) $request->attributes->get('_route')) {
            return null;
        }
        if (null === $this->tokenStorage->getToken()) {
            $user = $this->userProvider->loadUserByUsername($employee->email);
            $this->tokenStorage->setToken(new UsernamePasswordToken($user, null, 'admin', $user->getRoles()));
        }
        $controller = $this->normalizer->controller($request->request->get('controller', $request->query->get('controller', '')));
        $action = $this->normalizer->action($request->query->all()) . ' '
            . $this->normalizer->action($request->request->all());
        if ('AdminLogin' === $controller && $request->query->has('logout')) {
            $this->sessionState->clear();

            return null;
        }

        $active = $this->mfa->active($employeeId);
        $decision = $this->accessPolicy->decide(
            'legacy',
            $controller,
            $action,
            $request->isMethodSafe(),
            $this->policy->requiresLoginMfaForProfile((int) $employee->id_profile),
            $active,
            $this->sessionState->isVerified($employeeId),
            $this->sessionState->hasFreshVerification($employeeId, $this->policy->stepUpSeconds()),
            $this->sessionState->isRecoveryRestricted($employeeId)
        );

        if (AdminMfaAccessPolicy::ALLOW === $decision) {
            return null;
        }

        $this->repository->audit($employeeId, 'access.' . $decision, $request->getClientIp(), [
            'action' => $action,
            'controller' => $controller,
        ]);

        if (AdminMfaAccessPolicy::DENY === $decision) {
            return new Response('Access denied.', Response::HTTP_FORBIDDEN, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        if (AdminMfaAccessPolicy::REQUIRE_STEP_UP === $decision) {
            $returnTarget = $this->returnTargets->fromReferer(
                $request->headers->get('referer'),
                $request->getHost(),
                $request->getBasePath()
            );
            if (null !== $returnTarget) {
                $this->sessionState->setReturnTarget($returnTarget);
            }
        }

        $redirectUrl = $active && !$this->sessionState->isRecoveryRestricted($employeeId)
            ? $this->router->generate('mpadmin2fa_challenge', [
                'step_up' => AdminMfaAccessPolicy::REQUIRE_STEP_UP === $decision ? 1 : 0,
            ])
            : $this->router->generate('mpadmin2fa_enroll');

        return $this->stepUpResponses->create($request, $redirectUrl);
    }
    public function onLogin(): ?Response
    {
        $request = $this->request();
        $employee = Context::getContext()->employee;
        $employeeId = (int) $employee->id;
        $this->sessionState->resetForLogin($employeeId);
        if ($this->loginHttpsGuard->shouldReject(
            $request,
            $this->mfa->active($employeeId),
            $this->policy->requiresLoginMfaForProfile((int) $employee->id_profile)
        )) {
            $response = $this->loginHttpsGuard->reject($request);

            return $request->isXmlHttpRequest()
                ? new JsonResponse(['hasErrors' => true, 'errors' => [LoginHttpsGuard::ERROR_MESSAGE]], 403)
                : $response;
        }

        return null;
    }

    private function request(): Request
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            $request = Request::createFromGlobals();
            $this->requestStack->push($request);
        }
        if (!$request->hasSession()) {
            $request->setSession($this->session);
        }

        return $request;
    }

}
