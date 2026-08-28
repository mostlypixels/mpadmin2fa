<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Mpadmin2fa\Http\LoginHttpsGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;

final class LoginHttpsGuardTest extends TestCase
{
    public static function loginScenarios(): iterable
    {
        yield 'HTTP with an active factor' => [false, true, false, true];
        yield 'HTTP with required enrollment' => [false, false, true, true];
        yield 'HTTP without active or required 2FA' => [false, false, false, false];
        yield 'HTTPS with an active factor' => [true, true, false, false];
        yield 'HTTPS with required enrollment' => [true, false, true, false];
    }

    #[DataProvider('loginScenarios')]
    public function testRejectsOnlyInsecureLoginsThatNeedTwoFactorAuthentication(
        bool $secure,
        bool $factorActive,
        bool $factorRequired,
        bool $expected,
    ): void {
        $request = Request::create('http' . ($secure ? 's' : '') . '://example.test/admin/login');

        self::assertSame(
            $expected,
            $this->guard()->shouldReject($request, $factorActive, $factorRequired)
        );
    }

    public function testRejectedLoginReturnsToNormalLoginFormWithHttpsError(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('logout')->with(false);

        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('admin_login')
            ->willReturn('/admin/login');

        $request = Request::create('http://example.test/admin/login', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $response = (new LoginHttpsGuard($security, $router))->reject($request);

        self::assertSame('/admin/login', $response->getTargetUrl());
        self::assertSame(
            [LoginHttpsGuard::ERROR_MESSAGE],
            $request->getSession()->getFlashBag()->peek('error')
        );
    }

    private function guard(): LoginHttpsGuard
    {
        return new LoginHttpsGuard(
            $this->createStub(Security::class),
            $this->createStub(RouterInterface::class)
        );
    }
}
