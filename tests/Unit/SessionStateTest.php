<?php

declare(strict_types=1);

namespace Mpadmin2fa\Tests\Unit;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Mpadmin2fa\Security\SessionState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SessionStateTest extends TestCase
{
    public function testModernAndLegacyCallersShareVerificationWithoutARequestStack(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $modern = new SessionState($session);
        $legacy = new SessionState($session);
        $modern->resetForLogin(12);
        $modern->markVerified(12);
        self::assertTrue($legacy->isVerified(12));
        self::assertTrue($legacy->hasFreshVerification(12, 300));
        self::assertFalse($legacy->isVerified(13));
        $legacy->clear();
        self::assertFalse($modern->isVerified(12));
    }

    public function testPasswordLoginClearsAllPreviouslyVerifiedPrivileges(): void
    {
        $state = new SessionState(new Session(new MockArraySessionStorage()));
        $state->resetForLogin(12);
        $state->markVerified(12, true);
        $state->authorizeEnrollmentReplacement();
        $state->setReturnTarget('/admin/sensitive');
        $state->resetForLogin(12);
        self::assertFalse($state->isVerified(12));
        self::assertFalse($state->isRecoveryRestricted(12));
        self::assertFalse($state->isEnrollmentReplacementAuthorized(12));
        self::assertNull($state->consumeReturnTarget());
        self::assertNotNull($state->authenticatedAt(12));
    }
}
