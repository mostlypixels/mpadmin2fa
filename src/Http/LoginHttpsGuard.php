<?php

declare(strict_types=1);

namespace Mpadmin2fa\Http;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class LoginHttpsGuard
{

    /** @var TokenStorageInterface */
    private $tokenStorage;

    /** @var RouterInterface */
    private $router;

    public const ERROR_MESSAGE = 'A secure HTTPS connection is required to log in with two-factor authentication.';

    public function __construct(
        TokenStorageInterface $tokenStorage,
        RouterInterface $router
    ) {
        $this->tokenStorage = $tokenStorage;
        $this->router = $router;
    }

    public function shouldReject(Request $request, bool $factorActive, bool $factorRequired): bool
    {
        return !$request->isSecure() && ($factorActive || $factorRequired);
    }

    public function reject(Request $request): RedirectResponse
    {
        $this->tokenStorage->setToken(null);
        $request->getSession()->invalidate();
        $request->getSession()->getFlashBag()->add('error', self::ERROR_MESSAGE);

        return new RedirectResponse($this->router->generate('admin_login'));
    }
}
