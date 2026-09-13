<?php

declare(strict_types=1);

namespace Mpadmin2fa\Http;

use Mpadmin2fa\Translation\TranslatesMessages;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Translation\TranslatorInterface;

final class LoginHttpsGuard
{
    use TranslatesMessages;

    /** @var TokenStorageInterface */
    private $tokenStorage;

    /** @var LegacyContext */
    private $legacyContext;

    /** @var TranslatorInterface|null */
    private $translator;

    public const ERROR_MESSAGE = 'A secure HTTPS connection is required to log in with two-factor authentication.';

    public function __construct(
        TokenStorageInterface $tokenStorage,
        LegacyContext $legacyContext,
        ?TranslatorInterface $translator = null
    ) {
        $this->tokenStorage = $tokenStorage;
        $this->legacyContext = $legacyContext;
        $this->translator = $translator;
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
        $request->getSession()->getFlashBag()->add('error', $this->errorMessage());

        return new RedirectResponse($this->legacyContext->getAdminLink('AdminLogin', false));
    }

    public function errorMessage(): string
    {
        return $this->trans(self::ERROR_MESSAGE, [], 'Modules.Mpadmin2fa.Admin');
    }
}
