<?php

declare(strict_types=1);

namespace Mpadmin2fa\Http;

use PrestaShop\PrestaShop\Adapter\LegacyContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class LoginHttpsGuard
{

    /** @var TokenStorageInterface */
    private $tokenStorage;

    /** @var LegacyContext */
    private $legacyContext;

    public const ERROR_MESSAGE = 'A secure HTTPS connection is required to log in with two-factor authentication.';

    public function __construct(
        TokenStorageInterface $tokenStorage,
        LegacyContext $legacyContext
    ) {
        $this->tokenStorage = $tokenStorage;
        $this->legacyContext = $legacyContext;
    }

    public function shouldReject(Request $request, bool $factorActive, bool $factorRequired): bool
    {
        return !$request->isSecure() && ($factorActive || $factorRequired);
    }

    public function reject(Request $request): RedirectResponse
    {
        $employee = $this->legacyContext->getContext()->employee;
        if (null !== $employee) {
            $employee->logout();
        }
        $this->tokenStorage->setToken(null);
        $request->getSession()->invalidate();
        $request->getSession()->getFlashBag()->add('error', self::ERROR_MESSAGE);

        return new RedirectResponse($this->legacyContext->getAdminLink('AdminLogin', false));
    }
}
