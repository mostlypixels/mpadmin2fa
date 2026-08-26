<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Mpadmin2fa\Http\StepUpResponseFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class StepUpResponseFactoryTest extends TestCase
{
    public function testRegularRequestRedirectsToChallenge(): void
    {
        $response = (new StepUpResponseFactory())->create(
            Request::create('/admin/modules/manage/action/upgrade/example', 'POST'),
            '/admin/mpadmin2fa/challenge?step_up=1'
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin/mpadmin2fa/challenge?step_up=1', $response->getTargetUrl());
    }

    public function testAjaxRequestReturnsMachineReadableChallenge(): void
    {
        $request = Request::create('/admin/modules/manage/action/upgrade/example', 'POST');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        $response = (new StepUpResponseFactory())->create(
            $request,
            '/admin/mpadmin2fa/challenge?step_up=1'
        );

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame(
            '/admin/mpadmin2fa/challenge?step_up=1',
            $response->headers->get(StepUpResponseFactory::REDIRECT_HEADER)
        );
        self::assertSame(
            [
                'status' => false,
                'msg' => 'Two-factor authentication is required to continue.',
            ],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testModuleRegistersAjaxRedirectListener(): void
    {
        $module = file_get_contents(dirname(__DIR__, 2) . '/mpadmin2fa.php');
        $javascript = file_get_contents(dirname(__DIR__, 2) . '/views/js/admin-step-up.js');

        self::assertIsString($module);
        self::assertIsString($javascript);
        self::assertStringContainsString("registerHook('actionAdminControllerSetMedia')", $module);
        self::assertStringContainsString('X-Mpadmin2fa-Redirect', $javascript);
    }
}
