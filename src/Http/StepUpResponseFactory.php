<?php

declare(strict_types=1);

namespace Mpadmin2fa\Http;

use Mpadmin2fa\Translation\TranslatesMessages;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\TranslatorInterface;

final class StepUpResponseFactory
{
    use TranslatesMessages;

    public const REDIRECT_HEADER = 'X-Mpadmin2fa-Redirect';

    /** @var TranslatorInterface|null */
    private $translator;

    public function __construct(?TranslatorInterface $translator = null)
    {
        $this->translator = $translator;
    }

    public function create(Request $request, string $redirectUrl): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return new RedirectResponse($redirectUrl);
        }

        return new JsonResponse(
            [
                'status' => false,
                'msg' => $this->trans('Two-factor authentication is required to continue.', [], 'Modules.Mpadmin2fa.Admin'),
            ],
            Response::HTTP_FORBIDDEN,
            [self::REDIRECT_HEADER => $redirectUrl]
        );
    }
}
