<?php

declare(strict_types=1);

namespace Mpadmin2fa\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StepUpResponseFactory
{
    public const REDIRECT_HEADER = 'X-Mpadmin2fa-Redirect';

    public function create(Request $request, string $redirectUrl): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return new RedirectResponse($redirectUrl);
        }

        return new JsonResponse(
            [
                'status' => false,
                'msg' => 'Two-factor authentication is required to continue.',
            ],
            Response::HTTP_FORBIDDEN,
            [self::REDIRECT_HEADER => $redirectUrl]
        );
    }
}
