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
use Symfony\Component\Routing\RouterInterface;

final class LegacyAdminMfaAdapter
{
    /** @var RequestStack */
    private $requestStack;

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

    /** @var SecurityAlertService */
    private $alerts;

    public function __construct(
        RequestStack $requestStack,
        RouterInterface $router,
        MfaManager $mfa,
        Policy $policy,
        AdminMfaAccessPolicy $accessPolicy,
        SessionState $sessionState,
        ReturnTargetPolicy $returnTargets,
        StepUpResponseFactory $responses,
        SecurityRepository $repository,
        SecurityAlertService $alerts
    ) {
        $this->requestStack = $requestStack;
        $this->router = $router;
        $this->mfa = $mfa;
        $this->policy = $policy;
        $this->accessPolicy = $accessPolicy;
        $this->sessionState = $sessionState;
        $this->returnTargets = $returnTargets;
        $this->responses = $responses;
        $this->repository = $repository;
        $this->alerts = $alerts;
    }

    public function enforce(\Context $context, int $controllerType): ?Response
    {
        if ($controllerType !== \Dispatcher::FC_ADMIN) {
            return null;
        }

        $request = $this->requestStack->getCurrentRequest();
        $employee = $context->employee;
        if (null === $request || !\Validate::isLoadedObject($employee) || (int) $employee->id <= 0) {
            return null;
        }

        $employeeId = (int) $employee->id;
        $profileId = (int) $employee->id_profile;
        $controller = $this->controller($request);
        $action = $this->action($request);

        try {
            $active = $this->mfa->active($employeeId);
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
