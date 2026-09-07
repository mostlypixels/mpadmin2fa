<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Mpadmin2fa\Security\SessionState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class ChallengeScopeTest extends TestCase
{
    public function testOnlyServerSideVerificationSelectsTheStepUpScope(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/challenge');
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);
        $state = new SessionState($session);
        foreach (['0', '1', 'true', '', 'unexpected'] as $flag) {
            $request->query->set('step_up', $flag);
            $state->resetForLogin(42);
            self::assertSame('challenge', $state->challengeScope(42));
            $state->markVerified(42);
            self::assertSame('step_up', $state->challengeScope(42));
            self::assertSame('challenge', $state->challengeScope(43));
            $state->markVerified(42, true);
            self::assertSame('challenge', $state->challengeScope(42));
            $state->clear();
            self::assertSame('challenge', $state->challengeScope(42));
        }
    }
}
