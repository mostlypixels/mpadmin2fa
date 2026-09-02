<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Mpadmin2fa\Http\LoginHttpsGuard;
use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

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

    /** @dataProvider loginScenarios */
    public function testRejectsOnlyInsecureLoginsThatNeedTwoFactorAuthentication(
        bool $secure,
        bool $factorActive,
        bool $factorRequired,
        bool $expected
    ): void {
        $request = Request::create('http' . ($secure ? 's' : '') . '://example.test/admin/login');

        self::assertSame(
            $expected,
            $this->guard()->shouldReject($request, $factorActive, $factorRequired)
        );
    }

    public function testRejectedLoginReturnsToNormalLoginFormWithHttpsError(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects(self::once())->method('setToken')->with(null);

        $employee = new class() {
            public $loggedOut = false;
            public function logout(): void { $this->loggedOut = true; }
        };
        $legacy = $this->createMock(LegacyContext::class);
        $legacy->method('getContext')->willReturn((object) ['employee' => $employee]);
        $legacy->expects(self::once())->method('getAdminLink')
            ->with('AdminLogin', false)->willReturn('/admin/index.php?controller=AdminLogin');

        $request = Request::create('http://example.test/admin/login', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $response = (new LoginHttpsGuard($tokenStorage, $legacy))->reject($request);
        self::assertTrue($employee->loggedOut);

        self::assertSame('/admin/index.php?controller=AdminLogin', $response->getTargetUrl());
        self::assertSame(
            [LoginHttpsGuard::ERROR_MESSAGE],
            $request->getSession()->getFlashBag()->peek('error')
        );
    }

    private function guard(): LoginHttpsGuard
    {
        return new LoginHttpsGuard(
            $this->createStub(TokenStorageInterface::class),
            $this->createStub(LegacyContext::class)
        );
    }
}
