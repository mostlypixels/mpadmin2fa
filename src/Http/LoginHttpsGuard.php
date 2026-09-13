<?php

declare(strict_types=1);

namespace Mpadmin2fa\Http;

use Mpadmin2fa\Translation\TranslatesMessages;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LoginHttpsGuard
{
    use TranslatesMessages;

    public const ERROR_MESSAGE = 'A secure HTTPS connection is required to log in with two-factor authentication.';

    public function __construct(
        private readonly Security $security,
        private readonly RouterInterface $router,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    public function shouldReject(Request $request, bool $factorActive, bool $factorRequired): bool
    {
        return !$request->isSecure() && ($factorActive || $factorRequired);
    }

    public function reject(Request $request): RedirectResponse
    {
        $this->security->logout(false);
        $request->getSession()->getFlashBag()->add('error', $this->trans(self::ERROR_MESSAGE, [], 'Modules.Mpadmin2fa.Admin'));

        return new RedirectResponse($this->router->generate('admin_login'));
    }
}
