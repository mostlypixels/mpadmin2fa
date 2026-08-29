<?php

declare(strict_types=1);

namespace Mpadmin2fa\Http;

use Context;
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
        LegacyAdminRequestNormalizer $normalizer
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
    }

    public function enforce(): ?Response
    {
        $context = Context::getContext();
        $employee = $context->employee;
        $employeeId = isset($employee->id) ? (int) $employee->id : 0;
        if ($employeeId <= 0) {
            return null;
        }

        $request = $this->requestStack->getCurrentRequest() ?? Request::createFromGlobals();
        $controller = $this->normalizer->controller($request->query->get('controller', ''));
        $action = $this->normalizer->action(array_merge($request->query->all(), $request->request->all()));
        if ('AdminLogin' === $controller && 'logout' === $action) {
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
}
